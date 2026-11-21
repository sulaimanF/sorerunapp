<?php

declare(strict_types=1);

namespace Tests\Feature\Administration;

use App\Actions\Administration\IssueFirstAccountActivation;
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
use Illuminate\Support\Str;
use RuntimeException;
use Tests\Support\AdministrationCommittedFixtures;
use Tests\TestCase;

final class FirstActivationDeliveryTest extends TestCase
{
    use AdministrationCommittedFixtures;

    private User $candidate;

    protected function setUp(): void
    {
        parent::setUp();
        $this->requireAdministrationDatabase();
        $this->seed(CoreRolesSeeder::class);
        config(['sorerunapp.frontend_url' => 'http://localhost:3000']);
        $this->candidate = User::factory()->unverified()->create(['email' => 'first-delivery-'.Str::uuid().'@example.test']);
        $this->administrationActors[] = $this->candidate->id;
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

    private function issue(): NotificationDelivery
    {
        return NotificationDelivery::findOrFail(app(IssueFirstAccountActivation::class)->handle($this->candidate->id, $this->candidate->email, (string) Str::uuid()));
    }

    public function test_commit_dispatch_gap_recovers_only_id_job_on_the_bootstrap_queue(): void
    {
        $delivery = $this->issue();
        Queue::fake();
        Artisan::call('outbox:dispatch', ['--limit' => 1000]);
        Queue::assertPushed(DeliverOutboxNotification::class, fn ($job) => $job->deliveryId === $delivery->id && $job->queue === 'mail-bootstrap');
        app(DeliverNotification::class)->handle($delivery->id);
        self::assertSame('sent', $delivery->fresh()->status->value);
        self::assertNull($delivery->fresh()->payload_encrypted);
        Notification::assertSentTo($this->candidate, ActivateAdminAccount::class);
        self::assertNull($this->candidate->fresh()->email_verified_at);
        self::assertFalse($this->candidate->roles()->exists());
    }

    public function test_provider_failure_and_retry_keep_the_same_proof_without_row_lock(): void
    {
        $delivery = $this->issue();
        $proof = $delivery->payload_encrypted;
        $transaction = null;
        Notification::shouldReceive('sendNow')->once()->andReturnUsing(function () use (&$transaction): never {
            $transaction = DB::transactionLevel();
            throw new RuntimeException('Synthetic initial provider failure.');
        });
        app(DeliverNotification::class)->handle($delivery->id);
        self::assertSame(0, $transaction);
        self::assertSame('failed', $delivery->fresh()->status->value);
        self::assertSame($proof, $delivery->fresh()->payload_encrypted);
        self::assertSame(1, AdminInvitation::query()->where('user_id', $this->candidate->id)->count());
        $this->travel(61)->seconds();
        Notification::fake();
        app(DeliverNotification::class)->handle($delivery->id);
        self::assertSame('sent', $delivery->fresh()->status->value);
        self::assertSame(2, $delivery->fresh()->attempt_count);
        self::assertSame(hash('sha256', $proof['token']), AdminInvitation::findOrFail($proof['invitation_id'])->token_hash);
    }

    public function test_delayed_expired_delivery_is_cancelled_and_proof_erased(): void
    {
        $delivery = $this->issue();
        AdminInvitation::query()->where('user_id', $this->candidate->id)->update(['expires_at' => now('UTC')->subSecond()]);
        app(DeliverNotification::class)->handle($delivery->id);
        self::assertSame('cancelled', $delivery->fresh()->status->value);
        self::assertNull($delivery->fresh()->payload_encrypted);
        Notification::assertNothingSent();
    }
}
