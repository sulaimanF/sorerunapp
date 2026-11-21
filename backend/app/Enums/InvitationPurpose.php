<?php

declare(strict_types=1);

namespace App\Enums;

enum InvitationPurpose: string
{
    case Admin = 'admin_invitation';
    case FirstAccount = 'first_account_activation';
}
