<?php

namespace App\Http\Controllers\Election;

use App\Election\Core\ElectionSnapshot;
use App\Election\Lifecycle\Lifecycle;
use App\Election\Lifecycle\LifecycleState;
use App\Election\Support\ElectionStorage;
use App\Election\Voting\SealedBallotBox;
use App\Http\Controllers\Controller;
use Inertia\Inertia;
use Inertia\Response;

final class WatcherController extends Controller
{
    public function __invoke(
        ElectionSnapshot $snapshot,
        LifecycleState $lifecycle,
        ElectionStorage $storage,
        SealedBallotBox $ballotBox,
    ): Response {
        $resultsAvailable = in_array($lifecycle->current(), [
            Lifecycle::ElectionReturn,
            Lifecycle::Transmission,
            Lifecycle::FinalBackup,
            Lifecycle::Custody,
            Lifecycle::ClosePrecinct,
            Lifecycle::Audit,
        ], true);

        return Inertia::render('Election/Watcher', [
            'snapshot' => $snapshot->get(),
            'operations' => $ballotBox->operationalSummary(),
            'resultsAvailable' => $resultsAvailable,
            'tally' => $resultsAvailable ? $storage->readJson('runtime/tally.json') : [],
            'electionReturn' => $resultsAvailable ? $storage->readJson('returns/election-return.json') : [],
        ]);
    }
}
