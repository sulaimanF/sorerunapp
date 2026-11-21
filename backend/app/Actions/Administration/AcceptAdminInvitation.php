<?php

declare(strict_types=1);

namespace App\Actions\Administration;

use App\Enums\InvitationPurpose;
use App\Exceptions\ApiProblem;
use App\Models\AdminInvitation;
use App\Models\AdminProvisioning;
use App\Models\User;
use App\Services\Administration\AdminInvitationState;
use App\Services\Administration\CompleteAccountActivation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use SensitiveParameter;

final class AcceptAdminInvitation
{
    public function handle(#[SensitiveParameter] array $data, string $requestId): void
    {
        // Hashing does not extend the short critical section.
        $password = Hash::make($data['password']);
        $id = AdminInvitation::query()->whereKey($data['invitation_id'])->value('user_id');
        if ($id === null) {
            $this->invalid();
        }
        DB::transaction(function () use ($id, $data, $password, $requestId): void {
            $user = User::query()->whereKey($id)->lockForUpdate()->first();
            $record = AdminProvisioning::query()->where('user_id', $id)->lockForUpdate()->first();
            $invitation = AdminInvitation::query()->whereKey($data['invitation_id'])->where('user_id', $id)->lockForUpdate()->first();
            $state = app(AdminInvitationState::class);
            if ($user === null || $record === null || $record->activated_at !== null || $invitation === null || $invitation->purpose !== InvitationPurpose::Admin
                || ! $state->targetEligible($user) || $invitation->consumed_at !== null || $invitation->revoked_at !== null
                || $invitation->expires_at->lessThanOrEqualTo(now('UTC')) || $invitation->user_version !== $user->auth_version
                || ! hash_equals($user->email, $data['email']) || ! hash_equals($invitation->email, $user->email)
                || ! hash_equals($invitation->token_hash, hash('sha256', $data['token']))) {
                $this->invalid();
            }
            $record->forceFill(['activated_at' => now('UTC')])->save();
            app(CompleteAccountActivation::class)->handle($user, $invitation, $password, $requestId, 'admin.invitation_accepted');
        }, attempts: 3);
    }

    private function invalid(): never
    {
        throw new ApiProblem('ADMIN_INVITATION_INVALID', 'Undangan tidak valid, kedaluwarsa, sudah digunakan, atau tidak berlaku lagi. Hubungi Super Admin untuk undangan baru.', 422);
    }
}
