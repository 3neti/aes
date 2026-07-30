<?php

namespace App\Election\Printing;

use App\Election\Core\ActivityJournal;
use App\Election\Core\BallotConfigurationLabels;
use App\Election\Support\ElectionStorage;
use App\Election\Voting\PaperBallotLedger;

final class FileBallotPrinter implements BallotPrinter
{
    public function __construct(
        private readonly ElectionStorage $storage,
        private readonly ActivityJournal $journal,
        private readonly PrintFormArtifactService $forms,
        private readonly PrintFormProfileResolver $profiles,
        private readonly BallotConfigurationLabels $labels,
        private readonly PaperBallotLedger $paperBallots,
    ) {}

    public function print(array $payload, ?PrintFormProfile $profile = null): array
    {
        $profile ??= $this->profiles->default();
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
        $configuration = $this->storage->readJson('runtime/active-precinct.json');
        $forms = $this->forms->writeBallot($payload, $configuration);
        $pdfPath = $this->storage->writeText("ballots/{$ballotId}.pdf", file_get_contents($forms[PrintFormProfile::A4->value]['artifact_path']) ?: '');
        $selectedForm = $forms[$profile->value];
        $job = [
            'schema_version' => 'print-job-1',
            'ballot_id' => $ballotId,
            'payload_hash' => $payload['payload_hash'],
            'paper_ballot_serial' => $payload['paper_ballot_serial'] ?? null,
            'printer' => 'file',
            'status' => 'printed',
            'artifact_path' => $artifactPath,
            'pdf_artifact_path' => $pdfPath,
            'print_form_profile' => $profile->value,
            'print_form_label' => $profile->label(),
            'selected_pdf_artifact_path' => $selectedForm['artifact_path'],
            'form_artifacts' => $forms,
        ];

        $this->storage->writeJson("print-jobs/{$ballotId}.json", $job);
        $this->paperBallots->recordPrinted($payload['payload_hash']);
        $this->journal->record('ballot.printed', $job);

        return $job;
    }
}
