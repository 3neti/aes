<?php

namespace App\Election\PublicSimulation;

use App\Election\Core\ActivityJournal;
use App\Election\Lifecycle\ElectionRunType;
use App\Election\Printing\BallotPrinter;
use App\Election\Support\ElectionStorage;
use App\Election\Voting\PrivateBallotRelease;
use App\Election\Voting\SealedBallotBox;
use App\Models\SimulationPrecinct;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

final class RoleDemoVvpatPrint
{
    private const Path = 'runtime/role-demo-vvpat-pending.json';

    public function __construct(
        private readonly ElectionStorage $storage,
        private readonly Filesystem $files,
        private readonly PrivateBallotRelease $releases,
        private readonly BallotPrinter $printer,
        private readonly SealedBallotBox $ballotBox,
        private readonly RoleDemoInterimCloseout $forms,
        private readonly ActivityJournal $journal,
    ) {}

    /** @return array<string, mixed>|null */
    public function pending(): ?array
    {
        $record = $this->storage->readJson(self::Path);
        if ($record === []) {
            return null;
        }

        return [
            'code' => Crypt::decryptString($record['encrypted_code']),
            'attempts' => $record['attempts'] ?? 0,
            'status' => $record['status'] ?? 'pending',
            'message' => $record['message'] ?? null,
            'ballot_reference' => isset($record['release_id']) ? ($this->releases->find($record['release_id'])['ballot_id'] ?? null) : null,
            'pdf_available' => isset($record['release_id']) && $this->releases->printedBallotPdfPath($record['release_id']) !== null,
            'reconciliation_token' => $this->reconciliationToken($record),
            'reconciliation_available' => $this->isDemoReconciliationAvailable(),
        ];
    }

    public function pendingPdfPath(): ?string
    {
        $record = $this->storage->readJson(self::Path);

        return isset($record['release_id']) ? $this->releases->printedBallotPdfPath($record['release_id']) : null;
    }

    public function start(string $code): void
    {
        if ($this->pending() !== null) {
            throw new RuntimeException('Resolve the pending VVPAT before starting another print.');
        }
        if (! $this->releases->isReadyForPrint($code)) {
            throw new RuntimeException('The VVPAT is not ready or the control number is unavailable.');
        }

        $release = $this->releases->roleDemoReleaseForCode($code);
        $this->storage->writeJson(self::Path, [
            'encrypted_code' => Crypt::encryptString($code),
            'release_id' => $release['release_id'],
            'attempts' => 0,
            'status' => 'pending',
        ]);
        $this->attempt();
    }

    public function attempt(?string $retryToken = null, ?string $sessionId = null): void
    {
        $record = $this->storage->readJson(self::Path);
        if ($record === []) {
            throw new RuntimeException('There is no pending VVPAT to reprint.');
        }
        if (in_array($record['status'] ?? null, ['printing', 'uncertain', 'failed', 'awaiting_confirmation'], true)) {
            throw new RuntimeException('The previous VVPAT print outcome is unknown. Reconcile the printer and queued jobs before another attempt.');
        }
        if (($record['status'] ?? null) === 'depositing') {
            throw new RuntimeException('The deposit outcome is unresolved. Preserve this VVPAT and contact an operator for reconciliation.');
        }
        if ($retryToken !== null && ($record['status'] ?? null) !== 'ready_to_retry') {
            throw new RuntimeException('This reprint authorization is unavailable or has changed. Ask the officer to reconcile the print again.');
        }
        if (! in_array($record['status'] ?? null, ['pending', 'ready_to_retry'], true)
            || ! is_int($record['attempts'] ?? null) || $record['attempts'] < 0
            || (($record['status'] ?? null) === 'pending' && $record['attempts'] !== 0)
            || (($record['status'] ?? null) === 'ready_to_retry'
                && (! is_string($record['reconciliation_revision'] ?? null) || $record['reconciliation_revision'] === ''))) {
            throw new RuntimeException('The VVPAT print state is inconsistent. Operator investigation is required.');
        }
        if (($record['status'] ?? null) === 'ready_to_retry') {
            $revision = $record['reconciliation_revision'];
            $binding = $record['reconciliation_binding'] ?? null;
            if (! is_string($retryToken) || ! hash_equals($revision, $retryToken)
                || ! is_string($sessionId) || $sessionId === '' || ! is_string($binding)
                || ! hash_equals($binding, $this->retryBinding($record, $sessionId))) {
                throw new RuntimeException('This reprint authorization is unavailable or has changed. Ask the officer to reconcile the print again.');
            }
        }

        $printingStarted = false;
        try {
            $code = Crypt::decryptString($record['encrypted_code']);
            $release = $this->releases->roleDemoReleaseForCode($code);
            if (isset($record['release_id']) && $record['release_id'] !== $release['release_id']) {
                throw new RuntimeException('The pending VVPAT release does not match the control number.');
            }
            if ($release['status'] === 'printed' && (int) ($record['attempts'] ?? 0) === 0) {
                throw new RuntimeException('The VVPAT was already printed. Operator reconciliation is required.');
            }
            $record['release_id'] = $release['release_id'];
            $this->storage->writeJson(self::Path, $record);
            if ($release['status'] === 'pending') {
                $this->releases->redeem($code);
            }
            $record['attempts']++;
            $record['status'] = 'printing';
            $record['message'] = 'Check the printer before attempting another print.';
            $this->storage->writeJson(self::Path, $record);
            $printingStarted = true;
            $job = $this->releases->printForRoleDemo((string) $record['release_id'], $this->printer, $record['attempts'] > 1);
            $record['status'] = 'awaiting_confirmation';
            $record['message'] = ($job['status'] ?? null) === 'submitted'
                ? 'VVPAT sent to the printer. Check the printed VVPAT.'
                : 'VVPAT file prepared. Open the PDF and print it, then check the VVPAT.';
        } catch (Throwable $exception) {
            $record['status'] = $printingStarted ? 'uncertain' : 'failed';
            $record['message'] = 'The print outcome needs operator reconciliation. Check queued and printer-buffered copies before another attempt.';
            report($exception);
        }
        $this->storage->writeJson(self::Path, $record);
        $this->journal->record('role_demo.vvpat_print_attempt', ['attempt' => $record['attempts'], 'status' => $record['status']]);
    }

