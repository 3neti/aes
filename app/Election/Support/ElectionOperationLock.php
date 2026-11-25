<?php

namespace App\Election\Support;

use Closure;
use Illuminate\Cache\CacheManager;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

final class ElectionOperationLock
{
    public function __construct(
        private readonly CacheManager $cache,
        private readonly ElectionStorage $storage,
    ) {}

    public function execute(
        string $operationKey,
        Closure $operation,
        int $leaseSeconds = 15,
        int $waitSeconds = 5,
    ): mixed {
        if (config('election.voting_lock_driver') === 'local') {
            return $this->executeLocal($operationKey, $operation, $waitSeconds, false);
        }

        $key = 'election-operation:'.hash('sha256', $this->storage->root().'|'.$operationKey);

        return $this->cache
            ->lock($key, $leaseSeconds)
            ->block($waitSeconds, $operation);
    }

    public function executeLocalVoting(Closure $operation, int $waitSeconds = 5): mixed
    {
        return $this->executeLocal('public-simulation-voting', $operation, $waitSeconds, true);
    }

    private function executeLocal(string $operationKey, Closure $operation, int $waitSeconds, bool $logTiming): mixed
    {
        $directory = storage_path('app/private/election-operation-locks');
        if (! is_dir($directory) && ! @mkdir($directory, 0770, true) && ! is_dir($directory)) {
            throw new RuntimeException('Unable to prepare the election lock directory.');
        }

        $path = $directory.'/'.hash('sha256', $this->storage->root().'|'.$operationKey).'.lock';
        $handle = @fopen($path, 'c');
        if ($handle === false) {
            throw new RuntimeException('Unable to open the election lock.');
        }

        $started = hrtime(true);
        $acquired = false;
        try {
            do {
                $acquired = flock($handle, LOCK_EX | LOCK_NB);
                if ($acquired) {
                    break;
                }
                if ((hrtime(true) - $started) >= $waitSeconds * 1_000_000_000) {
                    if ($logTiming) {
                        $this->logTiming('warning', 'Election voting gate acquisition timed out', [
                            'operation' => request()->route()?->getName() ?? 'console',
                            'wait_ms' => (int) ((hrtime(true) - $started) / 1_000_000),
                        ]);
                    }
                    throw new LockTimeoutException;
                }
                usleep(50_000);
            } while (true);

            $acquiredAt = hrtime(true);
            try {
                return $operation();
            } finally {
                if ($logTiming) {
                    $this->logTiming('info', 'Election voting gate timing', [
                        'operation' => request()->route()?->getName() ?? 'console',
                        'wait_ms' => (int) (($acquiredAt - $started) / 1_000_000),
                        'hold_ms' => (int) ((hrtime(true) - $acquiredAt) / 1_000_000),
                    ]);
                }
            }
        } finally {
            if ($acquired) {
                flock($handle, LOCK_UN);
            }
            fclose($handle);
        }
    }

    /** @param array<string, int|string|null> $context */
    private function logTiming(string $level, string $message, array $context): void
    {
        try {
            Log::log($level, $message, $context);
        } catch (Throwable) {
            error_log('Election voting gate timing log could not be written.');
        }
    }
}
