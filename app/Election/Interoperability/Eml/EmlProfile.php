<?php

namespace App\Election\Interoperability\Eml;

interface EmlProfile
{
    public function id(): string;

    public function version(): string;

    /** @param array<string, mixed> $context */
    public function serialize(EmlMessageType $messageType, array $context): string;
}
