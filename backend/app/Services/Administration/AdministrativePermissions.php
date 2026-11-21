<?php

declare(strict_types=1);

namespace App\Services\Administration;

use App\Enums\AccountStatus;
use App\Enums\RoleCode;
use App\Models\User;

final class AdministrativePermissions
{
    public static function manage(User $user): bool
    {
        return self::eligible($user) && $user->roles()->whereIn('code', [RoleCode::Admin->value, RoleCode::SuperAdmin->value])->exists();
    }

    public static function provision(User $user): bool
    {
        return self::eligible($user) && $user->roles()->where('code', RoleCode::SuperAdmin->value)->exists();
    }

    public static function changeOwnPassword(User $user): bool
    {
        return self::eligible($user) && $user->roles()->whereIn('code', [RoleCode::Member->value, RoleCode::Admin->value, RoleCode::SuperAdmin->value])->exists();
    }

    private static function eligible(User $user): bool
    {
        return $user->status === AccountStatus::Enabled && $user->email_verified_at !== null
            && ! AdminInvitationState::pending($user);
    }
}
