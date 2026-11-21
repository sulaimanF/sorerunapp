<?php

declare(strict_types=1);

namespace App\Actions\Auth;

use App\Exceptions\ApiProblem;
use App\Models\AuditLog;
use App\Models\EmailChangeRequest;
use App\Models\User;
use App\Services\Auth\EmailChangeState;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use SensitiveParameter;

class ConfirmEmailChange
{
    public function handle(int $actorId, int $sessionVersion, #[SensitiveParameter] array $proof, string $requestId): void
    {
        try {
            DB::transaction(function () use ($actorId, $sessionVersion, $proof, $requestId): void {
                $state = app(EmailChangeState::class);
                $user = $state->lockOwner($actorId, $sessionVersion);
                $change = EmailChangeRequest::query()->whereKey($proof['request_id'])->where('user_id', $actorId)->lockForUpdate()->first();
                if ($change === null || $change->revoked_at !== null || $change->consumed_at !== null
                    || $change->expires_at->lessThanOrEqualTo(now('UTC')) || $change->user_version !== $user->auth_version
                    || ! hash_equals($change->new_email, $proof['email'])
                    || ! hash_equals($change->token_hash, hash('sha256', $proof['token']))) {
                    throw new ApiProblem('EMAIL_CHANGE_INVALID', 'Tautan tidak sesuai dengan akun ini, kedaluwarsa, atau sudah digunakan. Buka email terbaru atau minta perubahan email baru dari Profil.', 422);
                }
                if (User::query()->where('email', $change->new_email)->where('id', '!=', $actorId)->exists()) {
                    $this->unavailable();
                }
                $oldEmail = $user->email;
                $user->forceFill([
                    'email' => $change->new_email, 'email_verified_at' => now('UTC'),
                    'auth_version' => $user->auth_version + 1, 'remember_token' => Str::random(60),
                ])->save();
                $change->forceFill(['consumed_at' => now('UTC')])->save();
                EmailChangeRequest::query()->where('user_id', $actorId)->whereNull('consumed_at')->whereNull('revoked_at')->update(['revoked_at' => now('UTC')]);
                // Discard broker proofs bound to the previous identity; new requests use the new email.
                DB::table(config('auth.passwords.users.table'))->where('email', $oldEmail)->delete();
                $state->cancelDeliveries($actorId);
                DB::table('notification_deliveries')->where('user_id', $actorId)->where('type', 'password_reset')
                    ->whereIn('status', ['pending', 'processing', 'failed'])->update([
                        'status' => 'cancelled', 'payload_encrypted' => null, 'lease_until' => null, 'last_error_code' => 'EMAIL_CHANGED',
                    ]);
                DB::table('sessions')->where('user_id', $actorId)->delete();
                $audit = new AuditLog;
                $audit->forceFill([
                    'actor_user_id' => $actorId, 'action' => 'auth.email_changed', 'target_type' => 'user',
                    'target_id' => $actorId, 'occurred_at' => now('UTC'), 'request_id' => $requestId,
                    'after' => ['email_changed' => true, 'sessions_revoked' => true],
                ])->save();
            }, attempts: 3);
        } catch (QueryException $exception) {
            // A concurrent account can claim the same email after the read check.
            if ((int) ($exception->errorInfo[1] ?? 0) === 1062) {
                $this->unavailable();
            }
            throw $exception;
        }
    }

    private function unavailable(): never
    {
        throw new ApiProblem('EMAIL_UNAVAILABLE', 'Alamat email tidak dapat digunakan. Minta perubahan ke alamat lain dari Profil.', 409);
    }
}
