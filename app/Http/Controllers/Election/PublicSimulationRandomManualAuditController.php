<?php

namespace App\Http\Controllers\Election;

use App\Election\Audit\RandomManualAuditService;
use App\Election\PublicSimulation\PublicRandomManualAuditPublication;
use App\Election\PublicSimulation\PublicSimulationService;
use App\Election\Scanning\BallotScanner;
use App\Election\Support\ElectionStorage;
use App\Http\Controllers\Controller;
use App\Models\SimulationPrecinct;
use App\Models\SimulationRound;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Throwable;

final class PublicSimulationRandomManualAuditController extends Controller
{
    public function show(SimulationRound $round, SimulationPrecinct $precinct, PublicSimulationService $simulations, RandomManualAuditService $audit, PublicRandomManualAuditPublication $publication): Response
    {
        $this->scope($round, $precinct, $simulations);
        $this->ensureAvailable($precinct);
        $summary = $audit->summary();

        return Inertia::render('Election/PublicSimulationRandomManualAudit', [
            'precinct' => [
                'code' => $precinct->code,
                'label' => $precinct->label,
                'status' => $precinct->status,
            ],
            'audit' => [
                'sample' => collect($summary['sample_selection']['selected_ballots'] ?? [])
                    ->map(fn (array $ballot): array => [
                        'payload_hash' => $ballot['payload_hash'],
                        'paper_ballot_serial' => $ballot['paper_ballot_serial'],
                    ])
                    ->values()
                    ->all(),
                'sample_hash' => $summary['sample_selection']['sample_hash'] ?? null,
                'source_record_count' => $summary['sample_selection']['source_record_count'] ?? 0,
                'approved_ballots' => $summary['approved_ballots'],
                'discrepancy_ballots' => $summary['discrepancy_ballots'],
                'pending' => $summary['pending_proposal'] === null ? null : [
                    'payload_hash' => $summary['pending_proposal']['payload_hash'],
                    'paper_ballot_serial' => $summary['pending_proposal']['paper_ballot_serial'],
                    'selections' => $summary['pending_proposal']['selections'],
                ],
                'reconciliation' => $summary['reconciliation_report'] === [] ? null : [
                    'complete' => $summary['reconciliation_report']['complete'],
                    'passed' => $summary['reconciliation_report']['passed'],
                    'verified_ballots' => $summary['reconciliation_report']['verified_ballots'],
                    'discrepancy_ballots' => $summary['reconciliation_report']['discrepancy_ballots'],
                    'pending_ballots' => $summary['reconciliation_report']['pending_ballots'],
                ],
                'evidencePackAvailable' => $summary['evidence_pack'] !== [],
                'watcherPublicationAvailable' => $publication->summary() !== [],
            ],
            'feedback' => session('public_simulation.audit_feedback'),
            'actions' => [
                'select' => route('election.public-simulation.audit.select', [$round, $precinct]),
                'propose' => route('election.public-simulation.audit.propose', [$round, $precinct]),
                'approve' => route('election.public-simulation.audit.approve', [$round, $precinct]),
                'discrepancy' => route('election.public-simulation.audit.discrepancy', [$round, $precinct]),
                'reconcile' => route('election.public-simulation.audit.reconcile', [$round, $precinct]),
                'evidencePack' => route('election.public-simulation.audit.evidence-pack', [$round, $precinct]),
                'publish' => route('election.public-simulation.audit.publish', [$round, $precinct]),
                'download' => route('election.public-simulation.audit.download', [$round, $precinct]),
            ],
        ]);
    }

    public function select(Request $request, SimulationRound $round, SimulationPrecinct $precinct, PublicSimulationService $simulations, RandomManualAuditService $audit): RedirectResponse
    {
        $this->authorizeOfficer($request, $round, $precinct, $simulations);
        $audit->selectSample();

        return $this->redirect($round, $precinct, 'The deterministic paper-ballot sample has been sealed for audit.');
    }

    public function propose(Request $request, SimulationRound $round, SimulationPrecinct $precinct, PublicSimulationService $simulations, BallotScanner $scanner, RandomManualAuditService $audit): RedirectResponse
    {
        $validated = $this->authorizeOfficer($request, $round, $precinct, $simulations, ['payload' => ['required', 'string']]);

        try {
            $scan = $scanner->scan($validated['payload']);
        } catch (Throwable $exception) {
            throw ValidationException::withMessages(['payload' => $exception->getMessage()]);
        }

        $audit->propose($scan);

        return $this->redirect($round, $precinct, 'The paper QR code is awaiting two distinct Election Board approvals.');
    }

