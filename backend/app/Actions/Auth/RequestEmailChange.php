<?php

declare(strict_types=1);

namespace App\Actions\Auth;

use App\Actions\Notifications\RecordNotificationDelivery;
use App\Exceptions\ApiProblem;
use App\Models\AuditLog;
use App\Models\EmailChangeRequest;
use App\Models\User;
use App\Services\Auth\EmailChangeState;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use SensitiveParameter;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;

class RequestEmailChange
{
    public function handle(int $actorId, int $sessionVersion, string $email, #[SensitiveParameter] string $password, string $requestId): void
    {
        DB::transaction(function () use ($actorId, $sessionVersion, $email, $password, $requestId): void {
            $state = app(EmailChangeState::class);
            $user = $state->lockOwner($actorId, $sessionVersion);
            if (! Hash::check($password, $user->password)) {
                throw new ApiProblem('PASSWORD_CONFIRMATION_FAILED', 'Password akun saat ini tidak sesuai.', 422, ['password' => ['Password akun saat ini tidak sesuai.']]);
            }
            $now = CarbonImmutable::now('UTC');
            $last = EmailChangeRequest::query()->where('user_id', $actorId)->latest('created_at')->first();
            $retryAt = $last?->created_at?->copy()->addSeconds(EmailChangeState::COOLDOWN_SECONDS);
            if ($retryAt !== null && $retryAt->greaterThan($now)) {
                $seconds = (int) ceil($now->diffInSeconds($retryAt));
                throw new TooManyRequestsHttpException(max(1, $seconds));
            }
            if ($email === $user->email || User::query()->where('email', $email)->exists()) {
                throw new ApiProblem('EMAIL_UNAVAILABLE', 'Alamat email tidak dapat digunakan. Gunakan alamat lain.', 409, ['email' => ['Alamat email tidak dapat digunakan.']]);
            }
            EmailChangeRequest::query()->where('user_id', $actorId)->whereNull('consumed_at')->whereNull('revoked_at')->update(['revoked_at' => $now]);
            $state->cancelDeliveries($actorId);
            $token = bin2hex(random_bytes(32));
            $change = new EmailChangeRequest;
            $change->forceFill([
                'id' => (string) Str::uuid(), 'user_id' => $actorId, 'new_email' => $email,
                'token_hash' => hash('sha256', $token), 'user_version' => $user->auth_version,
                'expires_at' => $now->addMinutes(EmailChangeState::EXPIRY_MINUTES),
            ])->save();
            $delivery = app(RecordNotificationDelivery::class)->handle($user, 'email_change', 'email_change:'.$change->id, $now);
            $delivery->forceFill(['payload_encrypted' => ['request_id' => $change->id, 'token' => $token]])->save();
            $audit = new AuditLog;
            $audit->forceFill([
                'actor_user_id' => $actorId, 'action' => 'auth.email_change_requested', 'target_type' => 'user',
                'target_id' => $actorId, 'occurred_at' => $now, 'request_id' => $requestId,
                'after' => ['verification_requested' => true],
            ])->save();
        }, attempts: 3);
    }
}
