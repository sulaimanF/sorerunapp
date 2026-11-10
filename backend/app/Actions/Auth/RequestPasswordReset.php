<?php

declare(strict_types=1);

namespace App\Actions\Auth;

use App\Actions\Notifications\RecordNotificationDelivery;
use App\Models\NotificationDelivery;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Support\Timebox;
use SensitiveParameter;

class RequestPasswordReset
{
    public function handle(#[SensitiveParameter] string $email): void
    {
        // Keep the public response independent of account existence, including its minimum duration.
        (new Timebox)->call(function () use ($email): void {
            DB::transaction(function () use ($email): void {
                $user = User::query()->where('email', $email)->lockForUpdate()->first();
                if ($user === null) {
                    return;
                }
                $now = CarbonImmutable::now('UTC');
                $last = NotificationDelivery::query()->where('user_id', $user->id)->where('type', 'password_reset')
                    ->latest('created_at')->orderByDesc('id')->first();
                if ($last !== null && $last->created_at->greaterThan($now->subSeconds((int) config('auth.passwords.users.throttle')))) {
                    return;
                }
                Password::broker('users')->sendResetLink(['email' => $email], function (User $recipient, #[SensitiveParameter] string $token) use ($now): void {
                    // Bind the broker's existing hash record; this is not a second token store.
                    DB::table(config('auth.passwords.users.table'))->where('email', $recipient->email)->update([
                        'user_id' => $recipient->id, 'auth_version' => $recipient->auth_version,
                    ]);
                    $delivery = app(RecordNotificationDelivery::class)->handle($recipient, 'password_reset', 'password_reset:'.Str::uuid(), $now);
                    $delivery->forceFill(['payload_encrypted' => [
                        'email' => $recipient->email, 'token' => $token, 'auth_version' => $recipient->auth_version,
                    ]])->save();
                });
            }, attempts: 3);
        }, 200000);
    }
}
