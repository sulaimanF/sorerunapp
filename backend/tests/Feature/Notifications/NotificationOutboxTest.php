<?php

declare(strict_types=1);

namespace Tests\Feature\Notifications;

use App\Actions\Memberships\VerifyEmailAndActivateMembership;
use App\Actions\Notifications\RecordNotificationDelivery;
use App\Jobs\DeliverOutboxNotification;
use App\Models\NotificationDelivery;
use App\Notifications\MembershipActivated;
use App\Notifications\VerifyMembershipEmail;
use App\Services\Notifications\DeliverNotification;
use Carbon\CarbonImmutable;
use Database\Seeders\CoreRolesSeeder;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use LogicException;
use Mockery;
use RuntimeException;
use Tests\Support\VerificationFixtures;
use Tests\TestCase;

final class NotificationOutboxTest extends TestCase
{
    use VerificationFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->requireIsolatedMysql();
        $this->seed(CoreRolesSeeder::class);
        config(['sorerunapp.frontend_url' => 'http://localhost:3000', 'app.url' => 'http://localhost:8000', 'mail.default' => 'array', 'sorerunapp.outbox.dispatch_on_commit' => false]);
        Notification::fake();
        Queue::fake();
    }

    protected function tearDown(): void
    {
        try {
            while (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            $this->cleanupFixtures();
        } finally {
            parent::tearDown();
        }
    }

    public function test_committed_outbox_enqueues_only_an_id_on_database_mail_queue(): void
    {
        config(['sorerunapp.outbox.dispatch_on_commit' => true]);
        $user = $this->member();
        DB::beginTransaction();
        $row = app(RecordNotificationDelivery::class)->handle($user, 'email_verification', 'commit:'.bin2hex(random_bytes(12)), CarbonImmutable::now('UTC'));
        Queue::assertNothingPushed();
        DB::commit();
        Queue::assertPushed(DeliverOutboxNotification::class, fn ($job) => $job->deliveryId === (int) $row->id && $job->connection === 'database' && $job->queue === 'mail');
        self::assertSame('pending', $row->fresh()->status->value);
        Notification::assertNothingSent();
    }

    public function test_rollback_discards_outbox_and_enqueue_callback(): void
    {
        config(['sorerunapp.outbox.dispatch_on_commit' => true]);
        $user = $this->member();
        DB::beginTransaction();
        $row = app(RecordNotificationDelivery::class)->handle($user, 'email_verification', 'rollback:'.bin2hex(random_bytes(12)), CarbonImmutable::now('UTC'));
        DB::rollBack();
        self::assertNull(NotificationDelivery::query()->find($row->id));
        Queue::assertNothingPushed();
    }

    public function test_enqueue_failure_preserves_committed_pending_outbox_for_sweep(): void
    {
        $user = $this->member();
        config(['sorerunapp.outbox.dispatch_on_commit' => true]);
        $dispatcher = Mockery::mock(Dispatcher::class);
        $dispatcher->shouldReceive('dispatch')->once()->andThrow(new RuntimeException('synthetic queue outage'));
        $this->app->instance(Dispatcher::class, $dispatcher);
        $row = DB::transaction(fn () => app(RecordNotificationDelivery::class)->handle($user, 'email_verification', 'failure:'.bin2hex(random_bytes(12)), CarbonImmutable::now('UTC')));
        self::assertSame('pending', $row->fresh()->status->value);
    }

    public function test_delivery_uses_current_email_and_version_and_sends_once_without_locks(): void
    {
        $user = $this->member();
        $row = $this->delivery($user);
        $user->forceFill(['email' => 'current-'.bin2hex(random_bytes(8)).'@example.test', 'auth_version' => 2])->save();
        app(DeliverNotification::class)->handle((int) $row->id);
        app(DeliverNotification::class)->handle((int) $row->id);
        Notification::assertSentTo($user->fresh(), VerifyMembershipEmail::class, function ($notification, $channels, $notifiable): bool {
            self::assertSame(0, DB::transactionLevel());
            $message = $notification->toMail($notifiable);
            self::assertStringStartsWith('http://localhost:3000/verify-email#verification=', $message->actionUrl);
            self::assertNull(parse_url($message->actionUrl, PHP_URL_QUERY));
            parse_str((string) parse_url($message->actionUrl, PHP_URL_FRAGMENT), $fragment);
            $signedUrl = $fragment['verification'];
            $request = Request::create($signedUrl);
            self::assertTrue(URL::hasValidSignature($request));
            self::assertSame('2', $request->query('v'));
            self::assertStringContainsString(sha1($notifiable->email), $signedUrl);
            self::assertGreaterThan(time() + 3500, (int) $request->query('expires'));
            self::assertLessThanOrEqual(time() + 3601, (int) $request->query('expires'));
            self::assertStringNotContainsString('src-member:v1:', json_encode($message->toArray(), JSON_THROW_ON_ERROR));

            return true;
        });
        Notification::assertCount(1);
        self::assertSame('sent', $row->fresh()->status->value);
        self::assertSame(1, $row->fresh()->attempt_count);
        self::assertNotNull($row->fresh()->sent_at);
    }

    public function test_activation_email_contains_the_stable_number_without_a_qr(): void
    {
        $user = $this->member();
        config(['sorerunapp.membership.number_prefix' => 'TEST']);
        app(VerifyEmailAndActivateMembership::class)->handle((int) $user->id, (string) $user->id, sha1($user->email), (string) $user->auth_version, (string) Str::uuid(), $user->auth_version);
        $row = $user->notificationDeliveries()->where('type', 'membership_activated')->sole();
        app(DeliverNotification::class)->handle((int) $row->id);
        Notification::assertSentTo($user, MembershipActivated::class, function ($notification, $channels, $notifiable) use ($user): bool {
            $message = json_encode($notification->toMail($notifiable)->toArray(), JSON_THROW_ON_ERROR);
            self::assertStringContainsString($user->membership->fresh()->member_number, $message);
            self::assertStringNotContainsString('src-member:v1:', $message);

            return true;
        });
        self::assertSame('sent', $row->fresh()->status->value);
    }

    public function test_delivery_is_forbidden_inside_a_transaction(): void
    {
        $row = $this->delivery($this->member());
        DB::beginTransaction();
        try {
            app(DeliverNotification::class)->handle((int) $row->id);
            self::fail('Network delivery in a transaction must be rejected.');
        } catch (LogicException) {
            self::assertSame('pending', $row->fresh()->status->value);
        } finally {
            DB::rollBack();
        }
        Notification::assertNothingSent();
    }

    public function test_not_due_live_lease_sent_and_cancelled_rows_are_not_claimed(): void
    {
        $row = $this->delivery($this->member());
        $service = app(DeliverNotification::class);
        $row->forceFill(['available_at' => now('UTC')->addHour()])->save();
        self::assertNull($service->claim((int) $row->id));
        $row->forceFill(['available_at' => now('UTC')->subSecond()])->save();
        $claim = $service->claim((int) $row->id);
        self::assertNotNull($claim);
        self::assertNull($service->claim((int) $row->id));
        $service->sent($claim);
        self::assertNull($service->claim((int) $row->id));
        $other = $this->delivery($this->member());
        $claim = $service->claim((int) $other->id);
        $service->cancel($claim, 'RECIPIENT_UNAVAILABLE');
        self::assertNull($service->claim((int) $other->id));
    }

    public function test_expired_lease_is_recovered_and_stale_completion_cannot_overwrite_new_lease(): void
    {
        $row = $this->delivery($this->member());
        $service = app(DeliverNotification::class);
        $old = $service->claim((int) $row->id);
        $this->travel(181)->seconds();
        $new = $service->claim((int) $row->id);
        self::assertSame(2, $new->attempt);
        $service->sent($old);
        $service->fail($old, 'MAIL_SEND_FAILED');
        $service->cancel($old, 'RECIPIENT_UNAVAILABLE');
        self::assertSame('processing', $row->fresh()->status->value);
        $service->sent($new);
        self::assertSame('sent', $row->fresh()->status->value);
        $this->travelBack();
    }

    public function test_retry_backoff_and_attempt_exhaustion_are_bounded(): void
    {
        $row = $this->delivery($this->member());
        $service = app(DeliverNotification::class);
        foreach ([60, 300, 900, 3600, 3600] as $index => $delay) {
            $claim = $service->claim((int) $row->id);
            self::assertSame($index + 1, $claim->attempt);
            $service->fail($claim, 'MAIL_SEND_FAILED');
            self::assertNull($service->claim((int) $row->id));
            self::assertGreaterThanOrEqual(time() + $delay - 1, $row->fresh()->available_at->timestamp);
            $row->forceFill(['available_at' => now('UTC')->subSecond()])->save();
        }
        self::assertNull($service->claim((int) $row->id));
        self::assertSame(5, $row->fresh()->attempt_count);
        self::assertSame('ATTEMPTS_EXHAUSTED', $row->fresh()->last_error_code);
    }

    public function test_crashed_final_attempt_is_marked_failed_when_lease_expires(): void
    {
        $row = $this->delivery($this->member());
        $row->forceFill(['status' => 'processing', 'attempt_count' => 5, 'lease_until' => now('UTC')->subSecond()])->save();
        app(DeliverNotification::class)->handle((int) $row->id);
        self::assertSame('failed', $row->fresh()->status->value);
        self::assertNull($row->fresh()->lease_until);
        self::assertSame('ATTEMPTS_EXHAUSTED', $row->fresh()->last_error_code);
    }

    public function test_obsolete_verification_and_unavailable_recipients_are_cancelled(): void
    {
        $user = $this->member();
        $row = $this->delivery($user);
        $user->forceFill(['email_verified_at' => now('UTC')])->save();
        app(DeliverNotification::class)->handle((int) $row->id);
        self::assertSame('cancelled', $row->fresh()->status->value);
        $blocked = $this->member();
        $row = $this->delivery($blocked);
        $blocked->forceFill(['status' => 'blocked', 'blocked_at' => now('UTC')])->save();
        app(DeliverNotification::class)->handle((int) $row->id);
        self::assertSame('RECIPIENT_UNAVAILABLE', $row->fresh()->last_error_code);
        $row = $this->delivery($this->member(), 'unknown_type');
        app(DeliverNotification::class)->handle((int) $row->id);
        self::assertSame('UNSUPPORTED_NOTIFICATION_TYPE', $row->fresh()->last_error_code);
        Notification::assertNothingSent();
    }

    public function test_log_transport_is_rejected_without_exposing_signed_url(): void
    {
        config(['mail.default' => 'log']);
        $row = $this->delivery($this->member());
        app(DeliverNotification::class)->handle((int) $row->id);
        self::assertSame('failed', $row->fresh()->status->value);
        self::assertSame('SMTP_MAILER_REQUIRED', $row->fresh()->last_error_code);
        Notification::assertNothingSent();
    }

    public function test_transport_failure_stores_only_a_controlled_error_code(): void
    {
        $row = $this->delivery($this->member());
        Notification::shouldReceive('sendNow')->once()->andThrow(new RuntimeException('synthetic private transport details'));
        app(DeliverNotification::class)->handle((int) $row->id);
        self::assertSame('failed', $row->fresh()->status->value);
        self::assertSame('MAIL_SEND_FAILED', $row->fresh()->last_error_code);
        self::assertNull($row->fresh()->provider_reference);
    }

    public function test_sweeper_enqueues_due_rows_and_expired_leases_but_not_live_or_terminal_rows(): void
    {
        $user = $this->member();
        $due = $this->delivery($user);
        $expired = $this->delivery($user);
        $expired->forceFill(['status' => 'processing', 'attempt_count' => 5, 'lease_until' => now('UTC')->subSecond()])->save();
        $live = $this->delivery($user);
        $live->forceFill(['status' => 'processing', 'lease_until' => now('UTC')->addMinute()])->save();
        $future = $this->delivery($user);
        $future->forceFill(['available_at' => now('UTC')->addHour()])->save();
        $this->artisan('outbox:dispatch', ['--limit' => '1000'])->assertSuccessful();
        Queue::assertPushed(DeliverOutboxNotification::class, fn ($job) => $job->deliveryId === (int) $due->id);
        Queue::assertPushed(DeliverOutboxNotification::class, fn ($job) => $job->deliveryId === (int) $expired->id);
        Queue::assertNotPushed(DeliverOutboxNotification::class, fn ($job) => in_array($job->deliveryId, [(int) $live->id, (int) $future->id], true));
        $this->artisan('outbox:dispatch', ['--limit' => '0'])->assertExitCode(2);
    }
}
