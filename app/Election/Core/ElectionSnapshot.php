<?php

namespace App\Election\Core;

use App\Election\Lifecycle\Lifecycle;
use App\Election\Lifecycle\LifecycleState;
use App\Election\Support\ElectionStorage;
use App\Election\Tabulation\DeviceTabulationLedger;
use App\Election\Tabulation\TabulationProfileResolver;
use App\Election\Voting\PaperBallotLedger;

final class ElectionSnapshot
{
    public function __construct(
        private readonly LifecycleState $lifecycle,
        private readonly DomainDictionary $dictionary,
        private readonly ActivityJournal $journal,
        private readonly ElectionStorage $storage,
        private readonly PaperBallotLedger $paperBallots,
        private readonly TabulationProfileResolver $tabulation,
        private readonly DeviceTabulationLedger $deviceLedger,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function get(): array
    {
        $stage = $this->lifecycle->current();
        $configuration = $this->storage->readJson('runtime/active-precinct.json');

        return [
            'appName' => $this->dictionary->appName(),
            'operatorLabel' => $this->dictionary->operatorLabel(),
            'stage' => $stage,
            'stageLabel' => $this->dictionary->stageLabel($stage),
            'ceremony' => $this->dictionary->ceremonyLabel($stage),
            'nextAction' => $this->dictionary->actionLabel($stage),
            'nextStage' => Lifecycle::next($stage),
            'workflow' => $this->dictionary->workflow(),
            'configuration' => $configuration,
            'tabulationProfile' => $this->tabulation->describe(),
            'journal' => $this->journal->latest(),
            'counts' => [
                'accepted' => count($this->storage->files('counting/accepted')),
                'rejected' => count($this->storage->files('counting/rejected')),
                'printJobs' => count($this->storage->files('print-jobs')),
                'ballots' => count($this->storage->files('ballots')),
                'attestations' => count($this->storage->files('attestations')),
                'transmissions' => count($this->storage->files('transmission')),
                'custody_records' => count($this->storage->files('custody')),
                'device_tabulation_records' => $this->deviceLedger->summary()['recorded_ballots'],
            ],
            'paperBallots' => $this->paperBallots->summary(),
        ];
    }
}
