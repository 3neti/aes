<?php

namespace App\Http\Controllers\Election;

use App\Election\Bundle\ResearchBundleWorkbench;
use App\Election\Bundle\ResearchRunService;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class BundleWorkbenchController extends Controller
{
    public function index(Request $request, ResearchBundleWorkbench $workbench, ResearchRunService $runs): Response
    {
        $validated = $request->validate([
            'q' => ['nullable', 'string', 'max:120'],
            'region' => ['nullable', 'string', 'max:80'],
            'province' => ['nullable', 'string', 'max:120'],
            'city_municipality' => ['nullable', 'string', 'max:120'],
            'barangay' => ['nullable', 'string', 'max:120'],
            'status' => ['nullable', Rule::in(['unresolved', 'source_supported_pending_review'])],
            'legislative_district' => ['nullable', 'string', 'max:30'],
            'review_decision' => ['nullable', Rule::in(['undecided', 'approve', 'reject', 'defer'])],
            'page' => ['nullable', 'integer', 'min:1', 'max:10000'],
            'view' => ['nullable', Rule::in(['precincts', 'corrections'])],
        ]);
        $filters = array_filter($validated, fn (mixed $value): bool => $value !== null && $value !== '');
        $overview = $workbench->overview();
        $run = $runs->current();
        $reviews = $run === null ? [] : $runs->precinctDecisions($run['id']);

        return Inertia::render('Election/BundleWorkbench', [
            'overview' => $overview,
            'filters' => $filters,
            'browse' => $overview['available'] && ($filters['view'] ?? '') !== 'corrections'
                ? $workbench->browse($filters, $reviews)
                : null,
            'review' => [
                'reviewer' => (bool) $request->user()?->research_reviewer,
                'run' => $run,
                'can_start' => $runs->canStart(),
            ],
        ]);
    }

    public function show(Request $request, string $precinct, ResearchBundleWorkbench $workbench, ResearchRunService $runs): Response
    {
        $overview = $workbench->overview();
        abort_unless($overview['available'], 404);
        $record = $workbench->precinct($precinct);
        abort_if($record === null, 404);

        return Inertia::render('Election/BundlePrecinct', [
            'overview' => $overview,
            'precinct' => $record,
            'review' => [
                'reviewer' => (bool) $request->user()?->research_reviewer,
                'run' => $runs->current(),
            ],
        ]);
    }
}
