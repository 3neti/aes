<?php

namespace App\Election\Printing;

use App\Election\Printing\Documents\ElectionReturnPdf;
use App\Election\Printing\Documents\OfficialBallotPdf;
use App\Election\Printing\Documents\TallySheetPdf;
use App\Election\Printing\Documents\ThermalBallotPdf;
use App\Election\Printing\Documents\ThermalElectionReturnPdf;
use App\Election\Printing\Documents\ThermalTallySheetPdf;
use App\Election\Support\ElectionStorage;

final class PrintFormArtifactService
{
    public function __construct(
        private readonly ElectionStorage $storage,
        private readonly PrintFormProfileResolver $profiles,
        private readonly OfficialBallotPdf $a4Ballot,
        private readonly ThermalBallotPdf $thermalBallot,
        private readonly TallySheetPdf $a4Tally,
        private readonly ThermalTallySheetPdf $thermalTally,
        private readonly ElectionReturnPdf $a4Return,
        private readonly ThermalElectionReturnPdf $thermalReturn,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $configuration
     * @return array<string, array{profile: string, label: string, width_mm: int, artifact_path: string, sha256: string}>
     */
    public function writeBallot(array $payload, array $configuration, PrintFormProfile $selectedProfile): array
    {
        $ballotId = (string) $payload['ballot_id'];

        return $this->writeAll(
            "print-forms/ballots/{$ballotId}",
            'ballot',
            (string) ($payload['payload_hash'] ?? ''),
            function (PrintFormProfile $profile) use ($payload, $configuration): string {
                return $profile === PrintFormProfile::A4
                    ? $this->a4Ballot->render($payload, $configuration)
                    : $this->thermalBallot->render($payload, $configuration, $profile);
            },
            [PrintFormProfile::A4, $selectedProfile],
        );
    }

    /**
     * @param  array<string, mixed>  $configuration
     * @param  array<string, mixed>  $tally
     * @return array<string, array{profile: string, label: string, width_mm: int, artifact_path: string, sha256: string}>
     */
    public function writeTally(array $configuration, array $tally): array
    {
        return $this->writeAll('print-forms/tally-sheet', 'tally-sheet', (string) ($tally['tally_hash'] ?? ''), function (PrintFormProfile $profile) use ($configuration, $tally): string {
            return $profile === PrintFormProfile::A4
                ? $this->a4Tally->render($configuration, $tally)
                : $this->thermalTally->render($configuration, $tally, $profile);
        });
    }

    /**
     * @param  array<string, mixed>  $configuration
     * @param  array<string, mixed>  $return
     * @return array<string, array{profile: string, label: string, width_mm: int, artifact_path: string, sha256: string}>
     */
    public function writeElectionReturn(array $configuration, array $return): array
    {
        $precinctId = (string) ($return['precinct_id'] ?? 'unknown');

        return $this->writeAll("print-forms/election-return/{$precinctId}", 'election-return', (string) ($return['return_hash'] ?? ''), function (PrintFormProfile $profile) use ($configuration, $return): string {
            return $profile === PrintFormProfile::A4
                ? $this->a4Return->render($configuration, $return)
                : $this->thermalReturn->render($configuration, $return, $profile);
        });
    }

    /**
     * @param  callable(PrintFormProfile): string  $render
     * @param  array<int, PrintFormProfile>|null  $profiles
     * @return array<string, array{profile: string, label: string, width_mm: int, artifact_path: string, sha256: string}>
     */
    private function writeAll(string $directory, string $documentType, string $sourceHash, callable $render, ?array $profiles = null): array
    {
        $artifacts = [];

        foreach (collect($profiles ?? $this->profiles->available())
            ->unique(fn (PrintFormProfile $profile): string => $profile->value) as $profile) {
            $contents = $render($profile);
            $path = $this->storage->writeText("{$directory}/{$profile->value}.pdf", $contents);
            $artifacts[$profile->value] = [
                'profile' => $profile->value,
                'label' => $profile->label(),
                'width_mm' => $profile->widthMillimetres(),
                'artifact_path' => $path,
                'sha256' => hash('sha256', $contents),
            ];
        }

        $this->storage->writeJson("{$directory}/manifest.json", [
            'schema_version' => 'print-form-manifest-1',
            'document_type' => $documentType,
            'source_hash' => $sourceHash,
            'profiles' => $artifacts,
        ]);

        return $artifacts;
    }
}
