<?php

namespace App\Election\Lifecycle;

use App\Election\Core\ActivityJournal;
use App\Election\Support\ElectionStorage;
use RuntimeException;

final class LifecycleState
{
    public function __construct(
        private readonly ElectionStorage $storage,
        private readonly ActivityJournal $journal,
    ) {}

    public function current(): string
    {
        return (string) ($this->storage->readJson('runtime/lifecycle.json', [
            'stage' => Lifecycle::Provision,
        ])['stage'] ?? Lifecycle::Provision);
    }

    public function set(string $stage, bool $journal = true): void
    {
        if (! in_array($stage, Lifecycle::stages(), true)) {
            throw new RuntimeException("Unknown lifecycle stage [{$stage}].");
        }

        $this->storage->writeJson('runtime/lifecycle.json', ['stage' => $stage]);

        if ($journal) {
            $this->journal->record('lifecycle.stage_set', ['stage' => $stage]);
        }
    }

    public function advanceTo(string $stage): void
    {
        $current = $this->current();

        if (Lifecycle::next($current) !== $stage) {
            throw new RuntimeException("Cannot transition from [{$current}] to [{$stage}].");
        }

        $this->set($stage);
    }
}
