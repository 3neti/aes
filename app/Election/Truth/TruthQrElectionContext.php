<?php

namespace App\Election\Truth;

use App\Election\Support\ElectionStorage;
use LBHurtado\TruthQr\Contracts\ElectionContext;

final class TruthQrElectionContext implements ElectionContext
{
    public function __construct(private readonly ElectionStorage $storage) {}

    /** @return array<string, mixed> */
    public function activePrecinct(): array
    {
        return $this->storage->readJson('runtime/active-precinct.json');
    }

    public function emlProfile(): string
    {
        return (string) config('election.eml.profile', 'waes-eml-7-base-1');
    }
}
