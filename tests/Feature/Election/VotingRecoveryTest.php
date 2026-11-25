<?php

use App\Election\Certification\CertificationDeckBuilder;
use App\Election\Core\ActivityJournal;
use App\Election\Core\CanonicalJson;
use App\Election\Preparation\ActivateSamplePackage;
use App\Election\Printing\BallotPrinter;
use App\Election\Printing\PrintFormProfile;
use App\Election\PublicSimulation\PublicSimulationVotingQuiescence;
use App\Election\PublicSimulation\RoleDemoVvpatPrint;
use App\Election\Support\ElectionClock;
use App\Election\Support\ElectionOperationLock;
use App\Election\Support\ElectionStorage;
use App\Election\Tabulation\TabulationProfile;
use App\Election\Voting\AnonymousVoterAuthorization;
use App\Election\Voting\BallotPayloadService;
use App\Election\Voting\PaperBallotLedger;
use App\Election\Voting\PrivateBallotRelease;
use App\Election\Voting\SealedBallotBox;
use App\Models\SimulationPrecinct;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Crypt;
use Symfony\Component\Process\Process;

beforeEach(function (): void {
    app(ElectionStorage::class)->reset();
});

test('a corrupted activity journal is preserved and blocks another event', function (): void {
    $journal = app(ActivityJournal::class);
    $journal->record('test.first', ['value' => 'original']);
    $path = app(ElectionStorage::class)->path('journals/activity.jsonl');
    $contents = file_get_contents($path);
    $altered = str_replace('original', 'altered', $contents);
    file_put_contents($path, $altered);

    expect(fn () => $journal->record('test.second'))->toThrow(RuntimeException::class, 'activity journal is invalid');
    expect(file_get_contents($path))->toBe($altered)
        ->and($journal->verifyChain()['passed'])->toBeFalse();
});

test('a partial activity journal write is reported as a failure', function (): void {
    $storage = app(ElectionStorage::class);
    $storage->ensureDirectories();
    $files = Mockery::mock(Filesystem::class)->makePartial();
    $files->shouldReceive('append')->once()->andReturn(1);
    $journal = new ActivityJournal($storage, $files, app(CanonicalJson::class), app(ElectionClock::class), app(ElectionOperationLock::class));

    expect(fn () => $journal->record('test.partial'))
        ->toThrow(RuntimeException::class, 'Unable to write the activity journal');
});

test('a short election state write is reported as a failure', function (): void {
    $storage = app(ElectionStorage::class);
    $storage->ensureDirectories();
    $path = $storage->path('runtime/partial-write-test.json');
    $files = Mockery::mock(Filesystem::class)->makePartial();
    $files->shouldReceive('put')->once()->with($path, Mockery::type('string'))->andReturn(1);
    $checkedStorage = new ElectionStorage($files, app(CanonicalJson::class));

    expect(fn () => $checkedStorage->writeJson('runtime/partial-write-test.json', ['value' => 'data']))
        ->toThrow(RuntimeException::class, 'Unable to write election state completely');
});

test('a short election artifact write is reported as a failure', function (): void {
    $storage = app(ElectionStorage::class);
    $storage->ensureDirectories();
    $path = $storage->path('runtime/partial-artifact-test.txt');
    $files = Mockery::mock(Filesystem::class)->makePartial();
    $files->shouldReceive('put')->once()->with($path, 'test artifact')->andReturn(1);
    $checkedStorage = new ElectionStorage($files, app(CanonicalJson::class));

    expect(fn () => $checkedStorage->writeText('runtime/partial-artifact-test.txt', 'test artifact'))
        ->toThrow(RuntimeException::class, 'Unable to write election artifact completely');
});

test('a torn activity journal entry blocks further events without erasing evidence', function (): void {
    $journal = app(ActivityJournal::class);
    $journal->record('test.first');
    $path = app(ElectionStorage::class)->path('journals/activity.jsonl');
    file_put_contents($path, '{"sequence":', FILE_APPEND);
    $torn = file_get_contents($path);

    expect(fn () => $journal->record('test.second'))->toThrow(RuntimeException::class, 'incomplete final entry')
        ->and($journal->verifyChain()['passed'])->toBeFalse()
        ->and(file_get_contents($path))->toBe($torn);
});