    public function approve(Request $request, SimulationRound $round, SimulationPrecinct $precinct, PublicSimulationService $simulations, RandomManualAuditService $audit): RedirectResponse
    {
        $validated = $this->authorizeOfficer($request, $round, $precinct, $simulations, [
            'payload_hash' => ['required', 'string', 'size:64'],
            'first_officer_code' => ['required', 'string'],
            'first_officer_pin' => ['required', 'digits:6'],
            'second_officer_code' => ['required', 'string'],
            'second_officer_pin' => ['required', 'digits:6'],
        ]);
        $audit->approve(
            $validated['payload_hash'],
            $validated['first_officer_code'],
            $validated['first_officer_pin'],
            $validated['second_officer_code'],
            $validated['second_officer_pin'],
        );

        return $this->redirect($round, $precinct, 'The sampled paper ballot comparison has been dual-approved.');
    }

    public function discrepancy(Request $request, SimulationRound $round, SimulationPrecinct $precinct, PublicSimulationService $simulations, RandomManualAuditService $audit): RedirectResponse
    {
        $validated = $this->authorizeOfficer($request, $round, $precinct, $simulations, [
            'payload_hash' => ['required', 'string', 'size:64'],
            'reason' => ['required', 'string', 'max:1000'],
            'first_officer_code' => ['required', 'string'],
            'first_officer_pin' => ['required', 'digits:6'],
            'second_officer_code' => ['required', 'string'],
            'second_officer_pin' => ['required', 'digits:6'],
        ]);
        $audit->recordDiscrepancy(
            $validated['payload_hash'],
            $validated['reason'],
            $validated['first_officer_code'],
            $validated['first_officer_pin'],
            $validated['second_officer_code'],
            $validated['second_officer_pin'],
        );

        return $this->redirect($round, $precinct, 'The paper discrepancy has been dual-confirmed and recorded as an audit finding.');
    }

    public function reconcile(Request $request, SimulationRound $round, SimulationPrecinct $precinct, PublicSimulationService $simulations, RandomManualAuditService $audit): RedirectResponse
    {
        $this->authorizeOfficer($request, $round, $precinct, $simulations);
        $audit->generateReconciliationReport();

        return $this->redirect($round, $precinct, 'The Random Manual Audit reconciliation report has been generated.');
    }

    public function evidencePack(Request $request, SimulationRound $round, SimulationPrecinct $precinct, PublicSimulationService $simulations, RandomManualAuditService $audit): RedirectResponse
    {
        $this->authorizeOfficer($request, $round, $precinct, $simulations);
        $audit->buildEvidencePack();

        return $this->redirect($round, $precinct, 'The Random Manual Audit evidence pack is ready for download.');
    }

    public function publish(Request $request, SimulationRound $round, SimulationPrecinct $precinct, PublicSimulationService $simulations, PublicRandomManualAuditPublication $publication): RedirectResponse
    {
        $this->authorizeOfficer($request, $round, $precinct, $simulations);

        try {
            $publication->publish();
        } catch (RuntimeException $exception) {
            throw ValidationException::withMessages(['audit_publication' => $exception->getMessage()]);
        }

        return $this->redirect($round, $precinct, 'The redacted Random Manual Audit summary is now available to poll watchers.');
    }

    public function download(SimulationRound $round, SimulationPrecinct $precinct, PublicSimulationService $simulations, ElectionStorage $storage): BinaryFileResponse
    {
        $this->scope($round, $precinct, $simulations);
        $this->ensureAvailable($precinct);
        $path = $storage->path('rma/evidence-pack.pdf');
        abort_unless(is_file($path), 404);

        return response()->download($path, "{$precinct->code}-random-manual-audit.pdf");
    }

    /**
     * @param  array<string, array<int, string>>  $rules
     * @return array<string, string>
     */
    private function authorizeOfficer(Request $request, SimulationRound $round, SimulationPrecinct $precinct, PublicSimulationService $simulations, array $rules = []): array
    {
        $validated = $request->validate([
            'officer_code' => ['required', 'string', 'max:32'],
            'officer_pin' => ['required', 'digits:6'],
            ...$rules,
        ]);
        $this->scope($round, $precinct, $simulations);
        $this->ensureAvailable($precinct);

        try {
            $simulations->verifyOfficer($precinct, $validated['officer_code'], $validated['officer_pin']);
        } catch (RuntimeException $exception) {
            throw ValidationException::withMessages(['officer_pin' => $exception->getMessage()]);
        }

        return $validated;
    }

    private function scope(SimulationRound $round, SimulationPrecinct $precinct, PublicSimulationService $simulations): void
    {
        abort_unless($precinct->simulation_round_id === $round->id, 404);
        $simulations->applyScope($precinct);
    }

    private function ensureAvailable(SimulationPrecinct $precinct): void
    {
        abort_unless(in_array($precinct->status, ['results_ready', 'published'], true), 409);
    }

    private function redirect(SimulationRound $round, SimulationPrecinct $precinct, string $message): RedirectResponse
    {
        return to_route('election.public-simulation.audit.show', [$round, $precinct])
            ->with('public_simulation.audit_feedback', $message);
    }
}
