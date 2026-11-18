<?php

namespace App\Election\Bundle;

use Closure;
use LBHurtado\ElectionCeremonies\Ceremony\CeremonyContext;
use LBHurtado\ElectionCeremonies\Contracts\CeremonyPipe;
use RuntimeException;

final readonly class ResearchDecisionPipe implements CeremonyPipe
{
    public function __construct(private ResearchBundleWorkbench $workbench) {}

    public function handle(CeremonyContext $context, Closure $next): CeremonyContext
    {
        $input = $context->request->input;
        $caseType = (string) ($input['case_type'] ?? '');
        $caseId = (string) ($input['case_id'] ?? '');
        $decision = (string) ($input['decision'] ?? '');
        $rationale = trim((string) ($input['rationale'] ?? ''));

        if (! in_array($caseType, ['precinct', 'correction'], true)
            || ! in_array($decision, ['approve', 'reject', 'defer'], true)
            || $caseId === '' || strlen($rationale) < 10) {
            throw new RuntimeException('Research decision is incomplete.');
        }

        $overview = $this->workbench->overview();

        if (! $overview['available'] || $overview['bundle']['archive_sha256'] !== $context->request->bundleHash) {
            throw new RuntimeException('Research bundle changed during review.');
        }

        if ($caseType === 'precinct') {
            $case = $this->workbench->precinct($caseId);

            if ($case === null || ($decision === 'approve' && $case['status'] !== 'source_supported_pending_review')) {
                throw new RuntimeException('Precinct assignment cannot be approved from current evidence.');
            }

            $evidence = [
                'pop_row' => $case['source_row'],
                'legislative_district' => $case['legislative_district'],
                'evidence_id' => $case['evidence_id'],
                'source_url' => $case['source_url'],
                'status' => $case['status'],
            ];
        } else {
            $case = collect($overview['corrections'])->firstWhere('id', $caseId);

            if ($case === null || $decision === 'approve') {
                throw new RuntimeException('Correction notices require a reviewed before-and-after proposal before approval.');
            }

            $evidence = [
                'source_reference' => $case['source_reference'],
                'source_url' => $case['source_url'],
                'review_state' => $case['review_state'],
                'applied' => false,
            ];
        }

        $context->addEvidence('review_decision', [
            'case_type' => $caseType,
            'case_id' => $caseId,
            'decision' => $decision,
            'rationale' => $rationale,
            'source' => $evidence,
        ]);

        return $next($context);
    }
}
