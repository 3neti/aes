<?php

namespace App\Election\Documents;

use App\Election\Core\CanonicalJson;

final class DocumentProfileRegistry
{
    public const BallotProfileId = 'waes-official-ballot-a4-tondo-2025';

    public const ElectionReturnProfileId = 'waes-election-return-a4-tondo-2025';

    public const AssetBundleId = 'waes-election-assets-2025';

    public function __construct(
        private readonly CanonicalJson $json,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function ballotReference(): array
    {
        $profile = $this->profile('official-ballot', self::BallotProfileId, 'selected-candidates-compact-official');

        return $this->reference($profile);
    }

    /**
     * @return array<string, mixed>
     */
    public function electionReturnReference(): array
    {
        $profile = $this->profile('election-return', self::ElectionReturnProfileId, 'combined-election-return');

        return $this->reference($profile);
    }

    /**
     * @return array<string, mixed>
     */
    public function renderingKit(): array
    {
        $assetBundle = $this->assetBundle(includeAssets: true);

        return [
            'schema_version' => 'document-rendering-kit-1',
            'profiles' => [
                'official_ballot' => $this->profile('official-ballot', self::BallotProfileId, 'selected-candidates-compact-official'),
                'election_return' => $this->profile('election-return', self::ElectionReturnProfileId, 'combined-election-return'),
            ],
            'asset_bundle' => $assetBundle,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function reference(array $profile): array
    {
        return [
            'type' => $profile['type'],
            'id' => $profile['id'],
            'hash' => $profile['hash'],
            'asset_bundle_id' => $profile['asset_bundle_id'],
            'asset_bundle_hash' => $profile['asset_bundle_hash'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function profile(string $type, string $id, string $layoutVersion): array
    {
        $assetBundle = $this->assetBundle();
        $profile = [
            'schema_version' => 'document-profile-1',
            'type' => $type,
            'id' => $id,
            'paper_size' => 'a4',
            'layout_version' => $layoutVersion,
            'asset_bundle_id' => $assetBundle['id'],
            'asset_bundle_hash' => $assetBundle['hash'],
        ];
        $profile['hash'] = $this->json->hash($profile);

        return $profile;
    }

    /**
     * @return array<string, mixed>
     */
    private function assetBundle(bool $includeAssets = false): array
    {
        $assetPaths = [
            'republic_seal' => (string) config('election.branding.republic_seal', ''),
            'bagong_pilipinas' => (string) config('election.branding.bagong_pilipinas_logo', ''),
            'comelec' => (string) config('election.branding.comelec_logo', ''),
        ];
        $assets = collect($assetPaths)
            ->map(fn (string $path, string $key): array => $this->asset($key, $path, $includeAssets))
            ->all();
        $bundle = [
            'schema_version' => 'document-asset-bundle-1',
            'id' => self::AssetBundleId,
            'assets' => collect($assets)
                ->map(fn (array $asset): array => array_diff_key($asset, ['data_uri' => true]))
                ->all(),
        ];
        $bundle['hash'] = $this->json->hash($bundle);

        if ($includeAssets) {
            $bundle['assets'] = $assets;
        }

        return $bundle;
    }

    /**
     * @return array<string, mixed>
     */
    private function asset(string $key, string $path, bool $includeData): array
    {
        $exists = $path !== '' && is_file($path);
        $asset = [
            'key' => $key,
            'filename' => $exists ? basename($path) : null,
            'mime_type' => $exists ? $this->mimeType($path) : null,
            'sha256' => $exists ? hash_file('sha256', $path) : null,
        ];

        if ($includeData && $exists) {
            $asset['data_uri'] = 'data:'.$asset['mime_type'].';base64,'.base64_encode((string) file_get_contents($path));
        }

        return $asset;
    }

    private function mimeType(string $path): string
    {
        return match (strtolower(pathinfo($path, PATHINFO_EXTENSION))) {
            'jpg', 'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            default => 'application/octet-stream',
        };
    }
}
