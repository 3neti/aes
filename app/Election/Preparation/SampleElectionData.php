<?php

namespace App\Election\Preparation;

final class SampleElectionData
{
    /**
     * @return array<string, mixed>
     */
    public function registries(): array
    {
        return $this->read('registries.json');
    }

    /**
     * @return array<string, mixed>
     */
    public function package(): array
    {
        return $this->read('package.json');
    }

    /**
     * @return array<string, mixed>
     */
    public function certificationBallots(): array
    {
        return $this->read('certification-ballots.json');
    }

    /**
     * @return array<string, mixed>
     */
    private function read(string $file): array
    {
        return json_decode(file_get_contents(resource_path("election/sample/{$file}")), true, flags: JSON_THROW_ON_ERROR);
    }
}