test('a complete journal entry missing its trailing newline blocks another append', function (): void {
    $journal = app(ActivityJournal::class);
    $journal->record('test.first');
    $path = app(ElectionStorage::class)->path('journals/activity.jsonl');
    $unterminated = rtrim(file_get_contents($path), "\r\n");
    file_put_contents($path, $unterminated);

    expect(fn () => $journal->record('test.second'))->toThrow(RuntimeException::class, 'incomplete final entry')
        ->and($journal->verifyChain()['passed'])->toBeFalse()
        ->and(file_get_contents($path))->toBe($unterminated);
});

test('concurrent processes serialize activity journal appends', function (): void {
    $code = <<<'PHP'
        require 'vendor/autoload.php';
        $app = require 'bootstrap/app.php';
        $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
        $app->make(App\Election\Core\ActivityJournal::class)->record('test.concurrent');
    PHP;
    $environment = [
        'APP_ENV' => 'testing',
        'APP_KEY' => (string) config('app.key'),
        'ELECTION_STORAGE_DIRECTORY' => 'election-testing',
        'ELECTION_VOTING_LOCK_DRIVER' => 'local',
    ];
    $first = new Process([PHP_BINARY, '-r', $code], base_path(), $environment);
    $second = new Process([PHP_BINARY, '-r', $code], base_path(), $environment);
    $first->start();
    $second->start();
    $first->wait();
    $second->wait();

    expect($first->isSuccessful())->toBeTrue()
        ->and($second->isSuccessful())->toBeTrue()
        ->and(app(ActivityJournal::class)->verifyChain())->toMatchArray(['passed' => true, 'entry_count' => 2]);
});

test('finalization serializes release creation across voting paths', function (): void {
    config()->set('election.voting_lock_driver', 'local');
    $directory = storage_path('app/private/election-operation-locks');
    if (! is_dir($directory)) {
        mkdir($directory, 0770, true);
    }
    $path = $directory.'/'.hash('sha256', app(ElectionStorage::class)->root().'|private-ballot-finalization').'.lock';
    $handle = fopen($path, 'c');
    expect(flock($handle, LOCK_EX | LOCK_NB))->toBeTrue();

    try {
        expect(fn () => app(PrivateBallotRelease::class)->create('authorization-one', []))
            ->toThrow(LockTimeoutException::class);
        expect(app(ElectionStorage::class)->files('print-releases'))->toBeEmpty();
    } finally {
        flock($handle, LOCK_UN);
        fclose($handle);
    }
});

test('the direct ballot finalization path uses the same serialization lock', function (): void {
    config()->set('election.voting_lock_driver', 'local');
    $directory = storage_path('app/private/election-operation-locks');
    if (! is_dir($directory)) {
        mkdir($directory, 0770, true);
    }
    $path = $directory.'/'.hash('sha256', app(ElectionStorage::class)->root().'|private-ballot-finalization').'.lock';
    $handle = fopen($path, 'c');
    expect(flock($handle, LOCK_EX | LOCK_NB))->toBeTrue();

    try {
        expect(fn () => app(BallotPayloadService::class)->finalize([], 'test-ballot'))
            ->toThrow(LockTimeoutException::class);
    } finally {
        flock($handle, LOCK_UN);
        fclose($handle);
    }
});

test('authorization completion is serialized with other authorization changes', function (): void {
    config()->set('election.voting_lock_driver', 'local');
    $storage = app(ElectionStorage::class);
    $storage->writeJson('voter-authorizations/test-authorization.json', [
        'authorization_id' => 'test-authorization',
        'status' => 'claimed',
    ]);
    $directory = storage_path('app/private/election-operation-locks');
    if (! is_dir($directory)) {
        mkdir($directory, 0770, true);
    }
    $path = $directory.'/'.hash('sha256', $storage->root().'|voter-authorization').'.lock';
    $handle = fopen($path, 'c');
    expect(flock($handle, LOCK_EX | LOCK_NB))->toBeTrue();

    try {
        expect(fn () => app(AnonymousVoterAuthorization::class)->complete('test-authorization'))
            ->toThrow(LockTimeoutException::class)
            ->and($storage->readJson('voter-authorizations/test-authorization.json')['status'])->toBe('claimed');
    } finally {
        flock($handle, LOCK_UN);
        fclose($handle);
    }

    app(AnonymousVoterAuthorization::class)->complete('test-authorization');
    expect($storage->readJson('voter-authorizations/test-authorization.json')['status'])->toBe('completed');
});

