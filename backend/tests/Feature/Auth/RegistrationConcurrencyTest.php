<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Models\MemberNumberSequence;
use Database\Seeders\CoreRolesSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Symfony\Component\Process\Process;
use Tests\TestCase;

final class RegistrationConcurrencyTest extends TestCase
{
    public function test_two_processes_registering_one_email_commit_one_complete_membership(): void
    {
        $connection = DB::connection();
        if (! app()->environment('testing') || $connection->getDriverName() !== 'mysql'
            || $connection->getDatabaseName() !== 'sorerunapp_test' || $connection->transactionLevel() !== 0
            || config('cache.default') !== 'array'
            || ! str_starts_with((string) DB::selectOne('SELECT VERSION() AS version')->version, '8.4.')
            || ! Schema::hasTable('notification_deliveries')) {
            throw new RuntimeException('Concurrency test requires isolated MySQL 8.4 sorerunapp_test with stage 01 schema and no outer transaction.');
        }

        $this->seed(CoreRolesSeeder::class);
        $run = bin2hex(random_bytes(16));
        $email = 'registration-race-'.$run.'@example.test';
        $directory = storage_path('framework/testing/registration-race-'.$run);
        if (! mkdir($directory, 0700, true) && ! is_dir($directory)) {
            throw new RuntimeException('Cannot create the concurrency test directory.');
        }

        $nextNumber = MemberNumberSequence::query()->findOrFail('membership')->next_value;
        $processes = [];

        try {
            foreach (['1', '2'] as $worker) {
                $process = new Process([PHP_BINARY, base_path('tests/Support/registration-race-worker.php')], base_path(), [
                    'APP_ENV' => 'testing', 'APP_DEBUG' => 'false',
                    'DB_CONNECTION' => 'mysql', 'DB_DATABASE' => 'sorerunapp_test',
                    'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array',
                    'QUEUE_CONNECTION' => 'sync', 'MAIL_MAILER' => 'array',
                    'LOG_CHANNEL' => 'stderr', 'BCRYPT_ROUNDS' => '4',
                ]);
                $process->setInput(json_encode(['run' => $run, 'worker' => $worker], JSON_THROW_ON_ERROR));
                $process->setTimeout(40);
                $processes[] = $process;
                $process->start();
            }

            $deadline = microtime(true) + 20;
            while (! is_file($directory.'/ready-1') || ! is_file($directory.'/ready-2')) {
                clearstatcache();
                foreach ($processes as $process) {
                    if (! $process->isRunning()) {
                        throw new RuntimeException('Registration worker exited before the barrier: '.$process->getErrorOutput());
                    }
                }
                if (microtime(true) >= $deadline) {
                    throw new RuntimeException('Workers did not reach the registration race barrier.');
                }
                usleep(10_000);
            }

            self::assertNotFalse(file_put_contents($directory.'/go', 'go'));
            foreach ($processes as $process) {
                $process->wait();
                self::assertSame(0, $process->getExitCode(), 'Registration worker failed: '.$process->getErrorOutput());
                self::assertSame(['status' => 'ok'], json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR));
            }

            $users = DB::table('users')->where('email', $email)->get();
            self::assertCount(1, $users);
            $userId = $users->sole()->id;
            self::assertSame(1, DB::table('member_profiles')->where('user_id', $userId)->count());
            $memberships = DB::table('memberships')->where('user_id', $userId)->get();
            self::assertCount(1, $memberships);
            $membership = $memberships->sole();
            self::assertSame('pending_verification', $membership->status);
            self::assertNull($membership->member_number);
            self::assertSame(1, DB::table('user_roles')->where('user_id', $userId)->count());
            self::assertSame(2, DB::table('policy_acknowledgements')->where('user_id', $userId)->count());
            self::assertSame(1, DB::table('notification_deliveries')->where('user_id', $userId)->where('status', 'pending')->count());
            self::assertSame(1, DB::table('audit_logs')->where('target_type', 'membership')->where('target_id', $membership->id)->where('action', 'member.registered')->count());
            self::assertSame(0, DB::table('member_credentials')->where('membership_id', $membership->id)->count());
            self::assertSame($nextNumber, MemberNumberSequence::query()->findOrFail('membership')->next_value);
        } finally {
            foreach ($processes as $process) {
                if ($process->isRunning()) {
                    $process->stop(1);
                }
            }

            // Delete only this random synthetic fixture, never other test records or sequences.
            DB::transaction(function () use ($email): void {
                $userIds = DB::table('users')->where('email', $email)->pluck('id');
                $membershipIds = DB::table('memberships')->whereIn('user_id', $userIds)->pluck('id');
                DB::table('member_credentials')->whereIn('membership_id', $membershipIds)->delete();
                DB::table('audit_logs')->where('target_type', 'membership')->whereIn('target_id', $membershipIds)->where('action', 'member.registered')->delete();
                foreach (['policy_acknowledgements', 'notification_deliveries', 'user_roles', 'member_profiles', 'memberships'] as $table) {
                    DB::table($table)->whereIn('user_id', $userIds)->delete();
                }
                DB::table('users')->whereIn('id', $userIds)->delete();
            });

            foreach (['ready-1', 'ready-2', 'go'] as $file) {
                if (is_file($directory.'/'.$file)) {
                    unlink($directory.'/'.$file);
                }
            }
            rmdir($directory);
        }
    }
}
