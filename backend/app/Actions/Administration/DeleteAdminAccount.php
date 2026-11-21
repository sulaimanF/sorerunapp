<?php

declare(strict_types=1);

namespace App\Actions\Administration;

use App\Enums\AccountStatus;
use App\Enums\RoleCode;
use App\Exceptions\ApiProblem;
use App\Models\AdminInvitation;
use App\Services\Administration\AdminInvitationState;
use App\Services\Administration\ProvisioningAccess;
use Illuminate\Support\Facades\DB;

final class DeleteAdminAccount
{
    public function handle(int $actorId, int $version, int $targetId, string $requestId): void
    {
        DB::transaction(function () use ($actorId, $version, $targetId, $requestId): void {
            $target = app(ProvisioningAccess::class)->lock($actorId, $version, $targetId);
            if ($actorId === $targetId) {
                $this->unavailable();
            }
            if ($target->status === AccountStatus::Blocked && ! $target->roles()->exists()
                && DB::table('audit_logs')->where('action', 'admin.account_deleted')->where('target_type', 'user')->where('target_id', $targetId)->exists()) {
                return; // An uncertain DELETE can be retried without a second audit/version change.
            }
            if (! $target->roles()->where('code', RoleCode::Admin->value)->exists()
                || $target->roles()->where('code', '!=', RoleCode::Admin->value)->exists()
                || $target->membership()->exists() || $target->memberProfile()->exists()) {
                $this->unavailable();
            }
            $now = now('UTC');
            $target->forceFill([
                'status' => AccountStatus::Blocked, 'blocked_at' => $target->blocked_at ?? $now,
                'auth_version' => $target->auth_version + 1, 'remember_token' => null,
            ])->save();
            $target->roles()->detach();
            AdminInvitation::query()->where('user_id', $targetId)->whereNull('consumed_at')->whereNull('revoked_at')->update(['revoked_at' => $now]);
            DB::table('email_change_requests')->where('user_id', $targetId)->whereNull('consumed_at')->whereNull('revoked_at')->update(['revoked_at' => $now]);
            DB::table('password_reset_tokens')->where('email', $target->email)->delete();
            DB::table('notification_deliveries')->where('user_id', $targetId)->whereIn('status', ['pending', 'processing', 'failed'])
                ->update(['status' => 'cancelled', 'lease_until' => null, 'payload_encrypted' => null, 'last_error_code' => 'ADMIN_ACCOUNT_DELETED']);
            DB::table('sessions')->where('user_id', $targetId)->delete();
            app(AdminInvitationState::class)->audit($actorId, $targetId, 'admin.account_deleted', $requestId, ['deletion' => 'logical', 'status' => 'blocked', 'roles' => []]);
        }, 3);
    }

    private function unavailable(): never
    {
        throw new ApiProblem('ADMIN_DELETE_NOT_ALLOWED', 'Hanya akun Admin operasional tanpa role lain, profil anggota, atau membership yang dapat dihapus. Akun sendiri dan Super Admin dilindungi.', 409);
    }
}
