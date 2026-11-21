<?php

declare(strict_types=1);

namespace App\Actions\Administration;

use App\Enums\AccountStatus;
use App\Enums\RoleCode;
use App\Exceptions\ApiProblem;
use App\Models\Role;
use App\Models\User;
use App\Services\Administration\AdminInvitationState;
use Illuminate\Support\Facades\DB;

final class BootstrapSuperAdmin
{
    public function handle(int $userId, string $email, string $requestId): void
    {
        DB::transaction(function () use ($userId, $email, $requestId): void {
            $user = User::query()->whereKey($userId)->lockForUpdate()->first();
            if ($user === null || ! hash_equals($user->email, $email) || $user->status !== AccountStatus::Enabled
                || $user->email_verified_at === null || AdminInvitationState::pending($user)) {
                throw new ApiProblem('BOOTSTRAP_TARGET_INVALID', 'ID/email harus cocok dengan akun enabled yang sudah terverifikasi dan aktif.', 409);
            }
            // Persistent singleton fence serializes different targets and remains after role removal.
            $fence = DB::table('security_bootstraps')->where('name', 'first_super_admin')->lockForUpdate()->first();
            $role = Role::query()->where('code', RoleCode::SuperAdmin->value)->first();
            if ($fence === null || $role === null) {
                throw new ApiProblem('BOOTSTRAP_UNAVAILABLE', 'Migration administratif belum lengkap.', 503);
            }
            if ($fence->completed_at !== null || DB::table('user_roles')->where('role_id', $role->id)->lockForUpdate()->first() !== null) {
                throw new ApiProblem('BOOTSTRAP_ALREADY_COMPLETED', 'Bootstrap Super Admin sudah pernah dilakukan; tidak ada perubahan.', 409);
            }
            if ($fence->user_id !== null && (int) $fence->user_id !== $userId) {
                throw new ApiProblem('BOOTSTRAP_TARGET_INVALID', 'Bootstrap telah diikat ke akun aktivasi awal yang berbeda.', 409);
            }
            $user->roles()->attach($role->id, ['assigned_by' => null, 'assigned_at' => now('UTC')]);
            $user->forceFill(['auth_version' => $user->auth_version + 1])->save();
            DB::table('sessions')->where('user_id', $user->id)->delete();
            DB::table('security_bootstraps')->where('name', 'first_super_admin')->update(['user_id' => $user->id, 'completed_at' => now('UTC')]);
            app(AdminInvitationState::class)->audit(null, (int) $user->id, 'security.super_admin_bootstrapped', $requestId, ['role_added' => 'super_admin', 'sessions_revoked' => true]);
        }, attempts: 3);
    }
}
