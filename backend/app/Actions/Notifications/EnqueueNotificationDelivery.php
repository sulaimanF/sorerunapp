<?php

declare(strict_types=1);

namespace App\Actions\Notifications;

use App\Jobs\DeliverOutboxNotification;
use App\Models\NotificationDelivery;
use App\Services\Administration\FirstAccountActivation;
use App\Services\Notifications\LocalMailScope;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Support\Facades\Log;
use Throwable;

class EnqueueNotificationDelivery
{
    public function handle(int $id): bool
    {
        try {
            $scope = app(LocalMailScope::class);
            $queue = $scope->active() && ($row = NotificationDelivery::query()->find($id)) !== null && $scope->allows($row)
                ? LocalMailScope::QUEUE : 'mail';
            if (NotificationDelivery::query()->whereKey($id)->where('type', 'first_account_activation')->exists()) {
                $queue = FirstAccountActivation::QUEUE;
            }
            // Explicit connection prevents accidental synchronous SMTP in an HTTP request.
            app(Dispatcher::class)->dispatch((new DeliverOutboxNotification($id))
                ->onConnection('database')->onQueue($queue));

            return true;
        } catch (Throwable) {
            // The committed outbox remains recoverable by the scheduled sweep.
            Log::warning('Outbox enqueue deferred.', ['delivery_id' => $id, 'code' => 'QUEUE_DISPATCH_FAILED']);

            return false;
        }
    }
}
