<?php

declare(strict_types=1);

namespace App\Enums;

enum AccountStatus: string
{
    case Enabled = 'enabled';
    case Blocked = 'blocked';
}
