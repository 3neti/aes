<?php

namespace App\Election\Scenarios;

use App\Election\Core\CanonicalJson;
use App\Election\Lifecycle\ElectionRunType;
use App\Election\Support\ElectionClock;
use App\Election\Support\ElectionStorage;
use Illuminate\Filesystem\Filesystem;
use RuntimeException;

final class BrowserWalkthroughControl
{
    public const Header = 'X-Election-Walkthrough-Token';

    public function __construct(
        private readonly ElectionStorage $storage,
        private readonly ElectionClock $clock,
        private readonly CanonicalJson $json,
        private readonly Filesystem $files,
        private readonly BrowserWalkthroughRecovery $recovery,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function begin(string $scenario, string $precinctId): array
    {
        $existing = $this->read();

        if (($existing['status'] ?? null) === 'active') {
            if (! $this->isExpired($existing) && $this->coordinatorIsAlive($existing)) {
                throw new RuntimeException('A browser walkthrough is already active.');
            }

            $message = $this->isExpired($existing)
                ? 'The previous browser walkthrough coordinator lease expired.'
                : 'The previous browser walkthrough coordinator process is no longer running.';
            $recovered = $this->recovery->recover($existing, $message);
            $existing['status'] = $recovered['status'];
            $existing['completed_at'] = $this->clock->now()->toIso8601String();
            $existing['recovered_at'] = $existing['completed_at'];
            $existing['recovery_path'] = $recovered['recovery_path'];
            $existing['recovered_run_finalized'] = $recovered['run_finalized'];
            $this->write($existing);
        }

        $token = bin2hex(random_bytes(32));
        $walkthroughId = 'walkthrough-'.substr(hash('sha256', $token), 0, 12);
        $previousRunType = config('election.runtime.run_type');

        try {
            $this->storage->selectRunType(ElectionRunType::Rehearsal);
            $run = $this->storage->startRun(
                'browser-'.$scenario,
                $precinctId,
                $this->clock->now()->format('Ymd-His-u'),
                ElectionRunType::Rehearsal,
                'browser-walkthrough',
            );
        } finally {
            config()->set('election.runtime.run_type', $previousRunType);
        }

        $control = [
            'schema_version' => 'browser-walkthrough-control-1',
            'walkthrough_id' => $walkthroughId,
            'scenario' => $scenario,
            'status' => 'active',
            'run_id' => $run['run_id'],
            'run_path' => $run['run_path'],
            'run_type' => ElectionRunType::Rehearsal->value,
            'coordinator_pid' => getmypid(),
            'coordinator_started_at' => $this->clock->now()->toIso8601String(),
            'created_at' => $this->clock->now()->toIso8601String(),
            'expires_at' => $this->clock->now()->addHours(4)->toIso8601String(),
            'token_hash' => hash('sha256', $token),
        ];
        $this->write($control);

        return [
            ...$control,
            'token' => $token,
            'control_path' => $this->path(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function authorize(string $token): array
    {
        $control = $this->read();

        if ($control === []
            || ($control['status'] ?? null) !== 'active'
            || $this->isExpired($control)
            || ! hash_equals((string) ($control['token_hash'] ?? ''), hash('sha256', $token))) {
            throw new RuntimeException('The browser walkthrough token is invalid or expired.');
        }

        $rehearsal = $this->storage->currentRun(ElectionRunType::Rehearsal);

        if (($rehearsal['run_id'] ?? null) !== ($control['run_id'] ?? null)) {
            throw new RuntimeException('The browser walkthrough rehearsal pointer has changed.');
        }

        return $control;
    }

    /**
     * @return array<string, mixed>
     */
    public function complete(string $token, string $status): array
    {
        if (! in_array($status, ['passed', 'failed'], true)) {
            throw new RuntimeException("Unsupported browser walkthrough status [{$status}].");
        }

        $control = $this->authorize($token);
        $control['status'] = $status;
        $control['completed_at'] = $this->clock->now()->toIso8601String();
        $this->write($control);

        return $control;
    }

    /**
     * @return array<string, mixed>
     */
    public function read(): array
    {
        if (! $this->files->exists($this->path())) {
            return [];
        }

        $control = json_decode($this->files->get($this->path()), true, flags: JSON_THROW_ON_ERROR);
        $recordedHash = $control['control_hash'] ?? null;
        unset($control['control_hash']);

        if (! is_string($recordedHash) || ! hash_equals($recordedHash, $this->json->hash($control))) {
            throw new RuntimeException('The browser walkthrough control record failed integrity verification.');
        }

        return [
            ...$control,
            'control_hash' => $recordedHash,
        ];
    }

    private function write(array $control): void
    {
        unset($control['control_hash'], $control['token'], $control['control_path']);
        $control['control_hash'] = $this->json->hash($control);
        $this->files->ensureDirectoryExists(dirname($this->path()));
        $this->files->put($this->path(), $this->json->encode($control));
    }

    /**
     * @param  array<string, mixed>  $control
     */
    private function isExpired(array $control): bool
    {
        $expiresAt = (string) ($control['expires_at'] ?? '');

        return $expiresAt === '' || $this->clock->now()->greaterThanOrEqualTo($expiresAt);
    }

    /**
     * @param  array<string, mixed>  $control
     */
    private function coordinatorIsAlive(array $control): bool
    {
        $pid = filter_var($control['coordinator_pid'] ?? null, FILTER_VALIDATE_INT);

        if (! is_int($pid) || $pid < 1) {
            return true;
        }

        if ($pid === getmypid()) {
            return true;
        }

        return ! function_exists('posix_kill') || @posix_kill($pid, 0);
    }

    private function path(): string
    {
        return $this->storage->root().'/browser-walkthrough/control.json';
    }
}
