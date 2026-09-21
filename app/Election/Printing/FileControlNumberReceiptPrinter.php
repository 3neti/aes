<?php

namespace App\Election\Printing;

use App\Election\Core\ActivityJournal;
use App\Election\Printing\Documents\ControlNumberReceiptPdf;
use App\Election\Support\ElectionStorage;
use App\Election\Voting\StandardQrCode;

final class FileControlNumberReceiptPrinter implements ControlNumberPrinter
{
    public function __construct(
        private readonly ElectionStorage $storage,
        private readonly ActivityJournal $journal,
        private readonly StandardQrCode $qrCode,
        private readonly ControlNumberReceiptPdf $receipt,
    ) {}

    /**
     * @param  array<string, mixed>  $release
     * @return array<string, mixed>
     */
    public function print(array $release): array
    {
        $releaseId = (string) ($release['release_id'] ?? '');
        $controlNumber = (string) ($release['release_code'] ?? '');
        $configuration = $this->storage->readJson('runtime/active-precinct.json');
        $qrPayload = 'aes-print-release:'.$controlNumber;
        $qrArtifactPath = $this->storage->writeText(
            "print-forms/control-number/{$releaseId}-qr.png",
            $this->qrCode->renderPrintPng($qrPayload),
        );
        $pdfContents = $this->receipt->render($release, $configuration, $qrArtifactPath);
        $pdfPath = $this->storage->writeText("print-forms/control-number/{$releaseId}.pdf", $pdfContents);
        $job = [
            'schema_version' => 'control-number-print-job-1',
            'release_id' => $releaseId,
            'paper_ballot_serial' => $release['paper_ballot_serial'] ?? null,
            'printer' => 'file',
            'status' => 'printed',
            'control_number_digits' => strlen($controlNumber),
            'qr_payload' => $qrPayload,
            'qr_artifact_path' => $qrArtifactPath,
            'pdf_artifact_path' => $pdfPath,
            'print_form_profile' => PrintFormProfile::Thermal80->value,
            'print_form_label' => PrintFormProfile::Thermal80->label(),
            'expires_at' => $release['expires_at'] ?? null,
        ];

        $this->storage->writeJson("print-jobs/control-number/{$releaseId}.json", $job);
        $this->journal->record('control_number.print_recorded', [
            'release_id' => $releaseId,
            'printer' => 'file',
            'status' => 'printed',
            'pdf_artifact_path' => $pdfPath,
        ]);

        return $job;
    }
}
