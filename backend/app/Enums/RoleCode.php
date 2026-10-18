<?php

declare(strict_types=1);

namespace App\Enums;

enum RoleCode: string
{
    case Member = 'member';
    case Staff = 'staff';
    case Admin = 'admin';
}
