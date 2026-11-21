<?php

declare(strict_types=1);

namespace Tests\Feature\Administration;

use App\Jobs\DeliverOutboxNotification;
use App\Models\AdminInvitation;
use App\Models\NotificationDelivery;
use App\Models\User;
use App\Notifications\ActivateAdminAccount;
use App\Services\Notifications\DeliverNotification;
use Database\Seeders\CoreRolesSeeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use RuntimeException;
use Tests\Support\AdministrationCommittedFixtures;
use Tests\TestCase;

final class AdminInvitationDeliveryTest extends TestCase
{
    use AdministrationCommittedFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->requireAdministrationDatabase();
        $this->seed(CoreRolesSeeder::class);
        config(['sorerunapp.frontend_url' => 'http://localhost:3000']);
        $actor = $this->administrationActor();
        $this->administrationActors[] = $actor->id;
        Notification::fake();
    }

    protected function tearDown(): void
    {
        try {
            $this->cleanupAdministrationFixtures();
        } finally {
            parent::tearDown();
        }
    }

    public function test_committed_delivery_is_recovered_by_sweep_and_sent_without_a_domain_lock(): void
    {
        $actor = User::findOrFail($this->administrationActors[0]);
        $target = $this->provisionAdmin($actor);
        $delivery = NotificationDelivery::query()->where('user_id', $target['user_id'])->firstOrFail();
        Queue::fake();
        Artisan::call('outbox:dispatch', ['--limit' => 1000]);
        Queue::assertPushed(DeliverOutboxNotification::class, fn ($job) => $job->deliveryId === $delivery->id);
        app(DeliverNotification::class)->handle($delivery->id);
        self::assertSame('sent', $delivery->fresh()->status->value);
        self::assertNull($delivery->fresh()->payload_encrypted);
        Notification::assertSentTo(User::findOrFail($target['user_id']), ActivateAdminAccount::class);
    }

    public function test_provider_failure_retry_reuses_proof_and_expired_proof_is_cancelled(): void
    {
        $actor = User::findOrFail($this->administrationActors[0]);
        $target = $this->provisionAdmin($actor);
        $delivery = NotificationDelivery::query()->where('user_id', $target['user_id'])->firstOrFail();
        $hash = hash('sha256', $delivery->payload_encrypted['token']);
        $providerTransaction = null;
        Notification::shouldReceive('sendNow')->once()->andReturnUsing(static function () use (&$providerTransaction): never {
            $providerTransaction = DB::transactionLevel();
            throw new RuntimeException('Synthetic provider failure.');
        });
        app(DeliverNotification::class)->handle($delivery->id);
        self::assertSame(0, $providerTransaction, 'Provider must run outside the delivery/domain locks.');
        self::assertSame('failed', $delivery->fresh()->status->value);
        self::assertSame('MAIL_SEND_FAILED', $delivery->fresh()->last_error_code);
        self::assertSame($hash, hash('sha256', $delivery->fresh()->payload_encrypted['token']));
        $this->travel(61)->seconds();
        Notification::fake();
        app(DeliverNotification::class)->handle($delivery->id);
        self::assertSame('sent', $delivery->fresh()->status->value);
        self::assertSame(2, $delivery->fresh()->attempt_count);
        $other = $this->provisionAdmin($actor);
        $late = NotificationDelivery::query()->where('user_id', $other['user_id'])->firstOrFail();
        AdminInvitation::query()->where('user_id', $other['user_id'])->update(['expires_at' => now('UTC')->subSecond()]);
        app(DeliverNotification::class)->handle($late->id);
        self::assertSame('cancelled', $late->fresh()->status->value);
        self::assertNull($late->fresh()->payload_encrypted);
    }
}
