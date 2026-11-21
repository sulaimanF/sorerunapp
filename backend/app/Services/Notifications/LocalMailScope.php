<?php

declare(strict_types=1);

namespace App\Services\Notifications;

use App\Enums\InvitationPurpose;
use App\Jobs\DeliverOutboxNotification;
use App\Models\AdminInvitation;
use App\Models\EmailChangeRequest;
use App\Models\NotificationDelivery;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class LocalMailScope
{
    public const QUEUE = 'mail-local';

    public function active(): bool
    {
        return app()->environment('local');
    }

    public function constrain(Builder $query): void
    {
        $query->where(function (Builder $approved): void {
            $approved->where(function (Builder $synthetic): void {
                $this->constrainSynthetic($synthetic);
            })->orWhere(function (Builder $first): void {
                $first->where('type', InvitationPurpose::FirstAccount->value)
                    ->whereExists(fn ($fence) => $fence->selectRaw('1')->from('security_bootstraps')
                        ->where('name', 'first_super_admin')->whereColumn('user_id', 'notification_deliveries.user_id'));
            });
        });
    }

    private function constrainSynthetic(Builder $query): void
    {
        $query->whereHas('user', fn (Builder $users) => $users->where('email', 'like', '%@example.test'))
            ->whereIn('type', ['email_verification', 'membership_activated', 'password_reset', 'email_change', 'admin_invitation'])
            ->where(function (Builder $types): void {
                $types->where('type', '!=', 'email_change')->orWhereExists(function ($changes): void {
                    $changes->selectRaw('1')->from('email_change_requests as c')
                        ->whereColumn('c.user_id', 'notification_deliveries.user_id')
                        ->whereRaw("CONCAT('email_change:', c.id) = notification_deliveries.dedupe_key")
                        ->where('c.new_email', 'like', '%@example.test');
                });
            });
    }

    public function allows(NotificationDelivery $delivery): bool
    {
        if ($delivery->type === InvitationPurpose::FirstAccount->value) {
            // Only a CLI-issued proof bound to the bootstrap fence can address a non-synthetic mailbox.
            // Transport remains loopback Mailpit; this exception never applies to other notification types.
            $prefix = InvitationPurpose::FirstAccount->value.':';
            if (! str_starts_with($delivery->dedupe_key, $prefix)) {
                return false;
            }

            return AdminInvitation::query()->whereKey(substr($delivery->dedupe_key, strlen($prefix)))
                ->where('purpose', InvitationPurpose::FirstAccount->value)->whereNull('created_by')
                ->where('user_id', $delivery->user_id)->where('email', $delivery->user?->email)
                ->whereExists(fn ($fence) => $fence->selectRaw('1')->from('security_bootstraps')
                    ->where('name', 'first_super_admin')->whereColumn('user_id', 'admin_invitations.user_id'))->exists();
        }
        if (! str_ends_with((string) $delivery->user?->email, '@example.test')
            || ! in_array($delivery->type, ['email_verification', 'membership_activated', 'password_reset', 'email_change', 'admin_invitation'], true)) {
            return false;
        }
        if ($delivery->type !== 'email_change' || in_array($delivery->status->value, ['sent', 'cancelled'], true)) {
            return true;
        }
        $change = EmailChangeRequest::query()->where('user_id', $delivery->user_id)
            ->whereKey($delivery->payload_encrypted['request_id'] ?? null)->first();

        return $change !== null && str_ends_with($change->new_email, '@example.test');
    }

    public function assertTransport(): void
    {
        if (config('mail.default') !== 'smtp' || config('mail.mailers.smtp.host') !== '127.0.0.1'
            || (int) config('mail.mailers.smtp.port') !== 1025 || config('mail.mailers.smtp.url') !== null) {
            throw new RuntimeException('Local background requires SMTP Mailpit at 127.0.0.1:1025 without MAIL_URL.');
        }
    }

    public function assertReady(): void
    {
        if (! $this->active() || app()->configurationIsCached() || config('database.default') !== 'mysql'
            || config('queue.default') !== 'database' || config('cache.default') !== 'database'
            || config('queue.connections.database.driver') !== 'database' || config('queue.connections.database.table') !== 'jobs'
            || (int) config('queue.connections.database.retry_after') < 90) {
            throw new RuntimeException('Requires uncached local environment with MySQL/database queue/cache.');
        }
        $this->assertTransport();
        foreach ([config('database.default'), config('queue.connections.database.connection'), config('cache.stores.database.connection')] as $name) {
            $connection = DB::connection($name);
            $actual = $connection->selectOne('SELECT DATABASE() AS name, VERSION() AS version');
            if ($connection->getDriverName() !== 'mysql' || ! in_array($connection->getConfig('host'), ['localhost', '127.0.0.1'], true)
                || $actual->name !== 'sorerunapp' || ! str_starts_with($actual->version, '8.4.')) {
                throw new RuntimeException('Requires local development sorerunapp on MySQL 8.4; test/remote databases are refused.');
            }
        }
        foreach (['app.url', 'sorerunapp.frontend_url'] as $key) {
            $url = parse_url((string) config($key));
            if (! is_array($url) || ! in_array($url['host'] ?? '', ['localhost', '127.0.0.1'], true)) {
                throw new RuntimeException('Application and frontend URLs must be loopback development origins.');
            }
        }
        // Never drain an unknown job, including one manually placed on the local queue.
        foreach (DB::table('jobs')->where('queue', self::QUEUE)->get(['payload']) as $row) {
            $payload = json_decode($row->payload, true, flags: JSON_THROW_ON_ERROR);
            if (($payload['displayName'] ?? null) !== DeliverOutboxNotification::class) {
                throw new RuntimeException('Unexpected job on mail-local; inspect without deleting it.');
            }
            $job = unserialize($payload['data']['command'], ['allowed_classes' => [DeliverOutboxNotification::class]]);
            $delivery = $job instanceof DeliverOutboxNotification ? NotificationDelivery::query()->find($job->deliveryId) : null;
            if ($delivery === null || ! $this->allows($delivery)) {
                throw new RuntimeException('Unapproved recipient on mail-local; existing jobs have not been modified.');
            }
        }
    }
}
