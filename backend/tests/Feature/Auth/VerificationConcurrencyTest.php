<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Models\MemberNumberSequence;
use Database\Seeders\CoreRolesSeeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Symfony\Component\Process\Process;
use Tests\Support\VerificationFixtures;
use Tests\TestCase;

final class VerificationConcurrencyTest extends TestCase
{
    use VerificationFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->requireIsolatedMysql();
        if (DB::transactionLevel() !== 0) {
            throw new RuntimeException('Parallel MySQL tests cannot use an outer transaction.');
        }
        $this->seed(CoreRolesSeeder::class);
        config(['sorerunapp.outbox.dispatch_on_commit' => false]);
    }

    protected function tearDown(): void
    {
        try {
            $this->cleanupFixtures();
        } finally {
            parent::tearDown();
        }
    }

    public function test_parallel_verification_activates_one_membership_exactly_once(): void
    {
        $user = $this->member();
        $next = MemberNumberSequence::query()->findOrFail('membership')->next_value;
        $results = $this->race('activation', [(int) $user->id, (int) $user->id]);
        $actual = array_column($results, 'result');
        sort($actual);
        self::assertSame(['already_active', 'membership_activated'], $actual);
        self::assertSame('active', $user->membership->fresh()->status->value);
        self::assertSame(1, $user->membership->credentials()->whereNull('revoked_at')->count());
        self::assertSame(1, $user->notificationDeliveries()->where('type', 'membership_activated')->count());
        self::assertSame(1, DB::table('audit_logs')->where('target_id', $user->membership->id)->where('action', 'membership.activated')->count());
        self::assertSame($next + 1, MemberNumberSequence::query()->findOrFail('membership')->next_value);
    }

    public function test_parallel_activation_of_different_members_allocates_distinct_numbers(): void
    {
        $first = $this->member();
        $second = $this->member();
        $next = MemberNumberSequence::query()->findOrFail('membership')->next_value;
        $results = $this->race('activation', [(int) $first->id, (int) $second->id]);
        self::assertSame(['membership_activated', 'membership_activated'], array_column($results, 'result'));
        self::assertNotSame($first->membership->fresh()->member_number, $second->membership->fresh()->member_number);
        self::assertSame($next + 2, MemberNumberSequence::query()->findOrFail('membership')->next_value);
    }

    public function test_parallel_workers_claim_a_single_email_delivery(): void
    {
        $row = $this->delivery($this->member());
        $results = $this->race('delivery', [(int) $row->id, (int) $row->id]);
        self::assertSame(1, array_sum(array_column($results, 'sent')));
        self::assertSame('sent', $row->fresh()->status->value);
        self::assertSame(1, $row->fresh()->attempt_count);
    }

    private function race(string $mode, array $ids): array
    {
        $run = bin2hex(random_bytes(16));
        $directory = storage_path('framework/testing/verification-race-'.$run);
        if (! mkdir($directory, 0700, true) && ! is_dir($directory)) {
            throw new RuntimeException('Cannot create race directory.');
        }
        $processes = [];
        try {
            foreach ($ids as $index => $id) {
                $worker = (string) ($index + 1);
                $process = new Process([PHP_BINARY, base_path('tests/Support/verification-race-worker.php')], base_path(), [
                    'APP_ENV' => 'testing', 'APP_DEBUG' => 'false', 'DB_CONNECTION' => 'mysql', 'DB_DATABASE' => 'sorerunapp_test',
                    'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array', 'QUEUE_CONNECTION' => 'sync', 'MAIL_MAILER' => 'array',
                    'LOG_CHANNEL' => 'stderr', 'OUTBOX_DISPATCH_ON_COMMIT' => 'false', 'BCRYPT_ROUNDS' => '4',
                ]);
                $process->setInput(json_encode(['run' => $run, 'worker' => $worker, 'mode' => $mode, 'id' => $id], JSON_THROW_ON_ERROR));
                $process->setTimeout(40);
                $processes[] = $process;
                $process->start();
            }
            $deadline = microtime(true) + 20;
            while (! is_file($directory.'/ready-1') || ! is_file($directory.'/ready-2')) {
                clearstatcache();
                foreach ($processes as $process) {
                    if (! $process->isRunning()) {
                        throw new RuntimeException('Worker exited before barrier: '.$process->getErrorOutput());
                    }
                }
                if (microtime(true) > $deadline) {
                    throw new RuntimeException('Parallel workers timed out at barrier.');
                }
                usleep(10_000);
            }
            self::assertNotFalse(file_put_contents($directory.'/go', 'go'));
            $results = [];
            foreach ($processes as $process) {
                $process->wait();
                self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());
                $results[] = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
            }

            return $results;
        } finally {
            foreach ($processes as $process) {
                if ($process->isRunning()) {
                    $process->stop(1);
                }
            }
            foreach (['ready-1', 'ready-2', 'go'] as $file) {
                if (is_file($directory.'/'.$file)) {
                    unlink($directory.'/'.$file);
                }
            }
            rmdir($directory);
        }
    }
}
