<?php

declare(strict_types=1);

namespace Tests\Feature\Notifications;

use App\Actions\Auth\ConfirmEmailChange;
use App\Actions\Auth\RequestEmailChange;
use App\Jobs\DeliverOutboxNotification;
use App\Models\EmailChangeRequest;
use App\Notifications\VerifyChangedEmail;
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
use Tests\Support\VerificationFixtures;
use Tests\TestCase;

final class EmailChangeDeliveryTest extends TestCase
{
    use VerificationFixtures;

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
            DB::table('audit_logs')->where('target_type', 'user')->whereIn('target_id', $this->fixtureIds)->delete();
            $this->cleanupFixtures();
        } finally {
            parent::tearDown();
        }
    }

    private function requestChange(): array
    {
        $user = $this->member();
        $user->forceFill(['email_verified_at' => now('UTC')])->save();
        app(RequestEmailChange::class)->handle((int) $user->id, $user->auth_version, 'pending-'.Str::uuid().'@example.test', 'Test-only-password-123', (string) Str::uuid());
        $row = $user->notificationDeliveries()->where('type', 'email_change')->sole();
        $change = EmailChangeRequest::query()->findOrFail($row->payload_encrypted['request_id']);

        return [$user, $row, $change];
    }

    public function test_real_array_mail_channel_renders_the_pending_destination_and_trusted_fragment(): void
    {
        Notification::swap(new ChannelManager($this->app));
        [$user, $row, $change] = $this->requestChange();
        $token = $row->payload_encrypted['token'];
        app(DeliverNotification::class)->handle((int) $row->id);
        $messages = Mail::mailer('array')->getSymfonyTransport()->messages();
        self::assertCount(1, $messages);
        $message = $messages->first()->getOriginalMessage();
        self::assertSame($change->new_email, $message->getTo()[0]->getAddress());
        self::assertNotSame($user->email, $message->getTo()[0]->getAddress());
        self::assertSame(1, preg_match('~http://localhost:3000/verify-email-change#[^\s<>]+~', $message->getTextBody(), $matches));
        parse_str(parse_url($matches[0], PHP_URL_FRAGMENT), $proof);
        self::assertSame(['request_id' => $change->id, 'email' => $change->new_email, 'token' => $token], $proof);
        self::assertSame('sent', $row->fresh()->status->value);
        self::assertNull($row->fresh()->payload_encrypted);
    }

    public function test_after_commit_dispatch_gap_and_retry_keep_the_original_proof_outside_locks(): void
    {
        config(['sorerunapp.outbox.dispatch_on_commit' => true]);
        DB::beginTransaction();
        [$user, $row, $change] = $this->requestChange();
        $token = $row->payload_encrypted['token'];
        Queue::assertNothingPushed();
        DB::commit();
        Queue::assertPushed(DeliverOutboxNotification::class, fn ($job) => $job->deliveryId === (int) $row->id);
        self::assertStringNotContainsString($token, serialize(new DeliverOutboxNotification((int) $row->id)));
        Queue::fake();
        $this->artisan('outbox:dispatch', ['--limit' => '1000'])->assertSuccessful();
        Queue::assertPushed(DeliverOutboxNotification::class, fn ($job) => $job->deliveryId === (int) $row->id);
        app(DeliverNotification::class)->handle((int) $row->id);
        app(DeliverNotification::class)->handle((int) $row->id);
        Notification::assertSentOnDemand(VerifyChangedEmail::class, function ($notification, $channels, $recipient) use ($token, $change): bool {
            self::assertSame(0, DB::transactionLevel());
            self::assertSame($change->new_email, $recipient->routes['mail']);
            parse_str(parse_url($notification->toMail($recipient)->actionUrl, PHP_URL_FRAGMENT), $proof);
            self::assertSame($token, $proof['token']);

            return true;
        });
        Notification::assertCount(1);
        self::assertSame($user->email, $user->fresh()->email);
    }

    public function test_queue_failure_and_provider_retry_do_not_extend_or_replace_proof(): void
    {
        config(['sorerunapp.outbox.dispatch_on_commit' => true]);
        $dispatcher = Mockery::mock(Dispatcher::class);
        $dispatcher->shouldReceive('dispatch')->once()->andThrow(new RuntimeException('Synthetic queue outage'));
        $this->app->instance(Dispatcher::class, $dispatcher);
        [$user, $row, $change] = $this->requestChange();
        self::assertSame('pending', $row->fresh()->status->value);
        $payload = $row->payload_encrypted;
        $before = $change->getRawOriginal();
        Notification::shouldReceive('sendNow')->once()->andThrow(new RuntimeException('Synthetic provider details must never be persisted'));
        app(DeliverNotification::class)->handle((int) $row->id);
        self::assertSame('MAIL_SEND_FAILED', $row->fresh()->last_error_code);
        self::assertSame($payload, $row->fresh()->payload_encrypted);
        $this->travel(61)->seconds();
        Notification::fake();
        app(DeliverNotification::class)->handle((int) $row->id);
        Notification::assertSentOnDemand(VerifyChangedEmail::class);
        self::assertSame($before, $change->fresh()->getRawOriginal());
        self::assertSame(2, $row->fresh()->attempt_count);
    }

    #[DataProvider('staleReasons')]
    public function test_late_delivery_cancels_without_reviving_proof(string $reason): void
    {
        [$user, $row, $change] = $this->requestChange();
        $payload = $row->payload_encrypted;
        if ($reason === 'expired') {
            $change->forceFill(['expires_at' => now('UTC')->subSecond()])->save();
        }
        if ($reason === 'revoked') {
            $change->forceFill(['revoked_at' => now('UTC')])->save();
        }
        if ($reason === 'version') {
            $user->forceFill(['auth_version' => 2])->save();
        }
        if ($reason === 'blocked') {
            $user->forceFill(['status' => 'blocked'])->save();
        }
        if ($reason === 'consumed') {
            app(ConfirmEmailChange::class)->handle((int) $user->id, $user->auth_version, ['request_id' => $change->id, 'email' => $change->new_email, 'token' => $payload['token']], (string) Str::uuid());
            $row->forceFill(['status' => 'pending', 'payload_encrypted' => $payload])->save();
        }
        $before = $change->fresh()->getRawOriginal();
        app(DeliverNotification::class)->handle((int) $row->id);
        self::assertSame('cancelled', $row->fresh()->status->value);
        self::assertNull($row->fresh()->payload_encrypted);
        self::assertSame($before, $change->fresh()->getRawOriginal());
        Notification::assertNothingSent();
    }

    public static function staleReasons(): array
    {
        return [['expired'], ['revoked'], ['version'], ['blocked'], ['consumed']];
    }

    public function test_rollback_and_terminal_failure_clean_up_without_sending_secrets_to_log(): void
    {
        config(['sorerunapp.outbox.dispatch_on_commit' => true]);
        DB::beginTransaction();
        [$user, $row, $change] = $this->requestChange();
        DB::rollBack();
        self::assertNull($row->fresh());
        Queue::assertNothingPushed();
        [$user, $row, $change] = $this->requestChange();
        $row->forceFill(['attempt_count' => DeliverNotification::MAX_ATTEMPTS - 1])->save();
        config(['mail.default' => 'log']);
        app(DeliverNotification::class)->handle((int) $row->id);
        self::assertSame('SMTP_MAILER_REQUIRED', $row->fresh()->last_error_code);
        self::assertNull($row->fresh()->payload_encrypted);
        self::assertNull($change->fresh()->consumed_at);
        Notification::assertNothingSent();
    }
}
