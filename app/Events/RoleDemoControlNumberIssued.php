<?php

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

final class RoleDemoControlNumberIssued
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly string $authorizationId,
        public readonly string $code,
        public readonly string $expiresAt,
    ) {}

    /**
     * @param  array{authorization_id: string, code: string, expires_at: string}  $authorization
     */
    public static function fromAuthorization(array $authorization): self
    {
        return new self(
            authorizationId: $authorization['authorization_id'],
            code: $authorization['code'],
            expiresAt: $authorization['expires_at'],
        );
    }
}
