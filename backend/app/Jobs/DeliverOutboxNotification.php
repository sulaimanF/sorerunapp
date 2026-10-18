<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\Notifications\DeliverNotification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class DeliverOutboxNotification implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 60;

    public function __construct(public readonly int $deliveryId) {}

    public function handle(DeliverNotification $delivery): void
    {
        $delivery->handle($this->deliveryId);
    }
}