test('operator reconciliation requires the local voting lock', function (): void {
    config()->set('election.voting_lock_driver', 'cache');

    expect(fn () => app(RoleDemoVvpatPrint::class)->reconcileForRetry(str_repeat('0', 64), 'OFFICER'))
        ->toThrow(RuntimeException::class, 'requires the single-host voting lock');
});

test('operator reconciliation cannot run in an election day context', function (): void {
    config()->set('election.voting_lock_driver', 'local');
    config()->set('election.storage.directory', 'election-testing/public-simulations/demo/precinct');
    config()->set('election.runtime.run_type', 'election-day');

    expect(fn () => app(RoleDemoVvpatPrint::class)->reconcileForRetry(str_repeat('0', 64), 'OFFICER'))
        ->toThrow(RuntimeException::class, 'available only for a demo rehearsal or automated test');
});

test('a stale paper ledger state blocks another serial instead of reusing one', function (): void {
    $storage = app(ElectionStorage::class);
    $ledger = app(PaperBallotLedger::class);
    $ledger->recordIssued('PRECINCT-DEMO-000001', 'ballot-one', 'payload-one');
    $state = $storage->readJson('paper-ballot-ledger/state/current.json');
    $state['sequence'] = 0;
    $state['issued_count'] = 0;
    $storage->writeJson('paper-ballot-ledger/state/current.json', $state);

    expect(fn () => $ledger->nextRequiredSerial('PRECINCT'))
        ->toThrow(RuntimeException::class, 'ledger state is inconsistent');
    expect($ledger->verifyChain())->toMatchArray(['passed' => true, 'event_count' => 1]);
});

test('a private release cannot be sealed with missing or inconsistent issuance evidence', function (string $damage): void {
    $storage = app(ElectionStorage::class);
    $payload = [
        'ballot_id' => 'ballot-recovery',
        'paper_ballot_serial' => 'PRECINCT-DEMO-000001',
        'payload_hash' => 'payload-recovery',
        'qr_payload' => 'recovery-payload',
    ];
    $storage->writeJson('print-releases/release-recovery.json', [
        'release_id' => 'release-recovery',
        'status' => 'printed',
        'encrypted_payload' => Crypt::encryptString(json_encode($payload, JSON_THROW_ON_ERROR)),
    ]);
    if ($damage !== 'missing') {
        app(PaperBallotLedger::class)->recordIssued($payload['paper_ballot_serial'], $payload['ballot_id'], $damage === 'issue_hash' ? 'other-hash' : $payload['payload_hash']);
        if ($damage === 'index' || $damage === 'issue_hash') {
            $storage->writeJson('paper-ballot-ledger/index/payload-recovery.json', [
                'schema_version' => 'paper-ballot-ledger-payload-index-1',
                'paper_ballot_serial' => $payload['paper_ballot_serial'],
                'ballot_id' => $damage === 'index' ? 'other-ballot' : $payload['ballot_id'],
                'payload_hash' => $payload['payload_hash'],
            ]);
        } else {
            $state = $storage->readJson('paper-ballot-ledger/state/current.json');
            $state['sequence'] = 0;
            $storage->writeJson('paper-ballot-ledger/state/current.json', $state);
        }
    }

    expect(fn () => app(SealedBallotBox::class)->deposit('release-recovery'))
        ->toThrow(RuntimeException::class);
    expect($storage->files('counting/sealed'))->toBeEmpty()
        ->and($storage->readJson('print-releases/release-recovery.json')['status'])->toBe('printed');
})->with(['missing', 'index', 'state', 'issue_hash']);

