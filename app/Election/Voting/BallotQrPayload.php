<?php

namespace App\Election\Voting;

use App\Election\Core\CanonicalJson;
use RuntimeException;

final class BallotQrPayload
{
    private const Prefix = 'aes-ballot-zlib-1:';

    public function __construct(private readonly CanonicalJson $json) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public function encode(array $payload): string
    {
        $compressed = gzdeflate($this->json->encode($payload), 9);

        if ($compressed === false) {
            throw new RuntimeException('Unable to compact ballot QR payload.');
        }

        return self::Prefix.base64_encode($compressed);
    }

    /**
     * @return array<string, mixed>
     */
    public function decode(string $payload): array
    {
        if (str_starts_with($payload, self::Prefix)) {
            $compressed = base64_decode(substr($payload, strlen(self::Prefix)), true);

            if ($compressed === false) {
                throw new RuntimeException('Ballot QR payload is not valid base64 data.');
            }

            $json = gzinflate($compressed);

            if ($json === false) {
                throw new RuntimeException('Ballot QR payload cannot be decompressed.');
            }
        } else {
            $decoded = base64_decode($payload, true);
            $json = $decoded === false ? $payload : $decoded;
        }

        return json_decode($json, true, flags: JSON_THROW_ON_ERROR);
    }
}
