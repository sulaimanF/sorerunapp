<?php

declare(strict_types=1);

namespace App\Actions\Auth;

use App\Exceptions\ApiProblem;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use SensitiveParameter;

class ResetMemberPassword
{
    public function handle(#[SensitiveParameter] array $credentials, string $requestId): void
    {
        DB::transaction(function () use ($credentials, $requestId): void {
            // All token issuance/consumption for this account serializes on the same user row.
            $user = User::query()->where('email', $credentials['email'])->lockForUpdate()->first();
            $record = DB::table(config('auth.passwords.users.table'))->where('email', $credentials['email'])->lockForUpdate()->first();
            if ($user === null || $record === null || (int) $record->user_id !== (int) $user->id
                || (int) $record->auth_version !== $user->auth_version) {
                $this->invalidToken();
            }
            $status = Password::broker('users')->reset($credentials, function (User $subject, #[SensitiveParameter] string $password) use ($requestId): void {
                $subject->forceFill([
                    'password' => $password, 'remember_token' => Str::random(60), 'auth_version' => $subject->auth_version + 1,
                ])->save();
                DB::table('sessions')->where('user_id', $subject->id)->delete();
                DB::table('notification_deliveries')->where('user_id', $subject->id)->where('type', 'password_reset')
                    ->whereIn('status', ['pending', 'processing', 'failed'])->update([
                        'status' => 'cancelled', 'payload_encrypted' => null, 'lease_until' => null,
                        'last_error_code' => 'PASSWORD_RESET_CONSUMED',
                    ]);
                $audit = new AuditLog;
                $audit->forceFill([
                    'actor_user_id' => null, 'action' => 'auth.password_reset', 'target_type' => 'user',
                    'target_id' => $subject->id, 'occurred_at' => now('UTC'), 'request_id' => $requestId,
                    'after' => ['sessions_revoked' => true],
                ])->save();
            });
            if ($status !== Password::PASSWORD_RESET) {
                $this->invalidToken();
            }
            // The broker deletes its token after the callback, within this transaction.
        }, attempts: 3);
    }

    private function invalidToken(): never
    {
        throw new ApiProblem('PASSWORD_RESET_INVALID', 'Tautan reset tidak valid, kedaluwarsa, atau sudah digunakan. Minta tautan reset baru.', 422);
    }
}
