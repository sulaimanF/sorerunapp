<?php

declare(strict_types=1);

namespace App\Enums;

enum MembershipStatus: string
{
    case PendingVerification = 'pending_verification';
    case Active = 'active';
    case Suspended = 'suspended';
    case Ended = 'ended';
}
