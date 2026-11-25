<?php

namespace App\Election\Support;

use App\Election\Scenarios\BrowserWalkthroughControl;
use Closure;
use Fiber;
use Illuminate\Contracts\Cache\LockTimeoutException;
use RuntimeException;

final class ElectionRunScopeLock
{
    /** @var array<string, array{pid: int, owner: int|string, depth: int}> */
    private static array $held = [];

    public function executeCommand(Closure $operation, int $waitSeconds = 5, bool $allowActiveWalkthrough = false): mixed
    {
        $keys = [
            'election.storage.directory',
            'election.runtime.run_type',
            'election.pop.clustered_precinct',
            'election.pop.district',
            'election.officers',
        ];
        $previous = [];
        foreach ($keys as $key) {
            $previous[$key] = config($key);
        }

        try {
            return $this->execute(function () use ($operation, $allowActiveWalkthrough): mixed {
                if (! $allowActiveWalkthrough) {
                    $this->assertNoActiveWalkthrough();
                }

                return $operation();
            }, $waitSeconds);
        } finally {
            config()->set($previous);
        }
    }

    public function execute(Closure $operation, int $waitSeconds = 5): mixed
    {
        if (config('election.voting_lock_driver') !== 'local') {
            throw new RuntimeException('The run scope lock requires the single-host local lock profile.');
        }

        $key = hash('sha256', $this->baseRoot());
        $pid = getmypid();
        if ($pid === false) {
            throw new RuntimeException('Unable to identify the election lock process.');
        }
        $fiber = Fiber::getCurrent();
        $owner = $fiber === null ? 'main' : spl_object_id($fiber);
        $held = self::$held[$key] ?? null;
        if ($held !== null && $held['pid'] === $pid && $held['owner'] === $owner) {
            self::$held[$key]['depth']++;
            try {
                return $operation();
            } finally {
                self::$held[$key]['depth']--;
            }
        }

        $directory = storage_path('app/private/election-operation-locks');
        if (! is_dir($directory) && ! @mkdir($directory, 0770, true) && ! is_dir($directory)) {
            throw new RuntimeException('Unable to prepare the election lock directory.');
        }

        $handle = @fopen($directory.'/run-scope-'.$key.'.lock', 'c');
        if ($handle === false) {
            throw new RuntimeException('Unable to open the election run scope lock.');
        }
        $acquired = false;
        $started = hrtime(true);
        try {
            do {
                $acquired = flock($handle, LOCK_EX | LOCK_NB);
                if ($acquired) {
                    break;
                }
                if (hrtime(true) - $started >= $waitSeconds * 1_000_000_000) {
                    throw new LockTimeoutException;
                }
                usleep(50_000);
            } while (true);

            self::$held[$key] = ['pid' => $pid, 'owner' => $owner, 'depth' => 1];
            try {
                return $operation();
            } finally {
                unset(self::$held[$key]);
            }
        } finally {
            if ($acquired) {
                flock($handle, LOCK_UN);
            }
            fclose($handle);
        }
    }

    public function assertNoActiveWalkthrough(): void
    {
        $control = app(BrowserWalkthroughControl::class)->read();
        if (($control['status'] ?? null) === 'active') {
            throw new RuntimeException('An active browser walkthrough reserves the election run. Resolve it before starting another operation.');
        }
        if ($control !== [] && ! in_array($control['status'] ?? null, ['passed', 'failed'], true)) {
            throw new RuntimeException('The browser walkthrough reservation is inconsistent. Operator investigation is required.');
        }
    }

    public function baseRoot(): string
    {
        $directory = trim((string) config('election.storage.directory', 'election'), '/');
        $base = explode('/public-simulations/', $directory, 2)[0];
        $segments = explode('/', $base);
        if ($base === '' || in_array('', $segments, true) || in_array('.', $segments, true) || in_array('..', $segments, true)) {
            throw new RuntimeException('Invalid election storage directory for the run scope lock.');
        }

        return storage_path('app/'.$base);
    }
}
