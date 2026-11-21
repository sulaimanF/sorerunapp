<?php

declare(strict_types=1);

namespace App\Actions\Administration;

use App\Enums\AccountStatus;
use App\Enums\RoleCode;
use App\Exceptions\ApiProblem;
use App\Models\AdminInvitation;
use App\Models\EmailChangeRequest;
use App\Models\User;
use App\Services\Administration\AdminInvitationState;
use App\Services\Auth\EmailChangeState;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use SensitiveParameter;

final class ChangeAdministrativePassword
{
    public function handle(int $actorId, int $version, #[SensitiveParameter] array $data, string $requestId, ?int $targetId = null): void
    {
        $actor = User::query()->find($actorId);
        if ($actor === null || $actor->auth_version !== $version) {
            $this->expired();
        }
        $checkedHash = $actor->password;
        if (! Hash::check($data['current_password'], $checkedHash)) {
            throw new ApiProblem('CURRENT_PASSWORD_INVALID', 'Password akun Anda saat ini tidak sesuai.', 422, ['current_password' => ['Password akun Anda saat ini tidak sesuai.']]);
        }
        // Reauthentication and hashing happen before the short account locks.
        $passwordHash = Hash::make($data['password']);
        DB::transaction(function () use ($actorId, $version, $checkedHash, $passwordHash, $requestId, $targetId): void {
            $users = User::query()->whereIn('id', array_unique([$actorId, $targetId ?? $actorId]))->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $actor = $users->get($actorId);
            if ($actor === null || $actor->auth_version !== $version || ! hash_equals($checkedHash, $actor->password)) {
                $this->expired();
            }
            Gate::forUser($actor)->authorize($targetId === null ? 'changePassword' : 'provision', User::class);
            $subject = $users->get($targetId ?? $actorId);
            if ($subject === null) {
                throw new ApiProblem('NOT_FOUND', 'Akun Admin tidak ditemukan.', 404);
            }
            if ($targetId !== null && ($targetId === $actorId || $subject->status !== AccountStatus::Enabled
                || $subject->email_verified_at === null || AdminInvitationState::pending($subject)
                || ! $subject->roles()->where('code', RoleCode::Admin->value)->exists()
                || $subject->roles()->where('code', RoleCode::SuperAdmin->value)->exists())) {
                throw new ApiProblem('ADMIN_PASSWORD_NOT_ALLOWED', 'Password hanya dapat diganti untuk Admin aktif yang sudah diaktivasi, bukan Super Admin. Gunakan Profil untuk password sendiri.', 409);
            }
            $now = now('UTC');
            $subject->forceFill(['password' => $passwordHash, 'auth_version' => $subject->auth_version + 1, 'remember_token' => Str::random(60)])->save();
            DB::table('sessions')->where('user_id', $subject->id)->delete();
            DB::table(config('auth.passwords.users.table'))->where('email', $subject->email)->delete();
            AdminInvitation::query()->where('user_id', $subject->id)->whereNull('consumed_at')->whereNull('revoked_at')->update(['revoked_at' => $now]);
            EmailChangeRequest::query()->where('user_id', $subject->id)->whereNull('consumed_at')->whereNull('revoked_at')->update(['revoked_at' => $now]);
            $state = app(AdminInvitationState::class);
            $state->cancelDeliveries((int) $subject->id);
            app(EmailChangeState::class)->cancelDeliveries((int) $subject->id);
            DB::table('notification_deliveries')->where('user_id', $subject->id)->where('type', 'password_reset')
                ->whereIn('status', ['pending', 'processing', 'failed'])->update([
                    'status' => 'cancelled', 'lease_until' => null, 'payload_encrypted' => null, 'last_error_code' => 'PASSWORD_CHANGED',
                ]);
            $state->audit($actorId, (int) $subject->id, $targetId === null ? 'auth.password_changed' : 'admin.password_changed', $requestId, ['sessions_revoked' => true]);
        }, 3);
    }

    private function expired(): never
    {
        throw new ApiProblem('SESSION_EXPIRED', 'Sesi sudah berubah. Silakan masuk kembali.', 401);
    }
}
