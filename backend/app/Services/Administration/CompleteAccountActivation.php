<?php

declare(strict_types=1);

namespace App\Services\Administration;

use App\Models\AdminInvitation;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use SensitiveParameter;

final class CompleteAccountActivation
{
    /** Caller has validated purpose/eligibility and holds the user and invitation locks. */
    public function handle(User $user, AdminInvitation $invitation, #[SensitiveParameter] string $passwordHash, string $requestId, string $auditAction): void
    {
        $now = now('UTC');
        $user->forceFill([
            'password' => $passwordHash, 'email_verified_at' => $now,
            'auth_version' => $user->auth_version + 1, 'remember_token' => Str::random(60),
        ])->save();
        $invitation->forceFill(['consumed_at' => $now])->save();
        AdminInvitation::query()->where('user_id', $user->id)->whereNull('consumed_at')->whereNull('revoked_at')->update(['revoked_at' => $now]);
        DB::table('sessions')->where('user_id', $user->id)->delete();
        DB::table(config('auth.passwords.users.table'))->where('email', $user->email)->delete();
        DB::table('notification_deliveries')->where('user_id', $user->id)->where('type', 'password_reset')
            ->whereIn('status', ['pending', 'processing', 'failed'])->update([
                'status' => 'cancelled', 'lease_until' => null, 'payload_encrypted' => null, 'last_error_code' => 'ACCOUNT_ACTIVATED',
            ]);
        $state = app(AdminInvitationState::class);
        $state->cancelDeliveries((int) $user->id);
        // Proof identifies the subject, never an authenticated administrative actor.
        $state->audit(null, (int) $user->id, $auditAction, $requestId, ['email_verified' => true, 'sessions_revoked' => true]);
    }
}
