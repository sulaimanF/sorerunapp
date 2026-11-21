<?php

declare(strict_types=1);

namespace Tests\Feature\Notifications;

use App\Actions\Auth\RequestEmailChange;
use App\Actions\Notifications\EnqueueNotificationDelivery;
use App\Actions\Notifications\RecordNotificationDelivery;
use App\Jobs\DeliverOutboxNotification;
use App\Models\EmailChangeRequest;
use App\Models\NotificationDelivery;
use App\Notifications\VerifyChangedEmail;
use App\Services\Notifications\DeliverNotification;
use App\Services\Notifications\LocalMailScope;
use Carbon\CarbonImmutable;
use Database\Seeders\CoreRolesSeeder;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Queue\QueueManager;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Mockery;
use RuntimeException;
use Tests\Support\VerificationFixtures;
use Tests\TestCase;

class LocalBackgroundTest extends TestCase
{
    use VerificationFixtures;

    private QueueManager $realQueue;

    protected function setUp(): void
    {
        parent::setUp();
        $this->requireIsolatedMysql();
        $this->seed(CoreRolesSeeder::class);
        $this->realQueue = $this->app['queue'];
        Queue::fake();
        Notification::fake();
    }

    protected function tearDown(): void
    {
        try {
            $this->app['env'] = 'testing';
            DB::table('audit_logs')->where('target_type', 'user')->whereIn('target_id', $this->fixtureIds)->delete();
            $this->cleanupFixtures();
        } finally {
            parent::tearDown();
        }
    }

    public function test_local_synthetic_requests_enqueue_after_commit_on_the_local_queue(): void
    {
        $this->app['env'] = 'local';
        config(['sorerunapp.outbox.dispatch_on_commit' => true]);
        $user = $this->member();
        DB::beginTransaction();
        $row = app(RecordNotificationDelivery::class)->handle($user, 'email_verification', 'local:'.Str::uuid(), CarbonImmutable::now('UTC'));
        Queue::assertNothingPushed();
        DB::commit();
        Queue::assertPushed(DeliverOutboxNotification::class, fn ($job) => $job->deliveryId === $row->id && $job->queue === 'mail-local' && $job->connection === 'database');
    }

    public function test_other_local_recipients_keep_the_existing_mail_queue(): void
    {
        $this->app['env'] = 'local';
        $user = $this->member();
        $user->forceFill(['email' => 'outside-'.Str::uuid().'@example.com'])->save();
        $row = $this->delivery($user);
        app(EnqueueNotificationDelivery::class)->handle($row->id);
        Queue::assertPushed(DeliverOutboxNotification::class, fn ($job) => $job->queue === 'mail');
        app(DeliverNotification::class)->handle($row->id);
        self::assertSame(0, $row->fresh()->attempt_count);
        self::assertSame('pending', $row->fresh()->status->value);
        Notification::assertNothingSent();
    }

    public function test_local_sweep_recovers_only_synthetic_deliveries(): void
    {
        $this->app['env'] = 'local';
        $synthetic = $this->delivery($this->member());
        $otherUser = $this->member();
        $otherUser->forceFill(['email' => 'outside-'.Str::uuid().'@example.com'])->save();
        $other = $this->delivery($otherUser);
        $this->artisan('outbox:dispatch')->assertExitCode(0);
        Queue::assertPushed(DeliverOutboxNotification::class, fn ($job) => $job->deliveryId === $synthetic->id && $job->queue === 'mail-local');
        Queue::assertNotPushed(DeliverOutboxNotification::class, fn ($job) => $job->deliveryId === $other->id);
        self::assertSame('pending', $other->fresh()->status->value);
    }

    public function test_pending_email_must_also_be_synthetic(): void
    {
        $this->app['env'] = 'local';
        $user = $this->member();
        $request = (new EmailChangeRequest)->forceFill([
            'id' => (string) Str::uuid(), 'user_id' => $user->id, 'new_email' => 'outside@example.com',
            'token_hash' => hash('sha256', 'test-only-proof'), 'user_version' => $user->auth_version,
            'expires_at' => now('UTC')->addHour(),
        ]);
        $request->save();
        $row = $this->delivery($user, 'email_change');
        $row->forceFill(['dedupe_key' => 'email_change:'.$request->id, 'payload_encrypted' => ['request_id' => $request->id, 'token' => 'test-only-proof']])->save();
        self::assertFalse(app(LocalMailScope::class)->allows($row));
        $this->artisan('outbox:dispatch')->assertExitCode(0);
        Queue::assertNothingPushed();
    }

