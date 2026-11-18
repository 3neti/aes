<?php

namespace App\Election\PublicSimulation;

use App\Election\Core\ActivityJournal;
use App\Election\Printing\BallotPrinter;
use App\Election\Support\ElectionStorage;
use App\Election\Voting\PrivateBallotRelease;
use App\Election\Voting\SealedBallotBox;
use App\Models\SimulationPrecinct;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Crypt;
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
            'pdf_available' => isset($record['release_id']) && $this->releases->printedBallotPdfPath($record['release_id']) !== null,
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

        $this->storage->writeJson(self::Path, [
            'encrypted_code' => Crypt::encryptString($code),
            'attempts' => 0,
            'status' => 'pending',
        ]);
        $this->attempt();
    }

    public function attempt(): void
    {
        $record = $this->storage->readJson(self::Path);
        if ($record === []) {
            throw new RuntimeException('There is no pending VVPAT to reprint.');
        }

        try {
            if (! isset($record['release_id'])) {
                $release = $this->releases->redeem(Crypt::decryptString($record['encrypted_code']));
                $record['release_id'] = $release['release_id'];
            }
            $record['attempts']++;
            $record['status'] = 'printing';
            $record['message'] = 'Check the printer before attempting another print.';
            $this->storage->writeJson(self::Path, $record);
            $job = $this->releases->printForRoleDemo((string) $record['release_id'], $this->printer, $record['attempts'] > 1);
            $record['status'] = 'awaiting_confirmation';
            $record['message'] = ($job['status'] ?? null) === 'submitted'
                ? 'VVPAT sent to the printer. Check the printed VVPAT.'
                : 'VVPAT file prepared. Open the PDF and print it, then check the VVPAT.';
        } catch (Throwable $exception) {
            $record['status'] = 'failed';
            $record['message'] = 'The print attempt did not complete. Check the printer, clear any jam, and try again.';
            report($exception);
        }
        $this->storage->writeJson(self::Path, $record);
        $this->journal->record('role_demo.vvpat_print_attempt', ['attempt' => $record['attempts'], 'status' => $record['status']]);
    }

    public function confirm(SimulationPrecinct $precinct): array
    {
        $record = $this->storage->readJson(self::Path);
        if (($record['status'] ?? null) !== 'awaiting_confirmation' || ! isset($record['release_id'])) {
            throw new RuntimeException('A completed print attempt is required before confirming.');
        }
        $releaseId = (string) $record['release_id'];
        $pdf = $this->releases->printedBallotPdfPath($releaseId);
        $deposit = $this->ballotBox->deposit($releaseId);
        $this->files->delete($this->storage->path(self::Path));
        $this->journal->record('role_demo.vvpat_print_confirmed', ['release_id' => $releaseId, 'attempts' => $record['attempts']]);
        $this->forms->generate($precinct, 'role-demo-print-confirmed');

        return ['deposit' => $deposit, 'printed_pdf' => $pdf];
    }
}