test('a private release with verified issuance can be deposited', function (): void {
    config()->set('election.tabulation.profile', TabulationProfile::PaperFirst->value);
    $storage = app(ElectionStorage::class);
    $payload = [
        'ballot_id' => 'ballot-recovery',
        'paper_ballot_serial' => 'PRECINCT-DEMO-000001',
        'payload_hash' => 'payload-recovery',
        'qr_payload' => 'recovery-payload',
    ];
    $storage->writeJson('print-releases/release-recovery.json', [
        'release_id' => 'release-recovery',
        'status' => 'printed',
        'encrypted_payload' => Crypt::encryptString(json_encode($payload, JSON_THROW_ON_ERROR)),
    ]);
    app(PaperBallotLedger::class)->recordIssued($payload['paper_ballot_serial'], $payload['ballot_id'], $payload['payload_hash']);

    $deposit = app(SealedBallotBox::class)->deposit('release-recovery');

    expect($deposit['ballot_id'])->toBe($payload['ballot_id'])
        ->and($storage->readJson('print-releases/release-recovery.json')['status'])->toBe('deposited')
        ->and($storage->readJson('counting/sealed/payload-recovery.json')['payload_hash'])->toBe($payload['payload_hash'])
        ->and(app(PaperBallotLedger::class)->summary()['deposited'])->toBe(1);
});

test('issuance verification rejects a second event using the same serial', function (): void {
    $ledger = app(PaperBallotLedger::class);
    $ledger->recordIssued('PRECINCT-DEMO-000001', 'ballot-one', 'payload-one');
    $ledger->recordIssued('PRECINCT-DEMO-000001', 'ballot-two', 'payload-two');

    expect($ledger->verifyChain()['passed'])->toBeTrue()
        ->and($ledger->hasVerifiedIssuance('PRECINCT-DEMO-000001', 'ballot-one', 'payload-one'))->toBeFalse();
});

test('a damaged paper ledger blocks new events without replacing its evidence', function (): void {
    $storage = app(ElectionStorage::class);
    $ledger = app(PaperBallotLedger::class);
    $ledger->recordIssued('PRECINCT-DEMO-000001', 'ballot-one', 'payload-one');
    $relative = 'paper-ballot-ledger/000001-paper_ballot-issued.json';
    $event = $storage->readJson($relative);
    $event['payload']['ballot_id'] = 'altered';
    $storage->writeJson($relative, $event);

    expect(fn () => $ledger->recordIssued('PRECINCT-DEMO-000002', 'ballot-two', 'payload-two'))
        ->toThrow(RuntimeException::class, 'ledger is invalid');
    expect($storage->readJson($relative)['payload']['ballot_id'])->toBe('altered')
        ->and($ledger->verifyChain()['passed'])->toBeFalse();
});

test('a VVPAT print cannot be resubmitted without reconciliation', function (string $status): void {
    $storage = app(ElectionStorage::class);
    $pending = [
        'encrypted_code' => 'not-read-by-this-check',
        'release_id' => 'existing-release',
        'attempts' => 1,
        'status' => $status,
    ];
    $storage->writeJson('runtime/role-demo-vvpat-pending.json', $pending);

    expect(fn () => app(RoleDemoVvpatPrint::class)->attempt())
        ->toThrow(RuntimeException::class, 'print outcome is unknown');
    expect($storage->readJson('runtime/role-demo-vvpat-pending.json'))->toEqual($pending);
})->with(['printing', 'awaiting_confirmation']);

test('an interrupted deposit preserves pending state and blocks reprint or reconfirmation', function (): void {
    $storage = app(ElectionStorage::class);
    $pending = [
        'encrypted_code' => 'not-read-by-this-check',
        'release_id' => 'existing-release',
        'attempts' => 1,
        'status' => 'depositing',
    ];
    $storage->writeJson('runtime/role-demo-vvpat-pending.json', $pending);
    $service = app(RoleDemoVvpatPrint::class);

    expect(fn () => $service->attempt())->toThrow(RuntimeException::class, 'deposit outcome is unresolved')
        ->and(fn () => $service->confirm(new SimulationPrecinct))->toThrow(RuntimeException::class, 'completed print attempt is required')
        ->and($storage->readJson('runtime/role-demo-vvpat-pending.json'))->toEqual($pending);
});

