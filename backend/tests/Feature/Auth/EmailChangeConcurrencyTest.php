<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Actions\Auth\RequestEmailChange;
use App\Actions\Auth\RequestPasswordReset;
use App\Models\EmailChangeRequest;
use App\Models\User;
use Database\Seeders\CoreRolesSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\Process\Process;
use Tests\Support\VerificationFixtures;
use Tests\TestCase;

final class EmailChangeConcurrencyTest extends TestCase
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
            DB::table('password_reset_tokens')->whereIn('user_id', $this->fixtureIds)->delete();
            DB::table('audit_logs')->where('target_type', 'user')->whereIn('target_id', $this->fixtureIds)->delete();
            $this->cleanupFixtures();
        } finally {
            parent::tearDown();
        }
    }

    private function requestChange(?string $destination = null): array
    {
        $user = $this->member();
        $user->forceFill(['email_verified_at' => now('UTC')])->save();
        app(RequestEmailChange::class)->handle((int) $user->id, $user->auth_version, $destination ?? 'race-'.Str::uuid().'@example.test', 'Test-only-password-123', (string) Str::uuid());
        $delivery = $user->notificationDeliveries()->where('type', 'email_change')->sole();
        $payload = $delivery->payload_encrypted;
        $change = EmailChangeRequest::query()->findOrFail($payload['request_id']);

        return [$user, ['operation' => 'confirm', 'actor' => (int) $user->id, 'version' => $user->auth_version,
            'proof' => ['request_id' => $change->id, 'email' => $change->new_email, 'token' => $payload['token']]]];
    }

    public function test_parallel_consumption_succeeds_only_once(): void
    {
        [$user, $task] = $this->requestChange();
        $results = $this->race([$task, $task]);
        sort($results);
        self::assertSame(['SESSION_EXPIRED', 'email_changed'], $results);
        self::assertSame(2, $user->fresh()->auth_version);
        self::assertSame(1, EmailChangeRequest::query()->where('user_id', $user->id)->whereNotNull('consumed_at')->count());
        self::assertSame(1, DB::table('audit_logs')->where('target_id', $user->id)->where('action', 'auth.email_changed')->count());
    }

    public function test_parallel_accounts_cannot_claim_the_same_email(): void
    {
        $email = 'contested-'.Str::uuid().'@example.test';
        [$first, $a] = $this->requestChange($email);
        [$second, $b] = $this->requestChange($email);
        $results = $this->race([$a, $b]);
        sort($results);
        self::assertSame(['EMAIL_UNAVAILABLE', 'email_changed'], $results);
        self::assertSame(1, User::query()->where('email', $email)->count());
        self::assertSame(3, $first->fresh()->auth_version + $second->fresh()->auth_version);
        self::assertSame(1, EmailChangeRequest::query()->whereIn('user_id', [$first->id, $second->id])->whereNotNull('consumed_at')->count());
    }

    public function test_confirmation_racing_replacement_never_revives_old_proof(): void
    {
        [$user, $confirm] = $this->requestChange();
        EmailChangeRequest::query()->where('user_id', $user->id)->update(['created_at' => now('UTC')->subSeconds(61)]);
        $request = ['operation' => 'request', 'actor' => (int) $user->id, 'version' => 1,
            'email' => 'replacement-'.Str::uuid().'@example.test', 'password' => 'Test-only-password-123'];
        $results = $this->race([$confirm, $request]);
        if ($results[0] === 'email_changed') {
            self::assertSame('SESSION_EXPIRED', $results[1]);
            self::assertSame(2, $user->fresh()->auth_version);
        } else {
            self::assertSame(['EMAIL_CHANGE_INVALID', 'email_change_requested'], $results);
            self::assertNotNull(EmailChangeRequest::query()->findOrFail($confirm['proof']['request_id'])->revoked_at);
            self::assertSame($user->email, $user->fresh()->email);
        }
    }

    public function test_confirmation_racing_password_reset_has_one_consistent_winner(): void
    {
        [$user, $confirm] = $this->requestChange();
        app(RequestPasswordReset::class)->handle($user->email);
        $token = $user->notificationDeliveries()->where('type', 'password_reset')->sole()->payload_encrypted['token'];
        $reset = ['operation' => 'reset', 'credentials' => ['email' => $user->email, 'token' => $token, 'password' => 'Reset-race-password-123']];
        $results = $this->race([$confirm, $reset]);
        if ($results[0] === 'email_changed') {
            self::assertSame('PASSWORD_RESET_INVALID', $results[1]);
        } else {
            self::assertSame(['SESSION_EXPIRED', 'password_reset'], $results);
            self::assertNotNull(EmailChangeRequest::query()->findOrFail($confirm['proof']['request_id'])->revoked_at);
        }
        self::assertSame(2, $user->fresh()->auth_version);
        self::assertSame(0, DB::table('password_reset_tokens')->where('user_id', $user->id)->count());
    }

    private function race(array $tasks): array
    {
        $run = bin2hex(random_bytes(16));
        $directory = storage_path('framework/testing/email-change-race-'.$run);
        if (! mkdir($directory, 0700, true) && ! is_dir($directory)) {
            throw new RuntimeException('Cannot create barrier.');
        }
        $processes = [];
        try {
            foreach ($tasks as $index => $task) {
                $process = new Process([PHP_BINARY, base_path('tests/Support/email-change-race-worker.php')], base_path(), [
                    'APP_ENV' => 'testing', 'APP_DEBUG' => 'false', 'DB_CONNECTION' => 'mysql', 'DB_DATABASE' => 'sorerunapp_test', 'DB_URL' => '',
                    'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array', 'QUEUE_CONNECTION' => 'sync', 'MAIL_MAILER' => 'array',
                    'LOG_CHANNEL' => 'stderr', 'OUTBOX_DISPATCH_ON_COMMIT' => 'false', 'BCRYPT_ROUNDS' => '4',
                ]);
                $process->setInput(json_encode(['run' => $run, 'worker' => (string) ($index + 1), 'task' => $task], JSON_THROW_ON_ERROR));
                $process->setTimeout(45);
                $processes[] = $process;
                $process->start();
            }
            $deadline = microtime(true) + 25;
            while (! is_file($directory.'/ready-1') || ! is_file($directory.'/ready-2')) {
                clearstatcache();
                foreach ($processes as $process) {
                    if (! $process->isRunning()) {
                        throw new RuntimeException('Worker exited: '.$process->getErrorOutput());
                    }
                }
                if (microtime(true) > $deadline) {
                    throw new RuntimeException('Parallel barrier timed out.');
                }
                usleep(10_000);
            }
            file_put_contents($directory.'/go', 'go');
            $output = [];
            foreach ($processes as $process) {
                $process->wait();
                self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());
                $output[] = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
            }
            self::assertNotSame($output[0]['connection'], $output[1]['connection']);

            return array_column($output, 'result');
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
