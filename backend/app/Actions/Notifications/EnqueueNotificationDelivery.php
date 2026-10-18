<?php

declare(strict_types=1);

namespace App\Actions\Notifications;

use App\Jobs\DeliverOutboxNotification;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Support\Facades\Log;
use Throwable;

class EnqueueNotificationDelivery
{
    public function handle(int $id): bool
    {
        try {
            // Explicit connection prevents accidental synchronous SMTP in an HTTP request.
            app(Dispatcher::class)->dispatch((new DeliverOutboxNotification($id))
                ->onConnection('database')->onQueue('mail'));

            return true;
        } catch (Throwable) {
            // The committed outbox remains recoverable by the scheduled sweep.
            Log::warning('Outbox enqueue deferred.', ['delivery_id' => $id, 'code' => 'QUEUE_DISPATCH_FAILED']);

            return false;
        }
    }
}
