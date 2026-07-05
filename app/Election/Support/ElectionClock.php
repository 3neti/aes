<?php

namespace App\Election\Support;

use Carbon\CarbonImmutable;

final class ElectionClock
{
    private ?CarbonImmutable $fixed = null;

    public function now(): CarbonImmutable
    {
        return $this->fixed ?? CarbonImmutable::now();
    }

    public function freeze(string $instant): void
    {
        $this->fixed = CarbonImmutable::parse($instant);
    }

    public function tick(int $seconds = 1): CarbonImmutable
    {
        $this->fixed = ($this->fixed ?? CarbonImmutable::now())->addSeconds($seconds);

        return $this->fixed;
    }

    public function unfreeze(): void
    {
        $this->fixed = null;
    }
}
