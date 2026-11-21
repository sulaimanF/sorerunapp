<?php

declare(strict_types=1);

namespace App\Services\Administration;

use App\Actions\Notifications\RecordNotificationDelivery;
use App\Enums\AccountStatus;
use App\Enums\InvitationPurpose;
use App\Enums\RoleCode;
use App\Exceptions\ApiProblem;
use App\Models\AdminInvitation;
use App\Models\AdminProvisioning;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class AdminInvitationState
{
    public const EXPIRY_HOURS = 24;

    public const COOLDOWN_SECONDS = 60;

    public static function pending(User $user): bool
    {
        return AdminProvisioning::query()->where('user_id', $user->id)->whereNull('activated_at')->exists();
    }

    public function targetEligible(User $user): bool
    {
        return $user->status === AccountStatus::Enabled && $user->email_verified_at === null
            && $user->roles()->where('code', RoleCode::Admin->value)->exists()
            && ! $user->roles()->where('code', RoleCode::SuperAdmin->value)->exists();
    }

    /** Caller holds the target user and provisioning locks. */
    public function issue(User $user, ?int $actorId, string $requestId, InvitationPurpose $purpose = InvitationPurpose::Admin): void
    {
        $now = now('UTC');
        AdminInvitation::query()->where('user_id', $user->id)->where('purpose', $purpose->value)->whereNull('consumed_at')->whereNull('revoked_at')->update(['revoked_at' => $now]);
        $this->cancelDeliveries((int) $user->id);
        $token = bin2hex(random_bytes(32));
        $invitation = new AdminInvitation;
        $invitation->forceFill([
            'id' => (string) Str::uuid(), 'purpose' => $purpose, 'user_id' => $user->id, 'created_by' => $actorId,
            'email' => $user->email, 'token_hash' => hash('sha256', $token),
            'user_version' => $user->auth_version, 'expires_at' => $now->copy()->addHours(self::EXPIRY_HOURS),
        ])->save();
        $delivery = app(RecordNotificationDelivery::class)->handle($user, $purpose->value, $purpose->value.':'.$invitation->id, $now->toImmutable());
        $delivery->forceFill(['payload_encrypted' => ['invitation_id' => $invitation->id, 'token' => $token]])->save();
        $this->audit($actorId, (int) $user->id, $purpose === InvitationPurpose::Admin ? 'admin.invitation_issued' : 'security.first_activation_issued', $requestId);
    }

    public function snapshot(User $user): array
    {
        $provisioning = AdminProvisioning::query()->where('user_id', $user->id)->lockForUpdate()->first();
        if ($provisioning === null) {
            throw new ApiProblem('NOT_FOUND', 'Data undangan tidak ditemukan.', 404);
        }
        $invitation = AdminInvitation::query()->where('user_id', $user->id)->where('purpose', InvitationPurpose::Admin->value)->latest('created_at')->orderByDesc('id')->lockForUpdate()->first();
        $retry = $invitation === null ? 0 : max(0, (int) ceil(now('UTC')->diffInSeconds($invitation->created_at->copy()->addSeconds(self::COOLDOWN_SECONDS), false)));
        $status = match (true) {
            $provisioning->activated_at !== null => 'active',
            $invitation === null || $invitation->revoked_at !== null => 'revoked',
            $invitation->user_version !== $user->auth_version || ! hash_equals($invitation->email, $user->email) || ! $this->targetEligible($user) => 'stale',
            $invitation->expires_at->lessThanOrEqualTo(now('UTC')) => 'expired',
            default => 'pending',
        };

        return [
            'user_id' => (int) $user->id, 'full_name' => $user->name, 'email' => $user->email,
            'role' => 'admin', 'status' => $status, 'expires_at' => $invitation?->expires_at->utc()->toIso8601ZuluString(),
            'retry_after' => $retry, 'activated_at' => $provisioning->activated_at?->utc()->toIso8601ZuluString(),
        ];
    }

    public function cancelDeliveries(int $userId): void
    {
        DB::table('notification_deliveries')->where('user_id', $userId)->whereIn('type', [InvitationPurpose::Admin->value, InvitationPurpose::FirstAccount->value])
            ->whereIn('status', ['pending', 'processing', 'failed'])->update([
                'status' => 'cancelled', 'lease_until' => null, 'payload_encrypted' => null,
                'last_error_code' => 'ADMIN_INVITATION_NOT_CURRENT',
            ]);
    }

    public function audit(?int $actorId, int $targetId, string $action, string $requestId, array $after = []): void
    {
        (new AuditLog)->forceFill([
            'actor_user_id' => $actorId, 'action' => $action, 'target_type' => 'user', 'target_id' => $targetId,
            'occurred_at' => now('UTC'), 'request_id' => $requestId, 'after' => $after,
        ])->save();
    }
}
