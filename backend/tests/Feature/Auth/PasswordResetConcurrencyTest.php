<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Actions\Auth\RequestPasswordReset;
use Database\Seeders\CoreRolesSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use RuntimeException;
use Symfony\Component\Process\Process;
use Tests\Support\VerificationFixtures;
use Tests\TestCase;

final class PasswordResetConcurrencyTest extends TestCase
{
    use VerificationFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->requireIsolatedMysql();
        $this->seed(CoreRolesSeeder::class);
        config(['sorerunapp.outbox.dispatch_on_commit' => false]);
    }

    protected function tearDown(): void
    {
        try {
            if ($this->fixtureIds !== []) {
                DB::table('password_reset_tokens')->whereIn('user_id', $this->fixtureIds)->delete();
                DB::table('audit_logs')->where('target_type', 'user')->whereIn('target_id', $this->fixtureIds)->delete();
                $this->cleanupFixtures();
            }
        } finally {
            parent::tearDown();
        }
    }

    public function test_two_parallel_requests_can_consume_a_token_only_once(): void
    {
        $user = $this->member();
        app(RequestPasswordReset::class)->handle($user->email);
        $token = $user->notificationDeliveries()->where('type', 'password_reset')->sole()->payload_encrypted['token'];
        $credentials = ['email' => $user->email, 'token' => $token, 'password' => 'New-test-password-123'];
        $results = array_column($this->race([$credentials, $credentials]), 'result');
        sort($results);
        self::assertSame(['PASSWORD_RESET_INVALID', 'password_reset'], $results);
        self::assertSame(2, $user->fresh()->auth_version);
        self::assertTrue(Hash::check($credentials['password'], $user->fresh()->password));
        self::assertSame(0, DB::table('password_reset_tokens')->where('email', $user->email)->count());
        self::assertSame(1, DB::table('audit_logs')->where('target_type', 'user')->where('target_id', $user->id)->where('action', 'auth.password_reset')->count());
    }

    private function race(array $ids): array
    {
        $run = bin2hex(random_bytes(16));
        $directory = storage_path('framework/testing/password-reset-race-'.$run);
        if (! mkdir($directory, 0700, true) && ! is_dir($directory)) {
            throw new RuntimeException('Cannot create race directory.');
        }
        $processes = [];
        try {
            foreach ($ids as $index => $item) {
                $worker = (string) ($index + 1);
                $process = new Process([PHP_BINARY, base_path('tests/Support/password-reset-race-worker.php')], base_path(), [
                    'APP_ENV' => 'testing', 'APP_DEBUG' => 'false', 'DB_CONNECTION' => 'mysql', 'DB_DATABASE' => 'sorerunapp_test',
                    'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array', 'QUEUE_CONNECTION' => 'sync', 'MAIL_MAILER' => 'array',
                    'LOG_CHANNEL' => 'stderr', 'OUTBOX_DISPATCH_ON_COMMIT' => 'false', 'BCRYPT_ROUNDS' => '4',
                ]);
                $process->setInput(json_encode(['run' => $run, 'worker' => $worker, 'credentials' => $item], JSON_THROW_ON_ERROR));
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