    public function test_local_schedule_excludes_global_cleanup_commands(): void
    {
        $this->app['env'] = 'local';
        $schedule = app(Schedule::class);
        $commands = array_map(fn ($event) => $event->command, $schedule->events());
        self::assertCount(1, $commands);
        self::assertStringContainsString('outbox:dispatch', $commands[0]);
    }

    public function test_local_synthetic_email_change_is_queued_recovered_and_delivered_to_pending_address(): void
    {
        $this->app['env'] = 'local';
        config(['sorerunapp.outbox.dispatch_on_commit' => true, 'mail.default' => 'smtp',
            'mail.mailers.smtp.host' => '127.0.0.1', 'mail.mailers.smtp.port' => 1025, 'mail.mailers.smtp.url' => null,
            'sorerunapp.frontend_url' => 'http://localhost:3000']);
        $user = $this->member();
        $user->forceFill(['email_verified_at' => now('UTC')])->save();
        $pending = 'pending-'.Str::uuid().'@example.test';
        app(RequestEmailChange::class)->handle($user->id, $user->auth_version, $pending, 'Test-only-password-123', (string) Str::uuid());
        $delivery = NotificationDelivery::query()->where('user_id', $user->id)->where('type', 'email_change')->sole();
        Queue::assertPushed(DeliverOutboxNotification::class, fn ($job) => $job->deliveryId === $delivery->id && $job->queue === 'mail-local');
        $query = NotificationDelivery::query()->whereKey($delivery->id);
        app(LocalMailScope::class)->constrain($query);
        self::assertTrue($query->exists());
        app(DeliverNotification::class)->handle($delivery->id);
        Notification::assertSentOnDemand(VerifyChangedEmail::class, fn ($notification, $channels, $notifiable) => $notifiable->routes['mail'] === $pending);
        self::assertSame('sent', $delivery->fresh()->status->value);
    }

    public function test_guard_refuses_the_test_database_even_when_environment_is_mislabelled_local(): void
    {
        $this->app['env'] = 'local';
        config(['queue.default' => 'database', 'cache.default' => 'database', 'mail.default' => 'smtp',
            'mail.mailers.smtp.host' => '127.0.0.1', 'mail.mailers.smtp.port' => 1025, 'mail.mailers.smtp.url' => null]);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('sorerunapp on MySQL 8.4');
        app(LocalMailScope::class)->assertReady();
    }

    public function test_guard_refuses_external_smtp(): void
    {
        config(['mail.default' => 'smtp', 'mail.mailers.smtp.host' => 'smtp.example.com']);
        $this->expectException(RuntimeException::class);
        app(LocalMailScope::class)->assertTransport();
    }

    public function test_unhandled_job_failure_is_recorded_separately_and_preserves_outbox_for_recovery(): void
    {
        Queue::swap($this->realQueue);
        $queue = 'local-failure-test-'.Str::uuid();
        $row = $this->delivery($this->member());
        $service = Mockery::mock(DeliverNotification::class);
        $service->shouldReceive('handle')->once()->andThrow(new RuntimeException('SYNTHETIC_WORKER_FAILURE'));
        $this->app->instance(DeliverNotification::class, $service);
        try {
            app(Dispatcher::class)->dispatch((new DeliverOutboxNotification($row->id))->onConnection('database')->onQueue($queue));
            $this->artisan('queue:work', ['connection' => 'database', '--queue' => $queue, '--once' => true, '--tries' => 1])->assertExitCode(0);
            self::assertSame(1, DB::table('failed_jobs')->where('queue', $queue)->count());
            self::assertSame(0, DB::table('jobs')->where('queue', $queue)->count());
            self::assertSame('pending', $row->fresh()->status->value);
            self::assertSame(0, $row->fresh()->attempt_count);
        } finally {
            // Only this test's unique queue on the already guarded disposable database.
            DB::table('failed_jobs')->where('queue', $queue)->delete();
            DB::table('jobs')->where('queue', $queue)->delete();
        }
    }

    public function test_nonlocal_enqueue_retains_the_normal_mail_queue(): void
    {
        $row = $this->delivery($this->member());
        app(EnqueueNotificationDelivery::class)->handle($row->id);
        Queue::assertPushed(DeliverOutboxNotification::class, fn ($job) => $job->queue === 'mail');
    }
}