test('unrecognized or inconsistent VVPAT states cannot start a print', function (string $status, int $attempts): void {
    $storage = app(ElectionStorage::class);
    $pending = [
        'encrypted_code' => Crypt::encryptString('1234'),
        'release_id' => 'existing-release',
        'attempts' => $attempts,
        'status' => $status,
    ];
    $storage->writeJson('runtime/role-demo-vvpat-pending.json', $pending);

    expect(fn () => app(RoleDemoVvpatPrint::class)->attempt())
        ->toThrow(RuntimeException::class, 'print state is inconsistent');
    expect($storage->readJson('runtime/role-demo-vvpat-pending.json'))->toEqual($pending)
        ->and($storage->files('print-jobs'))->toBeEmpty();
})->with([
    ['unknown', 0],
    ['pending', 1],
    ['pending', -1],
    ['ready_to_retry', 1],
]);

test('an unresolved VVPAT deposit blocks closeout even when its release is deposited', function (): void {
    $storage = app(ElectionStorage::class);
    $storage->writeJson('print-releases/existing-release.json', [
        'release_id' => 'existing-release',
        'status' => 'deposited',
    ]);
    $storage->writeJson('runtime/role-demo-vvpat-pending.json', [
        'release_id' => 'existing-release',
        'status' => 'depositing',
    ]);

    expect(fn () => app(PublicSimulationVotingQuiescence::class)->assertReadyForClose())
        ->toThrow(RuntimeException::class, 'VVPAT or its deposit requires resolution');
    expect($storage->readJson('runtime/role-demo-vvpat-pending.json')['status'])->toBe('depositing');
});

test('a deposit exception retains the pending VVPAT for investigation', function (): void {
    $storage = app(ElectionStorage::class);
    $storage->writeJson('print-releases/release-one.json', [
        'release_id' => 'release-one',
        'ballot_id' => 'ballot-one',
        'status' => 'printed',
        'encrypted_payload' => 'invalid-payload',
    ]);
    $storage->writeJson('runtime/role-demo-vvpat-pending.json', [
        'encrypted_code' => Crypt::encryptString('1234'),
        'release_id' => 'release-one',
        'attempts' => 1,
        'status' => 'awaiting_confirmation',
    ]);

    expect(fn () => app(RoleDemoVvpatPrint::class)->confirm(new SimulationPrecinct))
        ->toThrow(DecryptException::class);
    expect($storage->readJson('runtime/role-demo-vvpat-pending.json'))
        ->toMatchArray(['release_id' => 'release-one', 'attempts' => 1, 'status' => 'depositing']);
});

test('an interruption during deposit preserves pending state and prevents a second attempt', function (string $failurePoint, int $sealedCount): void {
    config()->set('election.tabulation.profile', TabulationProfile::PaperFirst->value);
    config()->set('election.voting_lock_driver', 'local');
    $configuration = app(ActivateSamplePackage::class)->handle();
    $selections = app(CertificationDeckBuilder::class)->selections($configuration);
    $release = app(PrivateBallotRelease::class)->create('test-authorization', $selections, '1234');
    $storage = app(ElectionStorage::class);
    $releaseRecord = $storage->readJson("print-releases/{$release['release_id']}.json");
    $releaseRecord['status'] = 'printed';
    $storage->writeJson("print-releases/{$release['release_id']}.json", $releaseRecord);
    $storage->writeJson('runtime/role-demo-vvpat-pending.json', [
        'encrypted_code' => Crypt::encryptString('1234'),
        'release_id' => $release['release_id'],
        'attempts' => 1,
        'status' => 'awaiting_confirmation',
    ]);

    $files = Mockery::mock(Filesystem::class)->makePartial();
    $files->shouldReceive('put')->andReturnUsing(function (string $path, string $contents) use ($failurePoint): int|false {
        $isReleaseUpdate = str_contains($path, '/private-print-releases/')
            && (json_decode($contents, true)['status'] ?? null) === 'deposited';
        if (($failurePoint === 'sealed' && str_contains($path, '/sealed-ballot-box/'))
            || ($failurePoint === 'ledger' && str_contains($path, 'paper_ballot-deposited.json'))
            || ($failurePoint === 'release' && $isReleaseUpdate)) {
            throw new RuntimeException('Injected deposit write failure.');
        }

        return file_put_contents($path, $contents);
    });
    app()->instance(ElectionStorage::class, new ElectionStorage($files, app(CanonicalJson::class)));
    $service = app(RoleDemoVvpatPrint::class);

    expect(fn () => $service->confirm(new SimulationPrecinct))->toThrow(RuntimeException::class, 'Injected deposit write failure');
    expect($storage->readJson('runtime/role-demo-vvpat-pending.json'))->toMatchArray([
        'release_id' => $release['release_id'],
        'status' => 'depositing',
    ])
        ->and($storage->files('counting/sealed'))->toHaveCount($sealedCount)
        ->and($storage->readJson("print-releases/{$release['release_id']}.json")['status'])->toBe('printed')
        ->and(fn () => $service->confirm(new SimulationPrecinct))->toThrow(RuntimeException::class)
        ->and($storage->files('counting/sealed'))->toHaveCount($sealedCount);
})->with([
    'before sealed record' => ['sealed', 0],
    'after sealed record' => ['ledger', 1],
    'after ledger event' => ['release', 1],
]);

