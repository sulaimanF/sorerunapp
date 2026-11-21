<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;
use App\Services\Administration\AdministrativePermissions;

final class AdminAccountPolicy
{
    public function provision(User $user): bool
    {
        return AdministrativePermissions::provision($user);
    }

    public function changePassword(User $user): bool
    {
        return AdministrativePermissions::changeOwnPassword($user);
    }
}
