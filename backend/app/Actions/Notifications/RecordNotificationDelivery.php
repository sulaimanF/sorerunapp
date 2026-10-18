<?php

declare(strict_types=1);

namespace App\Actions\Notifications;

use App\Enums\NotificationDeliveryStatus;
use App\Models\NotificationDelivery;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class RecordNotificationDelivery
{
    public function handle(User $user, string $type, string $key, CarbonImmutable $availableAt): NotificationDelivery
    {
        $delivery = new NotificationDelivery;
        $delivery->forceFill([
            'type' => $type, 'dedupe_key' => $key,
            'status' => NotificationDeliveryStatus::Pending,
            'attempt_count' => 0, 'available_at' => $availableAt,
        ]);
        $user->notificationDeliveries()->save($delivery);
        if (config('sorerunapp.outbox.dispatch_on_commit')) {
            $id = (int) $delivery->id;
            DB::afterCommit(static fn () => app(EnqueueNotificationDelivery::class)->handle($id));
        }

        return $delivery;
    }
}