test('an interrupted VVPAT redemption retains the same release identity', function (): void {
    $storage = app(ElectionStorage::class);
    $storage->writeJson('print-releases/release-one.json', [
        'release_id' => 'release-one',
        'release_code_hash' => hash_hmac('sha256', '1234', (string) config('app.key')),
        'status' => 'redeemed',
        'encrypted_payload' => 'invalid-payload',
    ]);
    $storage->writeJson('runtime/role-demo-vvpat-pending.json', [
        'encrypted_code' => Crypt::encryptString('1234'),
        'attempts' => 0,
        'status' => 'pending',
    ]);

    app(RoleDemoVvpatPrint::class)->attempt();

    $pending = $storage->readJson('runtime/role-demo-vvpat-pending.json');
    expect($pending['release_id'])->toBe('release-one')
        ->and($pending['status'])->toBe('uncertain')
        ->and($storage->readJson('print-releases/release-one.json')['status'])->toBe('redeemed')
        ->and($storage->files('print-releases'))->toHaveCount(1);
    expect(fn () => app(RoleDemoVvpatPrint::class)->attempt())
        ->toThrow(RuntimeException::class, 'print outcome is unknown');
    expect($storage->readJson('runtime/role-demo-vvpat-pending.json')['attempts'])->toBe(1);
});

test('a pending VVPAT refuses a release that does not match its control number', function (): void {
    $storage = app(ElectionStorage::class);
    $storage->writeJson('print-releases/release-two.json', [
        'release_id' => 'release-two',
        'release_code_hash' => hash_hmac('sha256', '1234', (string) config('app.key')),
        'status' => 'redeemed',
    ]);
    $storage->writeJson('runtime/role-demo-vvpat-pending.json', [
        'encrypted_code' => Crypt::encryptString('1234'),
        'release_id' => 'release-one',
        'attempts' => 0,
        'status' => 'pending',
    ]);

    app(RoleDemoVvpatPrint::class)->attempt();

    $pending = $storage->readJson('runtime/role-demo-vvpat-pending.json');
    expect($pending['release_id'])->toBe('release-one')
        ->and($pending['attempts'])->toBe(0)
        ->and($storage->readJson('print-releases/release-two.json')['status'])->toBe('redeemed');
});

test('a pending VVPAT does not print a release already marked printed', function (): void {
    $storage = app(ElectionStorage::class);
    $storage->writeJson('print-releases/release-one.json', [
        'release_id' => 'release-one',
        'release_code_hash' => hash_hmac('sha256', '1234', (string) config('app.key')),
        'status' => 'printed',
    ]);
    $storage->writeJson('runtime/role-demo-vvpat-pending.json', [
        'encrypted_code' => Crypt::encryptString('1234'),
        'release_id' => 'release-one',
        'attempts' => 0,
        'status' => 'pending',
    ]);

    app(RoleDemoVvpatPrint::class)->attempt();

    $pending = $storage->readJson('runtime/role-demo-vvpat-pending.json');
    expect($pending['attempts'])->toBe(0)
        ->and($pending['release_id'])->toBe('release-one')
        ->and($storage->files('print-jobs'))->toBeEmpty();
});

