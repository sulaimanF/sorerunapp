<?php

declare(strict_types=1);

namespace App\Actions\Notifications;

use App\Models\User;
use Carbon\CarbonImmutable;

class RecordEmailVerificationDelivery
{
    public function handle(User $user, CarbonImmutable $availableAt): void
    {
        app(RecordNotificationDelivery::class)->handle($user, 'email_verification', 'registration_verification:'.$user->id, $availableAt);
    }
}
