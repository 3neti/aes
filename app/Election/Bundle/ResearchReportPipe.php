<?php

namespace App\Election\Bundle;

use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\LazyCollection;
use LBHurtado\ElectionCeremonies\Ceremony\CeremonyContext;
use LBHurtado\ElectionCeremonies\Contracts\CeremonyPipe;
use RuntimeException;

final readonly class ResearchReportPipe implements CeremonyPipe
{
    public function __construct(
        private ResearchBundleWorkbench $workbench,
        private DatabaseResearchCeremonyStore $store,
    ) {}

    public function handle(CeremonyContext $context, Closure $next): CeremonyContext
    {
        $overview = $this->workbench->overview();

        if (! $overview['available'] || $overview['bundle']['archive_sha256'] !== $context->request->bundleHash) {
            throw new RuntimeException('Research bundle changed during review.');
        }

        $this->store->verifyJournal($context->request->runId);
        $context->addEvidence('review_report', $this->summary(
            $context->request->runId,
            $context->request->bundleHash,
            $overview['report'],
        ));

        return $next($context);
    }

    /** @param array<string, mixed> $coverage
     * @return array<string, mixed>
     */
    public function summary(string $runId, string $bundleHash, array $coverage): array
    {
        $hash = hash_init('sha256');
        $count = 0;

        foreach ($this->decisions($runId) as $decision) {
            hash_update($hash, $this->line($decision));
            $count++;
        }

        return [
            'schema_version' => 'research-review-1',
            'input_bundle_sha256' => $bundleHash,
            'pop_sha256' => $this->workbench->overview()['bundle']['pop_sha256'],
            'decision_event_count' => $count,
            'decision_ledger_sha256' => hash_final($hash),
            'unresolved_precinct_count' => $coverage['unresolved_precinct_count'],
            'unreviewed_correction_count' => $coverage['unreviewed_correction_count'],
            'national_coverage_ready' => false,
        ];
    }

    public function writeLedger(string $runId, string $destination): string
    {
        $temporary = $destination.'.'.bin2hex(random_bytes(6)).'.tmp';
        $handle = fopen($temporary, 'wb');

        if ($handle === false) {
            throw new RuntimeException('Cannot create research decision export.');
        }

        try {
            foreach ($this->decisions($runId) as $decision) {
                if (fwrite($handle, $this->line($decision)) === false) {
                    throw new RuntimeException('Cannot write research decision export.');
                }
            }
        } finally {
            fclose($handle);
        }

        if (! rename($temporary, $destination)) {
            @unlink($temporary);
            throw new RuntimeException('Cannot publish research decision export.');
        }

        return hash_file('sha256', $destination);
    }

    /** @return LazyCollection<int, object> */
    private function decisions(string $runId): LazyCollection
    {
        return DB::table('election_research_decisions')->where('run_id', $runId)
            ->orderBy('id')->cursor();
    }

    private function line(object $decision): string
    {
        return json_encode([
            'event_id' => $decision->event_id,
            'case_type' => $decision->case_type,
            'case_id' => $decision->case_id,
            'decision' => $decision->decision,
            'reviewer_id' => $decision->reviewer_id,
            'rationale' => $decision->rationale,
            'evidence' => json_decode($decision->evidence, true, flags: JSON_THROW_ON_ERROR),
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)."\n";
    }
}
