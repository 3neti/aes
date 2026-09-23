<?php

namespace App\Listeners;

use App\Election\Printing\ControlNumberPrinter;
use App\Events\RoleDemoControlNumberIssued;
use Throwable;

final class PrintIssuedRoleDemoControlNumber
{
    public function __construct(
        private readonly ControlNumberPrinter $printer,
    ) {}

    public function handle(RoleDemoControlNumberIssued $event): void
    {
        try {
            $this->printer->print([
                'release_id' => $event->authorizationId,
                'release_code' => $event->code,
                'paper_ballot_serial' => null,
                'expires_at' => $event->expiresAt,
            ]);
        } catch (Throwable $exception) {
            report($exception);
        }
    }
}