test('a printer exception after submission does not resubmit the ballot', function (): void {
    $storage = app(ElectionStorage::class);
    $printer = new class implements BallotPrinter
    {
        public int $calls = 0;

        public function print(array $payload, ?PrintFormProfile $profile = null): array
        {
            $this->calls++;
            throw new RuntimeException('Submission outcome unknown.');
        }
    };
    app()->instance(BallotPrinter::class, $printer);
    $storage->writeJson('print-releases/release-one.json', [
        'release_id' => 'release-one',
        'release_code_hash' => hash_hmac('sha256', '1234', (string) config('app.key')),
        'status' => 'redeemed',
        'encrypted_payload' => Crypt::encryptString(json_encode([
            'ballot_id' => 'ballot-one',
            'qr_payload' => 'test-payload',
        ], JSON_THROW_ON_ERROR)),
    ]);
    $storage->writeJson('runtime/role-demo-vvpat-pending.json', [
        'encrypted_code' => Crypt::encryptString('1234'),
        'release_id' => 'release-one',
        'attempts' => 0,
        'status' => 'pending',
    ]);

    $service = app(RoleDemoVvpatPrint::class);
    $service->attempt();
    expect($printer->calls)->toBe(1)
        ->and($storage->readJson('runtime/role-demo-vvpat-pending.json')['status'])->toBe('uncertain');
    expect(fn () => $service->attempt())->toThrow(RuntimeException::class, 'print outcome is unknown');
    expect($printer->calls)->toBe(1);
});

test('finalization refuses to create another release for an interrupted authorization', function (): void {
    $storage = app(ElectionStorage::class);
    $storage->writeJson('print-releases/existing-release.json', [
        'release_id' => 'existing-release',
        'authorization_id' => 'interrupted-authorization',
        'ballot_id' => 'existing-ballot',
        'status' => 'pending',
    ]);

    expect(fn () => app(PrivateBallotRelease::class)->create('interrupted-authorization', []))
        ->toThrow(RuntimeException::class, 'Operator reconciliation is required.');

    expect($storage->files('print-releases'))->toHaveCount(1)
        ->and($storage->readJson('print-releases/existing-release.json')['ballot_id'])->toBe('existing-ballot');
});

