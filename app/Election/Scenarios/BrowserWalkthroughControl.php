<?php

namespace App\Election\Scenarios;

use App\Election\Core\CanonicalJson;
use App\Election\Lifecycle\ElectionRunType;
use App\Election\Support\ElectionClock;
use App\Election\Support\ElectionRunScopeLock;
use App\Election\Support\ElectionStorage;
use App\Models\SimulationPrecinct;
use DirectoryIterator;
use Illuminate\Filesystem\Filesystem;
use RuntimeException;

final class BrowserWalkthroughControl
{
    public const Header = 'X-Election-Walkthrough-Token';

    private const ProcessNotFound = 3;

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
    public function begin(string $scenario, string $precinctId, ?SimulationPrecinct $scopedPrecinct = null): array
    {
        $operation = fn (): array => $this->beginWithinLock($scenario, $precinctId, $scopedPrecinct);

        return config('election.voting_lock_driver') === 'local'
            ? app(ElectionRunScopeLock::class)->execute($operation)
            : $operation();
    }

    /** @return array<string, mixed> */
    private function beginWithinLock(string $scenario, string $precinctId, ?SimulationPrecinct $scopedPrecinct): array
    {
        $existing = $this->read();

        if (($existing['status'] ?? null) === 'active') {
            throw new RuntimeException('A browser walkthrough is already active. Resolve its reservation before starting another.');
        }
        if ($existing !== [] && ! in_array($existing['status'] ?? null, ['passed', 'failed'], true)) {
            throw new RuntimeException('The browser walkthrough reservation is inconsistent. Operator investigation is required.');
        }

        $scope = null;
        if ($scopedPrecinct !== null) {
            if (config('election.voting_lock_driver') !== 'local' || ! in_array($scenario, ['public-simulation', 'demo-room'], true)
                || $this->storage->root() !== app(ElectionRunScopeLock::class)->baseRoot()) {
                throw new RuntimeException('A scoped walkthrough requires the local profile and base storage root.');
            }
            $scope = $this->scopedIdentity((int) $scopedPrecinct->getKey(), $precinctId);
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
        if ($scope !== null) {
            $control['simulation_scope'] = $scope;
        }
        $this->write($control);

        return [
            ...$control,
            'token' => $token,
            'control_path' => $this->path(),
        ];
    }

    /** @return array<string, mixed> */
    public function recoverInterrupted(string $expectedRunId): array
    {
        if (config('election.voting_lock_driver') !== 'local') {
            throw new RuntimeException('Walkthrough recovery requires the single-host local lock profile.');
        }

        return app(ElectionRunScopeLock::class)->execute(function () use ($expectedRunId): array {
            $existing = $this->read();
            if (! in_array($existing['status'] ?? null, ['active', 'finalizing'], true)) {
                throw new RuntimeException('There is no active walkthrough reservation to recover.');
            }
            if ($expectedRunId === '' || ! is_string($existing['run_id'] ?? null) || ! hash_equals($existing['run_id'], $expectedRunId)) {
                throw new RuntimeException('The walkthrough run does not match the requested recovery.');
            }
            if ($this->coordinatorIsAlive($existing)) {
                throw new RuntimeException('The walkthrough coordinator may still be running. Operator investigation is required.');
            }
            if ($existing['status'] === 'finalizing') {
                return $this->finishScopedEvidence($existing, 'failed');
            }
            if (array_key_exists('simulation_scope', $existing)) {
                throw new RuntimeException('Scoped walkthrough recovery requires review of both runs.');
            }

            return $this->recoverExisting($existing, 'The browser walkthrough coordinator process is no longer running.');
        });
    }

    /** @param array<string, mixed> $existing @return array<string, mixed> */
    private function recoverExisting(array $existing, string $message): array
    {
        $recovered = $this->recovery->recover($existing, $message);
        $existing['status'] = $recovered['status'];
        $existing['completed_at'] = $this->clock->now()->toIso8601String();
        $existing['recovered_at'] = $existing['completed_at'];
        $existing['recovery_path'] = $recovered['recovery_path'];
        $existing['recovered_run_finalized'] = $recovered['run_finalized'];
        $this->write($existing);

        return $recovered;
    }

    /**
     * @return array<string, mixed>
     */
    public function authorize(string $token): array
    {
        $control = $this->read();

        if ($control === []
            || ($control['schema_version'] ?? null) !== 'browser-walkthrough-control-1'
            || ($control['status'] ?? null) !== 'active'
            || ($control['run_type'] ?? null) !== ElectionRunType::Rehearsal->value
            || ! is_string($control['run_id'] ?? null) || $control['run_id'] === ''
            || ! is_string($control['run_path'] ?? null) || $control['run_path'] === ''
            || $this->isExpired($control)
            || ! hash_equals((string) ($control['token_hash'] ?? ''), hash('sha256', $token))) {
            throw new RuntimeException('The browser walkthrough token is invalid or expired.');
        }

        $this->storage->requireOpenRehearsal(
            app(ElectionRunScopeLock::class)->baseRoot(),
            $control['run_id'],
            $control['run_path'],
        );
        if (array_key_exists('simulation_scope', $control)) {
            $scope = $control['simulation_scope'];
            if (! is_array($scope) || ! is_int($scope['precinct_id'] ?? null)
                || ! is_string($scope['clustered_precinct'] ?? null)
                || ! hash_equals($this->json->hash($scope), $this->json->hash($this->scopedIdentity($scope['precinct_id'], $scope['clustered_precinct'])))) {
                throw new RuntimeException('The scoped walkthrough reservation has changed.');
            }
        }

        return $control;
    }

    /** @return array<string, mixed> */
    private function scopedIdentity(int $precinctId, string $clusteredPrecinct): array
    {
        $precinct = SimulationPrecinct::query()->with('round')->find($precinctId);
        if ($precinct === null || $precinct->round?->status !== 'open'
            || $precinct->clustered_precinct !== $clusteredPrecinct
            || ! preg_match('/^[A-Z0-9-]+$/D', $precinct->round->code)
            || ! preg_match('/^[A-Z0-9-]+$/D', $precinct->code)) {
            throw new RuntimeException('The scoped walkthrough round or precinct has changed.');
        }

        $base = app(ElectionRunScopeLock::class)->baseRoot();
        $relative = '/public-simulations/'.$precinct->round->code.'/'.$precinct->code;
        $root = $base.$relative;
        $pointer = $root.'/pointers/rehearsal.json';
        if (realpath($base) === false || realpath($pointer) !== realpath($base).$relative.'/pointers/rehearsal.json' || ! is_file($pointer)) {
            throw new RuntimeException('The scoped walkthrough rehearsal pointer is missing or invalid.');
        }
        $context = json_decode($this->files->get($pointer), true, flags: JSON_THROW_ON_ERROR);
        $run = $this->storage->requireOpenRehearsal($root, (string) ($context['run_id'] ?? ''), (string) ($context['run_path'] ?? ''));
        if (($run['precinct_id'] ?? null) !== $clusteredPrecinct) {
            throw new RuntimeException('The scoped walkthrough rehearsal belongs to another precinct.');
        }

        return [
            'round_id' => $precinct->round->id,
            'round_code' => $precinct->round->code,
            'precinct_id' => $precinct->id,
            'precinct_code' => $precinct->code,
            'clustered_precinct' => $clusteredPrecinct,
            'root' => $root,
            'run_id' => $run['run_id'],
            'run_path' => $run['run_path'],
        ];
    }

    /**
     * @return array{base: array<string, mixed>, scoped: array<string, mixed>}
     */
    public function preflightScopedFinalization(string $token): array
    {
        return app(ElectionRunScopeLock::class)->execute(function () use ($token): array {
            $control = $this->authorize($token);
            $scope = $control['simulation_scope'] ?? null;
            if (! is_array($scope)) {
                throw new RuntimeException('Scoped finalization requires a reservation for both runs.');
            }

            foreach ([$control['run_path'], $scope['run_path']] as $runPath) {
                foreach (['artifact-index.json', 'run-summary.json', 'run-summary.txt', 'scoped-walkthrough-evidence.json'] as $name) {
                    $path = $runPath.'/'.$name;
                    clearstatcache(true, $path);
                    if (file_exists($path) || is_link($path)) {
                        throw new RuntimeException('Walkthrough finalization evidence already exists. Preserve both runs for review.');
                    }
                }
            }

            return [
                'base' => $this->storage->requireOpenRehearsal(app(ElectionRunScopeLock::class)->baseRoot(), $control['run_id'], $control['run_path']),
                'scoped' => $this->storage->requireOpenRehearsal($scope['root'], $scope['run_id'], $scope['run_path']),
            ];
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function beginScopedFinalization(string $token): array
    {
        return app(ElectionRunScopeLock::class)->execute(function () use ($token): array {
            $runs = $this->preflightScopedFinalization($token);
            $control = $this->read();
            $control['status'] = 'finalizing';
            $control['finalization_intent'] = [
                'schema_version' => 'scoped-walkthrough-finalization-intent-1',
                'base_run_id' => $runs['base']['run_id'],
                'base_run_path' => $runs['base']['run_path'],
                'scoped_run_id' => $runs['scoped']['run_id'],
                'scoped_run_path' => $runs['scoped']['run_path'],
                'started_at' => $this->clock->now()->toIso8601String(),
                'evidence' => [
                    'base' => $this->inventory($runs['base']['run_path']),
                    'scoped' => $this->inventory($runs['scoped']['run_path']),
                ],
            ];
            $this->write($control);

            return $this->read();
        });
    }

    /** @return array<string, mixed> */
    public function finishScopedFinalization(string $token, bool $recordingPassed): array
    {
        return app(ElectionRunScopeLock::class)->execute(function () use ($token, $recordingPassed): array {
            $control = $this->read();
            if (! is_string($control['token_hash'] ?? null)
                || ! hash_equals($control['token_hash'], hash('sha256', $token))
                || $this->isExpired($control)) {
                throw new RuntimeException('The browser walkthrough token is invalid or expired.');
            }

            return $this->finishScopedEvidence($control, $recordingPassed ? 'passed' : 'failed');
        });
    }

    /** @param array<string, mixed> $control @return array<string, mixed> */
    private function finishScopedEvidence(array $control, string $status): array
    {
        $intent = $control['finalization_intent'] ?? [];
        $scope = $control['simulation_scope'] ?? [];
        if (($control['status'] ?? null) !== 'finalizing'
            || ($intent['schema_version'] ?? null) !== 'scoped-walkthrough-finalization-intent-1'
            || ($intent['base_run_id'] ?? null) !== ($control['run_id'] ?? null)
            || ($intent['base_run_path'] ?? null) !== ($control['run_path'] ?? null)
            || ($intent['scoped_run_id'] ?? null) !== ($scope['run_id'] ?? null)
            || ($intent['scoped_run_path'] ?? null) !== ($scope['run_path'] ?? null)
            || ! is_int($scope['precinct_id'] ?? null) || ! is_string($scope['clustered_precinct'] ?? null)
            || ! hash_equals($this->json->hash($scope), $this->json->hash($this->scopedIdentity($scope['precinct_id'], $scope['clustered_precinct'])))) {
            throw new RuntimeException('The scoped finalization intent does not match the reserved runs.');
        }

        $this->storage->requireOpenRehearsal(app(ElectionRunScopeLock::class)->baseRoot(), $control['run_id'], $control['run_path']);
        $checkpoints = [];
        foreach (['base' => $control['run_path'], 'scoped' => $scope['run_path']] as $role => $path) {
            $files = $this->inventory($path);
            if ($files !== ($intent['evidence'][$role] ?? null)) {
                throw new RuntimeException('Walkthrough run evidence changed after finalization started.');
            }
            $checkpoints[$path.'/scoped-walkthrough-evidence.json'] = $this->json->encode([
                'schema_version' => 'scoped-walkthrough-evidence-1',
                'walkthrough_id' => $control['walkthrough_id'],
                'intent_hash' => $this->json->hash($intent),
                'role' => $role,
                'files' => $files,
            ]);
        }

        foreach ($checkpoints as $path => $contents) {
            if (! file_exists($path) && ! is_link($path)) {
                $this->publishCheckpoint($path, $contents);
            }
            if (is_link($path) || ! is_file($path) || $this->files->get($path) !== $contents) {
                throw new RuntimeException('Existing walkthrough checkpoint differs from the finalization intent.');
            }
        }

        $control['status'] = $status;
        $control['completed_at'] = $this->clock->now()->toIso8601String();
        $control['evidence_checkpoints'] = array_map(fn (string $contents): string => hash('sha256', $contents), $checkpoints);
        $this->write($control);

        return [...$this->read(), 'recovery_path' => array_key_first($checkpoints)];
    }

    /** @return array<string, string> */
    private function inventory(string $root): array
    {
        $files = [];
        $entries = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST,
        );
        foreach ($entries as $entry) {
            $relative = substr($entry->getPathname(), strlen($root) + 1);
            if ($entry->isLink()) {
                throw new RuntimeException('Walkthrough evidence contains a symbolic link.');
            }
            if ($entry->isDir() || $relative === 'scoped-walkthrough-evidence.json') {
                continue;
            }
            $hash = $entry->isFile() ? hash_file('sha256', $entry->getPathname()) : false;
            if (! is_string($hash)) {
                throw new RuntimeException('Unable to hash walkthrough evidence.');
            }
            $files[$relative] = $hash;
        }
        ksort($files, SORT_STRING);

        return $files;
    }

    private function publishCheckpoint(string $path, string $contents): void
    {
        $temporary = tempnam(dirname(dirname($path)), '.walkthrough-');
        if ($temporary === false) {
            throw new RuntimeException('Unable to stage walkthrough evidence.');
        }
        try {
            if (file_put_contents($temporary, $contents) !== strlen($contents) || ! @link($temporary, $path)) {
                throw new RuntimeException('Unable to publish walkthrough evidence without replacing existing files.');
            }
        } finally {
            @unlink($temporary);
        }
    }

    /** @return array<string, mixed> */
    public function complete(string $token, string $status): array
    {
        $operation = fn (): array => $this->completeWithinLock($token, $status);

        return config('election.voting_lock_driver') === 'local'
            ? app(ElectionRunScopeLock::class)->execute($operation)
            : $operation();
    }

    /** @return array<string, mixed> */
    private function completeWithinLock(string $token, string $status): array
    {
        if (! in_array($status, ['passed', 'failed'], true)) {
            throw new RuntimeException("Unsupported browser walkthrough status [{$status}].");
        }

        $control = $this->authorize($token);
        if (array_key_exists('simulation_scope', $control)) {
            throw new RuntimeException('Scoped walkthrough completion requires finalization of both runs.');
        }
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
        $this->assertNoLegacyScopedControls();
        if (is_link(dirname($this->path())) || is_link($this->path())) {
            throw new RuntimeException('The browser walkthrough reservation path is a symbolic link. Operator investigation is required.');
        }
        if (! $this->files->exists($this->path())) {
            return [];
        }
        if (! $this->files->isFile($this->path())) {
            throw new RuntimeException('The browser walkthrough reservation is not a regular file. Operator investigation is required.');
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
        $contents = $this->json->encode($control);
        if (isset($control['finalization_intent'])) {
            $temporary = tempnam(dirname($this->path()), '.control-');
            if ($temporary === false) {
                throw new RuntimeException('Unable to stage the scoped walkthrough reservation.');
            }
            try {
                if (file_put_contents($temporary, $contents) !== strlen($contents) || ! @rename($temporary, $this->path())) {
                    throw new RuntimeException('Unable to publish the scoped walkthrough reservation.');
                }
            } finally {
                if (is_file($temporary)) {
                    @unlink($temporary);
                }
            }

            return;
        }
        if ($this->files->put($this->path(), $contents) !== strlen($contents)) {
            throw new RuntimeException('Unable to write the browser walkthrough reservation completely. Operator investigation is required.');
        }
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

        if (! function_exists('posix_kill') || ! function_exists('posix_get_last_error')) {
            return true;
        }
        if (@posix_kill($pid, 0)) {
            return true;
        }

        return posix_get_last_error() !== self::ProcessNotFound;
    }

    private function path(): string
    {
        return app(ElectionRunScopeLock::class)->baseRoot().'/browser-walkthrough/control.json';
    }

    private function assertNoLegacyScopedControls(): void
    {
        $directory = app(ElectionRunScopeLock::class)->baseRoot().'/public-simulations';
        if (is_link($directory)) {
            throw new RuntimeException('The public simulation evidence directory requires operator investigation.');
        }
        if (! is_dir($directory)) {
            return;
        }
        foreach (new DirectoryIterator($directory) as $round) {
            if ($round->isDot()) {
                continue;
            }
            if ($round->isLink()) {
                throw new RuntimeException('The public simulation evidence directory requires operator investigation.');
            }
            if (! $round->isDir()) {
                continue;
            }
            foreach (new DirectoryIterator($round->getPathname()) as $precinct) {
                if ($precinct->isDot()) {
                    continue;
                }
                if ($precinct->isLink()) {
                    throw new RuntimeException('The public simulation evidence directory requires operator investigation.');
                }
                if (! $precinct->isDir()) {
                    continue;
                }
                $legacyDirectory = $precinct->getPathname().'/browser-walkthrough';
                if (is_link($legacyDirectory)) {
                    throw new RuntimeException('The public simulation evidence directory requires operator investigation.');
                }
                $legacy = $legacyDirectory.'/control.json';
                if (file_exists($legacy) || is_link($legacy)) {
                    throw new RuntimeException('A legacy scoped walkthrough reservation requires operator investigation.');
                }
            }
        }
    }
}
