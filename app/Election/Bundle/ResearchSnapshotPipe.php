<?php

namespace App\Election\Bundle;

use Closure;
use LBHurtado\ElectionCeremonies\Ceremony\CeremonyContext;
use LBHurtado\ElectionCeremonies\Contracts\CeremonyPipe;
use RuntimeException;

final readonly class ResearchSnapshotPipe implements CeremonyPipe
{
    public function __construct(private ResearchBundleWorkbench $workbench) {}

    public function handle(CeremonyContext $context, Closure $next): CeremonyContext
    {
        $overview = $this->workbench->overview();

        if (! $overview['available'] || $overview['bundle']['archive_sha256'] !== $context->request->bundleHash) {
            throw new RuntimeException('The research source snapshot is unavailable or changed.');
        }

        $context->addEvidence('source_snapshot', [
            'bundle_id' => $overview['bundle']['id'],
            'archive_sha256' => $overview['bundle']['archive_sha256'],
            'pop_sha256' => $overview['bundle']['pop_sha256'],
        ]);

        return $next($context);
    }
}
