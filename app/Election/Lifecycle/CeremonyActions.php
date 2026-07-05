<?php

namespace App\Election\Lifecycle;

use App\Election\Core\ActivityJournal;

final class CeremonyActions
{
    public function __construct(
        private readonly LifecycleState $lifecycle,
        private readonly ActivityJournal $journal,
    ) {}

    public function openPrecinct(string $officer = 'simulation officer'): void
    {
        $this->lifecycle->advanceTo(Lifecycle::OpenPrecinct);
        $this->journal->record('precinct.opened', ['officer' => $officer]);
    }

    public function openPolls(string $officer = 'simulation officer'): void
    {
        if ($this->lifecycle->current() === Lifecycle::OpenPrecinct) {
            $this->lifecycle->advanceTo(Lifecycle::OpenPolls);
        }

        $this->lifecycle->advanceTo(Lifecycle::Voting);
        $this->journal->record('polls.opened', ['officer' => $officer]);
    }

    public function closePolls(string $officer = 'simulation officer'): void
    {
        $this->lifecycle->advanceTo(Lifecycle::ClosePolls);
        $this->journal->record('polls.closed', ['officer' => $officer]);
    }

    public function startCounting(): void
    {
        $this->lifecycle->advanceTo(Lifecycle::Counting);
        $this->journal->record('counting.started');
    }

    public function moveToReturns(): void
    {
        $this->lifecycle->advanceTo(Lifecycle::ElectionReturn);
    }

    public function closePrecinct(string $officer = 'simulation officer'): void
    {
        $this->lifecycle->advanceTo(Lifecycle::ClosePrecinct);
        $this->journal->record('precinct.closed', ['officer' => $officer]);
    }
}
