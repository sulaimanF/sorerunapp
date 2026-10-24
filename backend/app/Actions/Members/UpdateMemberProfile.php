<?php

declare(strict_types=1);

namespace App\Actions\Members;

use App\Actions\Memberships\ActivatePendingMembership;
use App\Data\Members\UpdateMemberProfileData;
use App\Enums\AccountStatus;
use App\Enums\MembershipStatus;
use App\Exceptions\ApiProblem;
use App\Models\AuditLog;
use App\Models\MemberProfile;
use App\Models\Membership;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class UpdateMemberProfile
{
    public function __construct(private readonly ActivatePendingMembership $activation) {}

    public function handle(int $actorId, int $sessionVersion, UpdateMemberProfileData $data, string $requestId): MemberProfile
    {
        return DB::transaction(function () use ($actorId, $sessionVersion, $data, $requestId): MemberProfile {
            // All profile/activation writers serialize on membership -> user.
            $membership = Membership::query()->where('user_id', $actorId)->lockForUpdate()->first();
            $user = User::query()->whereKey($actorId)->lockForUpdate()->first();
            if ($user === null || $user->status !== AccountStatus::Enabled) {
                throw new ApiProblem('ACCOUNT_BLOCKED', 'Akun tidak dapat digunakan.', 403);
            }
            if ($user->auth_version !== $sessionVersion) {
                throw new ApiProblem('SESSION_EXPIRED', 'Sesi sudah berakhir. Silakan masuk kembali.', 401);
            }
            $profile = $user->memberProfile()->lockForUpdate()->first();
            if ($profile === null) {
                throw new ApiProblem('PROFILE_NOT_FOUND', 'Profil belum tersedia. Hubungi pengurus komunitas.', 404);
            }
            Gate::forUser($user)->authorize('update', $profile);
            $profile->fill($data->changes);
            $changed = array_keys($profile->getDirty());
            $now = CarbonImmutable::now('UTC');
            if ($changed !== []) {
                if ($profile->isDirty('full_name')) {
                    $user->name = $profile->full_name;
                    $user->save();
                }
                $profile->save();
                // Record only field names: no previous/new biodata, email, or credential.
                (new AuditLog)->forceFill([
                    'actor_user_id' => $actorId, 'action' => 'member.profile_updated',
                    'target_type' => 'member_profile', 'target_id' => $profile->id,
                    'occurred_at' => $now, 'request_id' => $requestId,
                    'after' => ['changed_fields' => $changed],
                ])->save();
            }
            $user->setRelation('memberProfile', $profile);
            if ($membership?->status === MembershipStatus::PendingVerification && $user->email_verified_at !== null) {
                $this->activation->handleLocked($user, $membership, $requestId, $now);
            }

            return $profile;
        }, attempts: 3);
    }
}
