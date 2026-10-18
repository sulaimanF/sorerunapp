<?php

declare(strict_types=1);

namespace App\Data\Notifications;

final readonly class DeliveryClaim
{
    public function __construct(public int $id, public int $attempt, public string $leaseUntil) {}
}
