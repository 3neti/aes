<?php

namespace App\Election\Printing;

use App\Election\Core\ActivityJournal;
use App\Election\Support\ElectionStorage;
use App\Election\Support\SimplePdf;

final class FileBallotPrinter implements BallotPrinter
{
    public function __construct(
        private readonly ElectionStorage $storage,
        private readonly ActivityJournal $journal,
        private readonly SimplePdf $pdf,
    ) {}

    public function print(array $payload): array
    {
        $ballotId = $payload['ballot_id'];
        $contents = "OFFICIAL SIMULATION BALLOT\n";
        $contents .= "Election: {$payload['election_id']}\n";
        $contents .= "Precinct: {$payload['precinct_id']}\n";
        $contents .= "Ballot: {$ballotId}\n";
        $contents .= "Payload Hash: {$payload['payload_hash']}\n\n";
        $contents .= "QR Artifact: {$payload['qr_artifact_path']}\n\n";

        foreach ($payload['selections'] as $contest => $candidateIds) {
            $contents .= strtoupper((string) $contest).': '.implode(', ', $candidateIds)."\n";
        }

        $contents .= "\nQR Payload:\n{$payload['qr_payload']}\n";

        $artifactPath = $this->storage->writeText("ballots/{$ballotId}.txt", $contents);
        $pdfPath = $this->storage->writeText("ballots/{$ballotId}.pdf", $this->pdf->render('Official Simulation Ballot', [
            "Election: {$payload['election_id']}",
            "Precinct: {$payload['precinct_id']}",
            "Ballot: {$ballotId}",
            "Payload Hash: {$payload['payload_hash']}",
            "QR Artifact: {$payload['qr_artifact_path']}",
            'Selections:',
            ...collect($payload['selections'])
                ->map(fn (array $candidateIds, string $contest): string => strtoupper($contest).': '.implode(', ', $candidateIds))
                ->values()
                ->all(),
        ]));
        $job = [
            'schema_version' => 'print-job-1',
            'ballot_id' => $ballotId,
            'payload_hash' => $payload['payload_hash'],
            'printer' => 'file',
            'status' => 'printed',
            'artifact_path' => $artifactPath,
            'pdf_artifact_path' => $pdfPath,
        ];

        $this->storage->writeJson("print-jobs/{$ballotId}.json", $job);
        $this->journal->record('ballot.printed', $job);

        return $job;
    }
}
