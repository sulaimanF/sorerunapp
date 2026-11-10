<?php

declare(strict_types=1);

namespace Tests\Feature\Notifications;

use App\Actions\Auth\RequestPasswordReset;
use App\Actions\Auth\ResetMemberPassword;
use App\Jobs\DeliverOutboxNotification;
use App\Models\NotificationDelivery;
use App\Models\User;
use App\Notifications\ResetMemberPasswordMail;
use App\Services\Notifications\DeliverNotification;
use Database\Seeders\CoreRolesSeeder;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Notifications\ChannelManager;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Support\EnforceCsrfInRegistrationTests;
use Tests\Support\VerificationBrowser;
use Tests\Support\VerificationFixtures;
use Tests\TestCase;

final class PasswordResetDeliveryTest extends TestCase
{
    use VerificationBrowser, VerificationFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->requireIsolatedMysql();
        $this->seed(CoreRolesSeeder::class);
        config(['sorerunapp.frontend_url' => 'http://localhost:3000', 'mail.default' => 'array', 'sorerunapp.outbox.dispatch_on_commit' => false]);
        Notification::fake();
        Queue::fake();
    }

    protected function tearDown(): void
    {
        try {
            while (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            if ($this->fixtureIds !== []) {
                DB::table('password_reset_tokens')->whereIn('user_id', $this->fixtureIds)->delete();
                DB::table('audit_logs')->where('target_type', 'user')->whereIn('target_id', $this->fixtureIds)->delete();
                $this->cleanupFixtures();
            }
        } finally {
            parent::tearDown();
        }
    }

    private function requestReset(User $user): NotificationDelivery
    {
        app(RequestPasswordReset::class)->handle($user->email);

        return $user->notificationDeliveries()->where('type', 'password_reset')->latest('id')->firstOrFail();
    }

    public function test_public_request_renders_real_mail_channel_and_email_proof_resets_through_api(): void
    {
        config(['sanctum.stateful' => ['localhost:3000', 'localhost:8000'],
            'sanctum.middleware.validate_csrf_token' => EnforceCsrfInRegistrationTests::class]);
        Notification::swap(new ChannelManager($this->app));
        $user = $this->member();
        $user->forceFill(['email_verified_at' => now()])->save();
        $this->csrf();
        $this->requestJson('POST', '/api/v1/auth/forgot-password', ['email' => $user->email])->assertStatus(202);
        $row = $user->notificationDeliveries()->where('type', 'password_reset')->sole();
        (new DeliverOutboxNotification((int) $row->id))->handle(app(DeliverNotification::class));
        self::assertSame('sent', $row->fresh()->status->value);
        $messages = Mail::mailer('array')->getSymfonyTransport()->messages();
        self::assertCount(1, $messages);
        $message = $messages->first()->getOriginalMessage();
        self::assertSame($user->email, $message->getTo()[0]->getAddress());
        self::assertSame('Reset password sorerunapp', $message->getSubject());
        self::assertSame(1, preg_match('~http://localhost:3000/reset-password#[^\s<>]+~', $message->getTextBody(), $matches));
        parse_str(parse_url($matches[0], PHP_URL_FRAGMENT), $proof);
        self::assertSame($user->email, $proof['email']);
        $this->requestJson('POST', '/api/v1/auth/reset-password', [...$proof, 'password' => 'New-test-password-123', 'password_confirmation' => 'New-test-password-123'])->assertOk();
        $this->requestJson('GET', '/api/v1/auth/me')->assertStatus(401);
        $this->login($user)->assertStatus(401);
        $this->login($user, 'New-test-password-123')->assertOk();
    }

    public function test_commit_dispatch_gap_is_recovered_and_email_uses_original_token_outside_locks(): void
    {
        $user = $this->member();
        config(['sorerunapp.outbox.dispatch_on_commit' => true]);
        DB::beginTransaction();
        $row = $this->requestReset($user);
        $token = $row->payload_encrypted['token'];
        Queue::assertNothingPushed();
        DB::commit();
        Queue::assertPushed(DeliverOutboxNotification::class, fn ($job) => $job->deliveryId === (int) $row->id);
        Queue::fake();
        $this->artisan('outbox:dispatch', ['--limit' => '1000'])->assertSuccessful();
        Queue::assertPushed(DeliverOutboxNotification::class, fn ($job) => $job->deliveryId === (int) $row->id);
        app(DeliverNotification::class)->handle((int) $row->id);
        app(DeliverNotification::class)->handle((int) $row->id);
        Notification::assertSentTo($user, ResetMemberPasswordMail::class, function ($notification, $channels, $recipient) use ($token): bool {
            self::assertSame(0, DB::transactionLevel());
            $url = $notification->toMail($recipient)->actionUrl;
            self::assertStringStartsWith('http://localhost:3000/reset-password#', $url);
            self::assertNull(parse_url($url, PHP_URL_QUERY));
            parse_str(parse_url($url, PHP_URL_FRAGMENT), $proof);
            self::assertSame(['email' => $recipient->email, 'token' => $token], $proof);

            return true;
        });
        Notification::assertCount(1);
        self::assertNull($row->fresh()->payload_encrypted);
        self::assertSame('sent', $row->fresh()->status->value);
    }

    public function test_enqueue_failure_keeps_committed_token_and_pending_delivery(): void
    {
        $user = $this->member();
        config(['sorerunapp.outbox.dispatch_on_commit' => true]);
        $dispatcher = Mockery::mock(Dispatcher::class);
        $dispatcher->shouldReceive('dispatch')->once()->andThrow(new RuntimeException('synthetic queue outage'));
        $this->app->instance(Dispatcher::class, $dispatcher);
        $row = $this->requestReset($user);
        self::assertSame('pending', $row->fresh()->status->value);
        self::assertNotNull($row->payload_encrypted);
        self::assertSame(1, DB::table('password_reset_tokens')->where('email', $user->email)->count());
    }

    public function test_rollback_drops_hash_outbox_and_after_commit_job(): void
    {
        $user = $this->member();
        config(['sorerunapp.outbox.dispatch_on_commit' => true]);
        DB::beginTransaction();
        $row = $this->requestReset($user);
        DB::rollBack();
        self::assertNull($row->fresh());
        self::assertSame(0, DB::table('password_reset_tokens')->where('email', $user->email)->count());
        Queue::assertNothingPushed();
    }

    public function test_transport_retry_keeps_same_token_and_original_expiry(): void
    {
        $user = $this->member();
        $row = $this->requestReset($user);
        $proof = $row->payload_encrypted;
        $hash = (array) DB::table('password_reset_tokens')->where('email', $user->email)->first();
        Notification::shouldReceive('sendNow')->once()->andThrow(new RuntimeException('private transport details'));
        app(DeliverNotification::class)->handle((int) $row->id);
        self::assertSame('MAIL_SEND_FAILED', $row->fresh()->last_error_code);
        self::assertSame($proof, $row->fresh()->payload_encrypted);
        $this->travel(61)->seconds();
        Notification::fake();
        app(DeliverNotification::class)->handle((int) $row->id);
        Notification::assertSentTo($user, ResetMemberPasswordMail::class);
        self::assertSame($hash, (array) DB::table('password_reset_tokens')->where('email', $user->email)->first());
        self::assertSame(2, $row->fresh()->attempt_count);
    }

    #[DataProvider('obsoleteReasons')]
    public function test_late_delivery_never_reissues_obsolete_token(string $reason): void
    {
        $user = $this->member();
        $row = $this->requestReset($user);
        $proof = $row->payload_encrypted;
        if ($reason === 'expired') {
            $this->travel(61)->minutes();
        }
        if ($reason === 'email') {
            $user->forceFill(['email' => 'changed-'.Str::uuid().'@example.test'])->save();
        }
        if ($reason === 'blocked') {
            $user->forceFill(['status' => 'blocked', 'auth_version' => 2])->save();
        }
        if ($reason === 'replaced') {
            $this->travel(61)->seconds();
            $this->requestReset($user);
        }
        if ($reason === 'consumed') {
            app(ResetMemberPassword::class)->handle(['email' => $user->email, 'token' => $proof['token'], 'password' => 'New-test-password-123'], (string) Str::uuid());
            // Simulate a delayed retry/duplicate delivery carrying the old encrypted payload.
            $row->forceFill(['status' => 'pending', 'payload_encrypted' => $proof])->save();
        }
        $before = DB::table('password_reset_tokens')->where('email', $proof['email'])->first();
        app(DeliverNotification::class)->handle((int) $row->id);
        self::assertSame('cancelled', $row->fresh()->status->value);
        self::assertNull($row->fresh()->payload_encrypted);
        self::assertEquals($before, DB::table('password_reset_tokens')->where('email', $proof['email'])->first());
        Notification::assertNothingSent();
    }

    public static function obsoleteReasons(): array
    {
        return [['expired'], ['email'], ['blocked'], ['replaced'], ['consumed']];
    }

    public function test_blocked_unverified_account_can_receive_reset_without_changing_eligibility(): void
    {
        $user = $this->member();
        $user->forceFill(['status' => 'blocked'])->save();
        $row = $this->requestReset($user);
        app(DeliverNotification::class)->handle((int) $row->id);
        Notification::assertSentTo($user, ResetMemberPasswordMail::class);
        self::assertSame('blocked', $user->fresh()->status->value);
        self::assertNull($user->fresh()->email_verified_at);
    }

    public function test_log_transport_and_untrusted_frontend_configuration_do_not_expose_proof(): void
    {
        $user = $this->member();
        $row = $this->requestReset($user);
        config(['mail.default' => 'log']);
        app(DeliverNotification::class)->handle((int) $row->id);
        self::assertSame('SMTP_MAILER_REQUIRED', $row->fresh()->last_error_code);
        config(['mail.default' => 'array', 'sorerunapp.frontend_url' => 'https://trusted.example@evil.example/?redirect=bad']);
        $this->travel(61)->seconds();
        app(DeliverNotification::class)->handle((int) $row->id);
        self::assertSame('MAIL_SEND_FAILED', $row->fresh()->last_error_code);
        Notification::assertNothingSent();
    }

    public function test_terminal_mail_failure_erases_the_encrypted_payload_without_extending_token(): void
    {
        $user = $this->member();
        $row = $this->requestReset($user);
        $hash = (array) DB::table('password_reset_tokens')->where('email', $user->email)->first();
        $row->forceFill(['attempt_count' => DeliverNotification::MAX_ATTEMPTS - 1])->save();
        Notification::shouldReceive('sendNow')->once()->andThrow(new RuntimeException('synthetic final failure'));
        app(DeliverNotification::class)->handle((int) $row->id);
        self::assertSame('failed', $row->fresh()->status->value);
        self::assertSame(DeliverNotification::MAX_ATTEMPTS, $row->fresh()->attempt_count);
        self::assertNull($row->fresh()->payload_encrypted);
        self::assertSame($hash, (array) DB::table('password_reset_tokens')->where('email', $user->email)->first());
    }
}
