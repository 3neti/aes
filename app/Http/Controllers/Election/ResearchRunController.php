<?php

namespace App\Http\Controllers\Election;

use App\Election\Bundle\ResearchBundleWorkbench;
use App\Election\Bundle\ResearchRunService;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;

class ResearchRunController extends Controller
{
    public function store(Request $request, ResearchRunService $runs): RedirectResponse
    {
        $this->reviewer($request);

        try {
            $runId = $runs->start($request->user());
        } catch (RuntimeException $exception) {
            throw ValidationException::withMessages(['research' => $exception->getMessage()]);
        }

        return to_route('election.bundle.review.show', $runId);
    }

    public function show(Request $request, string $run, ResearchRunService $runs, ResearchBundleWorkbench $workbench): Response
    {
        $this->reviewer($request);
        try {
            $current = $runs->show($run);
        } catch (RuntimeException) {
            abort(404);
        }

        $selection = $request->validate([
            'case_type' => ['nullable', Rule::in(['precinct', 'correction'])],
            'case_id' => ['nullable', 'string', 'max:100'],
        ]);
        $caseType = $selection['case_type'] ?? null;
        $caseId = $selection['case_id'] ?? null;
        $case = null;

        if ($caseType === 'precinct' && is_string($caseId)) {
            $case = $workbench->precinct($caseId);
        } elseif ($caseType === 'correction' && is_string($caseId)) {
            $overview = $workbench->overview();
            $case = collect($overview['corrections'] ?? [])->firstWhere('id', $caseId);
        }

        return Inertia::render('Election/ResearchRun', [
            'run' => $current,
            'decisions' => $runs->decisions($run),
            'caseType' => $caseType,
            'caseId' => $caseId,
            'caseRecord' => $case,
            'idempotencyKey' => (string) Str::uuid(),
        ]);
    }

    public function decide(Request $request, string $run, ResearchRunService $runs): RedirectResponse
    {
        $this->reviewer($request);
        $input = $request->validate([
            'case_type' => ['required', Rule::in(['precinct', 'correction'])],
            'case_id' => ['required', 'string', 'max:100'],
            'decision' => ['required', Rule::in(['approve', 'reject', 'defer'])],
            'rationale' => ['required', 'string', 'min:10', 'max:3000'],
            'idempotency_key' => ['required', 'uuid'],
        ]);
        $idempotencyKey = $input['idempotency_key'];
        unset($input['idempotency_key']);

        try {
            $runs->run($run, 'record_decision', $request->user(), $input, $idempotencyKey);
        } catch (RuntimeException $exception) {
            throw ValidationException::withMessages(['research' => $exception->getMessage()]);
        }

        return to_route('election.bundle.review.show', ['run' => $run, 'case_type' => $input['case_type'], 'case_id' => $input['case_id']]);
    }

    public function close(Request $request, string $run, ResearchRunService $runs): RedirectResponse
    {
        $this->reviewer($request);
        $input = $request->validate(['idempotency_key' => ['required', 'uuid']]);

        try {
            $runs->run($run, 'close_review', $request->user(), [], $input['idempotency_key']);
        } catch (RuntimeException $exception) {
            throw ValidationException::withMessages(['research' => $exception->getMessage()]);
        }

        return to_route('election.bundle.review.show', $run);
    }

    private function reviewer(Request $request): void
    {
        abort_unless($request->user()?->research_reviewer, 403);
    }
}
