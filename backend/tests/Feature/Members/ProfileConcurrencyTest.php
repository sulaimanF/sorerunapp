<?php

declare(strict_types=1);

namespace Tests\Feature\Members;

use App\Models\MemberNumberSequence;
use Database\Seeders\CoreRolesSeeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Symfony\Component\Process\Process;
use Tests\Support\VerificationFixtures;
use Tests\TestCase;

final class ProfileConcurrencyTest extends TestCase
{
    use VerificationFixtures;

    private bool $isolatedReady = false;

    protected function setUp(): void
    {
        parent::setUp();
        $this->requireIsolatedMysql();
        $this->isolatedReady = true;
        if (DB::transactionLevel() !== 0) {
            throw new RuntimeException('Profile race requires committed fixtures, not an outer transaction.');
        }
        $this->seed(CoreRolesSeeder::class);
        config(['sorerunapp.outbox.dispatch_on_commit' => false]);
    }

    protected function tearDown(): void
    {
        try {
            if ($this->isolatedReady && $this->fixtureIds !== []) {
                DB::table('audit_logs')->where('action', 'member.profile_updated')->whereIn('actor_user_id', $this->fixtureIds)->delete();
                $this->cleanupFixtures();
            }
        } finally {
            parent::tearDown();
        }
    }

    public function test_profile_completion_and_email_verification_race_activate_once(): void
    {
        $this->assertOneActivation(['profile', 'activation'], false);
    }

    public function test_two_verified_profile_completions_race_activate_once(): void
    {
        $this->assertOneActivation(['profile', 'profile'], true);
    }

    private function assertOneActivation(array $modes, bool $verified): void
    {
        $user = $this->member();
        $user->memberProfile->forceFill(['city' => ''])->save();
        if ($verified) {
            $user->forceFill(['email_verified_at' => now('UTC')])->save();
        }
        $next = MemberNumberSequence::query()->findOrFail('membership')->next_value;
        $this->race(array_map(fn (string $mode): array => ['mode' => $mode, 'id' => (int) $user->id], $modes));
        self::assertSame('active', $user->membership->fresh()->status->value);
        self::assertTrue($user->fresh()->hasVerifiedEmail());
        self::assertSame('Kota Profil Paralel', $user->memberProfile->fresh()->city);
        self::assertSame(1, $user->membership->credentials()->whereNull('revoked_at')->count());
        self::assertSame(1, $user->notificationDeliveries()->where('type', 'membership_activated')->count());
        self::assertSame(1, DB::table('audit_logs')->where('target_type', 'membership')->where('target_id', $user->membership->id)->where('action', 'membership.activated')->count());
        self::assertSame($next + 1, MemberNumberSequence::query()->findOrFail('membership')->next_value);
    }

    private function race(array $ids): array
    {
        $run = bin2hex(random_bytes(16));
        $directory = storage_path('framework/testing/profile-race-'.$run);
        if (! mkdir($directory, 0700, true) && ! is_dir($directory)) {
            throw new RuntimeException('Cannot create race directory.');
        }
        $processes = [];
        try {
            foreach ($ids as $index => $item) {
                $worker = (string) ($index + 1);
                $process = new Process([PHP_BINARY, base_path('tests/Support/profile-race-worker.php')], base_path(), [
                    'APP_ENV' => 'testing', 'APP_DEBUG' => 'false', 'DB_CONNECTION' => 'mysql', 'DB_DATABASE' => 'sorerunapp_test',
                    'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array', 'QUEUE_CONNECTION' => 'sync', 'MAIL_MAILER' => 'array',
                    'LOG_CHANNEL' => 'stderr', 'OUTBOX_DISPATCH_ON_COMMIT' => 'false', 'BCRYPT_ROUNDS' => '4',
                ]);
                $process->setInput(json_encode(['run' => $run, 'worker' => $worker, 'mode' => $item['mode'], 'id' => $item['id']], JSON_THROW_ON_ERROR));
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