test('an existing completed release remains blocked without changing its evidence', function (): void {
    config()->set('election.voting_lock_driver', 'local');
    $configuration = app(ActivateSamplePackage::class)->handle();
    $selections = app(CertificationDeckBuilder::class)->selections($configuration);
    $releases = app(PrivateBallotRelease::class);
    $first = $releases->create('test-authorization', $selections, '1234');
    $storage = app(ElectionStorage::class);
    $storage->writeJson('voter-authorizations/test-authorization.json', [
        'authorization_id' => 'test-authorization',
        'status' => 'claimed',
        'code_hash' => hash_hmac('sha256', '1234', (string) config('app.key')),
    ]);
    $releaseRecord = $storage->readJson("print-releases/{$first['release_id']}.json");
    $journalBefore = app(ActivityJournal::class)->entries();
    expect(app(PaperBallotLedger::class)->hasVerifiedIssuance($releaseRecord['paper_ballot_serial'], $releaseRecord['ballot_id'], $releaseRecord['payload_hash']))->toBeTrue()
        ->and(app(ActivityJournal::class)->verifyChain()['passed'])->toBeTrue();

    expect(fn () => $releases->create('test-authorization', $selections, '1234'))
        ->toThrow(RuntimeException::class, 'Operator reconciliation is required')
        ->and($storage->files('print-releases'))->toHaveCount(1)
        ->and(app(ActivityJournal::class)->entries())->toBe($journalBefore);
    expect(fn () => $releases->create('test-authorization', $selections, '9999'))
        ->toThrow(RuntimeException::class, 'Operator reconciliation is required')
        ->and(fn () => $releases->create('test-authorization', [], '1234'))
        ->toThrow(RuntimeException::class, 'Operator reconciliation is required');

    $authorization = $storage->readJson('voter-authorizations/test-authorization.json');
    $completedAuthorization = [...$authorization, 'status' => 'completed'];
    $storage->writeJson('voter-authorizations/test-authorization.json', $completedAuthorization);
    expect(fn () => $releases->create('test-authorization', $selections, '1234'))
        ->toThrow(RuntimeException::class, 'Operator reconciliation is required');
    $storage->writeJson('voter-authorizations/test-authorization.json', $authorization);

    $mapPath = $storage->path('mappings/candidate-code-map.json');
    $map = file_get_contents($mapPath);
    unlink($mapPath);
    expect(fn () => $releases->create('test-authorization', $selections, '1234'))
        ->toThrow(RuntimeException::class, 'Operator reconciliation is required')
        ->and(is_file($mapPath))->toBeFalse();
    file_put_contents($mapPath, $map);
    $corruptMap = json_decode($map, true, flags: JSON_THROW_ON_ERROR);
    $corruptMap['candidate_count']++;
    $storage->writeJson('mappings/candidate-code-map.json', $corruptMap);
    $corruptBytes = file_get_contents($mapPath);
    expect(fn () => $releases->create('test-authorization', $selections, '1234'))
        ->toThrow(RuntimeException::class, 'Operator reconciliation is required')
        ->and(file_get_contents($mapPath))->toBe($corruptBytes);
    file_put_contents($mapPath, $map);

    $duplicate = $releaseRecord;
    $duplicate['release_id'] = 'conflicting-release';
    $duplicate['authorization_id'] = 'different-authorization';
    $duplicate['ballot_id'] = 'different-ballot';
    $storage->writeJson('print-releases/conflicting-release.json', $duplicate);
    expect(fn () => $releases->create('test-authorization', $selections, '1234'))
        ->toThrow(RuntimeException::class, 'Operator reconciliation is required');
    unlink($storage->path('print-releases/conflicting-release.json'));

    $payload = json_decode(Crypt::decryptString($releaseRecord['encrypted_payload']), true, flags: JSON_THROW_ON_ERROR);
    $payload['qr_payload'] = 'invalid-qr';
    $tampered = $releaseRecord;
    $tampered['encrypted_payload'] = Crypt::encryptString(app(CanonicalJson::class)->encode($payload));
    $storage->writeJson("print-releases/{$first['release_id']}.json", $tampered);
    expect(fn () => $releases->create('test-authorization', $selections, '1234'))
        ->toThrow(RuntimeException::class, 'Operator reconciliation is required');
    $storage->writeJson("print-releases/{$first['release_id']}.json", $releaseRecord);

    unlink($storage->path("paper-ballot-ledger/index/{$releaseRecord['payload_hash']}.json"));
    expect(fn () => $releases->create('test-authorization', $selections, '1234'))
        ->toThrow(RuntimeException::class, 'Operator reconciliation is required')
        ->and($storage->files('print-releases'))->toHaveCount(1);
});

test('interrupted finalization preserves the release and refuses a duplicate', function (string $failurePoint): void {
    config()->set('election.voting_lock_driver', 'local');
    $configuration = app(ActivateSamplePackage::class)->handle();
    $selections = app(CertificationDeckBuilder::class)->selections($configuration);
    $storage = app(ElectionStorage::class);
    $files = Mockery::mock(Filesystem::class)->makePartial();
    $files->shouldReceive('put')->andReturnUsing(function (string $path, string $contents) use ($failurePoint): int|false {
        $schema = json_decode($contents, true)['schema_version'] ?? null;
        if (($failurePoint === 'event' && str_contains($path, 'paper_ballot-issued.json'))
            || ($failurePoint === 'state' && $schema === 'paper-ballot-ledger-state-1')) {
            throw new RuntimeException('Injected finalization write failure.');
        }

        return file_put_contents($path, $contents);
    });
    app()->instance(ElectionStorage::class, new ElectionStorage($files, app(CanonicalJson::class)));
    $releases = app(PrivateBallotRelease::class);

    expect(fn () => $releases->create('test-authorization', $selections, '1234'))
        ->toThrow(RuntimeException::class, 'Injected finalization write failure');
    $paths = $storage->files('print-releases');
    expect($paths)->toHaveCount(1)
        ->and(fn () => $releases->create('test-authorization', $selections, '1234'))
        ->toThrow(RuntimeException::class, 'Operator reconciliation is required')
        ->and($storage->files('print-releases'))->toBe($paths);
})->with(['before issuance event' => 'event', 'after issuance event' => 'state']);
