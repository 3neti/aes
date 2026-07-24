<?php

namespace App\Election\Printing;

use App\Election\Core\ActivityJournal;
use App\Election\Core\BallotConfigurationLabels;
use App\Election\Printing\Documents\OfficialBallotPdf;
use App\Election\Support\ElectionStorage;
use App\Election\Voting\PaperBallotLedger;

final class FileBallotPrinter implements BallotPrinter
{
    public function __construct(
        private readonly ElectionStorage $storage,
        private readonly ActivityJournal $journal,
        private readonly OfficialBallotPdf $pdf,
        private readonly BallotConfigurationLabels $labels,
        private readonly PaperBallotLedger $paperBallots,
    ) {}

    public function print(array $payload): array
    {
        $ballotId = $payload['ballot_id'];
        $contents = "OFFICIAL SIMULATION BALLOT\n";
        $contents .= "Election: {$payload['election_id']}\n";
        $contents .= "Precinct: {$payload['precinct_id']}\n";
        $contents .= "Ballot: {$ballotId}\n";
        $contents .= 'Paper Ballot Serial: '.($payload['paper_ballot_serial'] ?? 'CERTIFICATION/UNNUMBERED')."\n";
        $contents .= "Payload Hash: {$payload['payload_hash']}\n\n";
        $contents .= "QR Artifact: {$payload['qr_artifact_path']}\n\n";

        foreach ($this->labels->selectionLines($payload['selections']) as $line) {
            $contents .= $line."\n";
        }

        $contents .= "\nQR Payload:\n{$payload['qr_payload']}\n";

        $artifactPath = $this->storage->writeText("ballots/{$ballotId}.txt", $contents);
        $pdfPath = $this->storage->writeText(
            "ballots/{$ballotId}.pdf",
            $this->pdf->render(
                $payload,
                $this->storage->readJson('runtime/active-precinct.json'),
            ),
        );
        $job = [
            'schema_version' => 'print-job-1',
            'ballot_id' => $ballotId,
            'payload_hash' => $payload['payload_hash'],
            'paper_ballot_serial' => $payload['paper_ballot_serial'] ?? null,
            'printer' => 'file',
            'status' => 'printed',
            'artifact_path' => $artifactPath,
            'pdf_artifact_path' => $pdfPath,
        ];

        $this->storage->writeJson("print-jobs/{$ballotId}.json", $job);
        $this->paperBallots->recordPrinted($payload['payload_hash']);
        $this->journal->record('ballot.printed', $job);

        return $job;
    }
}
