<?php

declare(strict_types=1);

namespace App\Services\Administration;

use App\Enums\AccountStatus;
use App\Enums\InvitationPurpose;
use App\Models\AdminInvitation;
use App\Models\AdminProvisioning;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final class FirstAccountActivation
{
    public const QUEUE = 'mail-bootstrap';

    public function eligible(User $user): bool
    {
        return $user->status === AccountStatus::Enabled && $user->email_verified_at === null
            && ! $user->roles()->exists() && ! $user->membership()->exists()
            && ! $user->memberProfile()->exists() && ! AdminProvisioning::query()->where('user_id', $user->id)->exists();
    }

    /** Issuance/acceptance callers lock the user before this singleton fence. */
    public function fenceAvailable(?object $fence, int $userId): bool
    {
        return $fence !== null && $fence->completed_at === null
            && ($fence->user_id === null || (int) $fence->user_id === $userId)
            && ! DB::table('user_roles')->whereIn('role_id', DB::table('roles')->where('code', 'super_admin')->select('id'))->exists();
    }

    public function current(AdminInvitation $invitation, User $user): bool
    {
        $fence = DB::table('security_bootstraps')->where('name', 'first_super_admin')->first();

        return $invitation->purpose === InvitationPurpose::FirstAccount && $invitation->created_by === null
            && $fence !== null && (int) $fence->user_id === (int) $user->id && $this->fenceAvailable($fence, (int) $user->id)
            && $this->eligible($user) && $invitation->consumed_at === null && $invitation->revoked_at === null
            && $invitation->expires_at->greaterThan(now('UTC')) && $invitation->user_version === $user->auth_version
            && hash_equals($user->email, $invitation->email);
    }
}
