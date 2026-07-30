<?php

namespace App\Election\Support;

use Closure;
use Illuminate\Cache\CacheManager;

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
        $key = 'election-operation:'.hash('sha256', $this->storage->root().'|'.$operationKey);

        return $this->cache
            ->lock($key, $leaseSeconds)
            ->block($waitSeconds, $operation);
    }
}
