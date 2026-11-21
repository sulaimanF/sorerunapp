<?php

declare(strict_types=1);

namespace App\Actions\Administration;

use App\Exceptions\ApiProblem;
use App\Models\AdminInvitation;
use App\Models\User;
use App\Services\Administration\CompleteAccountActivation;
use App\Services\Administration\FirstAccountActivation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use SensitiveParameter;

final class AcceptFirstAccountActivation
{
    public function handle(#[SensitiveParameter] array $data, string $requestId): void
    {
        $password = Hash::make($data['password']);
        $id = AdminInvitation::query()->whereKey($data['invitation_id'])->value('user_id');
        if ($id === null) {
            $this->invalid();
        }
        DB::transaction(function () use ($id, $data, $password, $requestId): void {
            $user = User::query()->whereKey($id)->lockForUpdate()->first();
            $fence = DB::table('security_bootstraps')->where('name', 'first_super_admin')->lockForUpdate()->first();
            $invitation = AdminInvitation::query()->whereKey($data['invitation_id'])->where('user_id', $id)->lockForUpdate()->first();
            $state = app(FirstAccountActivation::class);
            if ($user === null || $invitation === null || ! $state->fenceAvailable($fence, (int) $id)
                || ! $state->current($invitation, $user) || ! hash_equals($user->email, $data['email'])
                || ! hash_equals($invitation->token_hash, hash('sha256', $data['token']))) {
                $this->invalid();
            }
            app(CompleteAccountActivation::class)->handle($user, $invitation, $password, $requestId, 'security.first_activation_accepted');
        }, attempts: 3);
    }

    private function invalid(): never
    {
        throw new ApiProblem('FIRST_ACTIVATION_INVALID', 'Tautan aktivasi tidak valid, kedaluwarsa, sudah digunakan, atau tidak berlaku lagi. Hubungi operator untuk undangan baru.', 422);
    }
}
