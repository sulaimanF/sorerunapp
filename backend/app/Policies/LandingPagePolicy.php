<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;
use App\Services\Administration\AdministrativePermissions;

class LandingPagePolicy
{
    public function manage(User $user): bool
    {
        return AdministrativePermissions::manage($user);
    }
}
