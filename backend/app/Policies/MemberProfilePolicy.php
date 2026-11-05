<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\AccountStatus;
use App\Models\MemberProfile;
use App\Models\User;

class MemberProfilePolicy
{
    public function view(User $user, MemberProfile $profile): bool
    {
        return $user->status === AccountStatus::Enabled && $user->email_verified_at !== null && (int) $profile->user_id === (int) $user->id;
    }

    public function update(User $user, MemberProfile $profile): bool
    {
        return $this->view($user, $profile);
    }
}
