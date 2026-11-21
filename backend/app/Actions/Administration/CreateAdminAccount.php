<?php

declare(strict_types=1);

namespace App\Actions\Administration;

use App\Enums\AccountStatus;
use App\Enums\RoleCode;
use App\Exceptions\ApiProblem;
use App\Models\AdminProvisioning;
use App\Models\Role;
use App\Models\User;
use App\Services\Administration\AdminInvitationState;
use App\Services\Administration\ProvisioningAccess;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

final class CreateAdminAccount
{
    public function handle(int $actorId, int $version, array $data, string $requestId): array
    {
        $hash = hash('sha256', json_encode([$data['full_name'], $data['email']], JSON_THROW_ON_ERROR));
        // A different random value for every account, discarded and never communicated.
        $initialPassword = Hash::make(bin2hex(random_bytes(32)));
        try {
            return DB::transaction(function () use ($actorId, $version, $data, $hash, $initialPassword, $requestId): array {
                app(ProvisioningAccess::class)->lock($actorId, $version);
                // The creator lock serializes this key. Discover immutable target identity first,
                // then lock its user before provisioning, matching resend/accept lock order.
                $targetId = AdminProvisioning::query()->where('created_by', $actorId)->where('request_key', $data['request_key'])->value('user_id');
                $target = $targetId === null ? null : User::query()->whereKey($targetId)->lockForUpdate()->firstOrFail();
                $existing = AdminProvisioning::query()->where('created_by', $actorId)->where('request_key', $data['request_key'])->lockForUpdate()->first();
                if ($existing !== null) {
                    if (! hash_equals($existing->payload_hash, $hash)) {
                        throw new ApiProblem('IDEMPOTENCY_CONFLICT', 'Kunci permintaan sudah dipakai untuk data berbeda. Periksa hasil sebelumnya.', 409);
                    }
                    if ($target === null || (int) $existing->user_id !== (int) $target->id) {
                        throw new ApiProblem('PROVISIONING_STATE_CONFLICT', 'Status permintaan perlu diperiksa sebelum mencoba kembali.', 409);
                    }

                    if ($target->status === AccountStatus::Blocked && ! $target->roles()->exists()) {
                        throw new ApiProblem('ADMIN_ACCOUNT_DELETED', 'Akun dari permintaan ini sudah dihapus. Akun tidak dibuat ulang dan email tetap dicadangkan.', 409);
                    }

                    return app(AdminInvitationState::class)->snapshot($target);
                }
                if (User::query()->where('email', $data['email'])->exists()) {
                    $this->emailConflict();
                }
                $role = Role::query()->where('code', RoleCode::Admin->value)->first();
                if ($role === null) {
                    throw new ApiProblem('PROVISIONING_UNAVAILABLE', 'Konfigurasi akun administratif belum tersedia.', 503);
                }
                $user = User::query()->create(['name' => $data['full_name'], 'email' => $data['email'], 'password' => $initialPassword]);
                $user->roles()->attach($role->id, ['assigned_by' => $actorId, 'assigned_at' => now('UTC')]);
                (new AdminProvisioning)->forceFill([
                    'user_id' => $user->id, 'created_by' => $actorId, 'request_key' => $data['request_key'], 'payload_hash' => $hash,
                ])->save();
                $state = app(AdminInvitationState::class);
                $state->audit($actorId, (int) $user->id, 'admin.account_created', $requestId, ['role' => 'admin', 'activation' => 'pending']);
                $state->issue($user, $actorId, $requestId);

                return $state->snapshot($user);
            }, attempts: 3);
        } catch (UniqueConstraintViolationException $exception) {
            if ((int) ($exception->errorInfo[1] ?? 0) === 1062
                && str_contains((string) ($exception->errorInfo[2] ?? ''), 'users_email_unique')) {
                $this->emailConflict();
            }
            throw $exception;
        }
    }

    private function emailConflict(): never
    {
        throw new ApiProblem('EMAIL_UNAVAILABLE', 'Email sudah digunakan. Akun existing tidak diubah.', 409, ['email' => ['Gunakan alamat email lain.']]);
    }
}