    public function reconcileForRetry(string $token, string $officerCode, ?string $sessionId = null): string
    {
        if (config('election.voting_lock_driver') !== 'local') {
            throw new RuntimeException('Print reconciliation requires the single-host voting lock.');
        }
        if (! $this->isDemoReconciliationAvailable()) {
            throw new RuntimeException('Print reconciliation is available only for a demo rehearsal or automated test.');
        }
        if (! is_string($sessionId) || $sessionId === '') {
            throw new RuntimeException('An officer session is required for print reconciliation.');
        }

        $record = $this->storage->readJson(self::Path);
        if ($record === [] || ! in_array($record['status'] ?? null, ['printing', 'uncertain', 'failed', 'awaiting_confirmation', 'ready_to_retry'], true)) {
            throw new RuntimeException('There is no unresolved print attempt to reconcile.');
        }
        if (! hash_equals($this->reconciliationToken($record), $token)) {
            throw new RuntimeException('The pending print changed. Refresh the page and review it again.');
        }

        $release = $this->releases->roleDemoReleaseForCode(Crypt::decryptString($record['encrypted_code']));
        if (isset($record['release_id']) && $record['release_id'] !== $release['release_id']) {
            throw new RuntimeException('The pending VVPAT release does not match the control number.');
        }
        if ($release['status'] === 'printed' && (int) ($record['attempts'] ?? 0) === 0) {
            throw new RuntimeException('The print history is inconsistent. Operator investigation is required.');
        }

        $this->journal->record('role_demo.vvpat_retry_authorized', [
            'release_id' => $release['release_id'],
            'attempt' => (int) ($record['attempts'] ?? 0),
            'previous_status' => $record['status'],
            'officer_code_hash' => hash_hmac('sha256', $officerCode, (string) config('app.key')),
            'no_usable_paper' => true,
            'queue_and_buffer_cleared' => true,
        ]);
        $record['release_id'] = $release['release_id'];
        $record['reconciliation_revision'] = (string) Str::uuid();
        $record['reconciliation_binding'] = $this->retryBinding($record, $sessionId);
        $record['status'] = 'ready_to_retry';
        $record['message'] = 'The previous attempt was reconciled. Select reprint to submit a new attempt.';
        $this->storage->writeJson(self::Path, $record);

        return $record['reconciliation_revision'];
    }

    /** @param array<string, mixed> $record */
    private function retryBinding(array $record, string $sessionId): string
    {
        return hash_hmac('sha256', json_encode([
            'session_id' => $sessionId,
            'run_path' => $this->storage->currentRun()['run_path'] ?? null,
            'release_id' => $record['release_id'] ?? null,
            'attempts' => $record['attempts'] ?? null,
            'revision' => $record['reconciliation_revision'] ?? null,
        ], JSON_THROW_ON_ERROR), (string) config('app.key'));
    }

    /** @param array<string, mixed> $record */
    private function reconciliationToken(array $record): string
    {
        return hash_hmac('sha256', json_encode($record, JSON_THROW_ON_ERROR), (string) config('app.key'));
    }

    private function isDemoReconciliationAvailable(): bool
    {
        $directory = '/'.trim((string) config('election.storage.directory'), '/').'/';
        $runType = $this->storage->currentRun()['run_type'] ?? null;

        return str_contains($directory, '/public-simulations/')
            && in_array($runType, [ElectionRunType::Rehearsal->value, ElectionRunType::AutomatedTest->value], true);
    }

    public function confirm(SimulationPrecinct $precinct): array
    {
        $record = $this->storage->readJson(self::Path);
        if (($record['status'] ?? null) !== 'awaiting_confirmation' || ! isset($record['release_id'])) {
            throw new RuntimeException('A completed print attempt is required before confirming.');
        }
        $releaseId = (string) $record['release_id'];
        $pdf = $this->releases->printedBallotPdfPath($releaseId);
        $record['status'] = 'depositing';
        $record['message'] = 'The deposit outcome is unresolved. Preserve this VVPAT and contact an operator for reconciliation.';
        $this->storage->writeJson(self::Path, $record);
        $deposit = $this->ballotBox->deposit($releaseId);
        $this->journal->record('role_demo.vvpat_print_confirmed', ['release_id' => $releaseId, 'attempts' => $record['attempts']]);
        $this->forms->generate($precinct, 'role-demo-print-confirmed');
        $this->files->delete($this->storage->path(self::Path));

        return ['deposit' => $deposit, 'printed_pdf' => $pdf];
    }
}
