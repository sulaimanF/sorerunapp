<?php

declare(strict_types=1);

namespace App\Actions\Auth;

use App\Actions\Notifications\RecordNotificationDelivery;
use App\Enums\AccountStatus;
use App\Enums\MembershipStatus;
use App\Models\Membership;
use App\Models\NotificationDelivery;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use SensitiveParameter;

class ResendVerificationEmail
{
    public function handle(#[SensitiveParameter] string $email): void
    {
        $id = User::query()->where('email', $email)->value('id');
        if ($id === null) {
            return;
        }
        DB::transaction(function () use ($id, $email): void {
            $membership = Membership::query()->where('user_id', $id)->lockForUpdate()->first();
            $user = User::query()->whereKey($id)->lockForUpdate()->first();
            if ($user === null || $user->status !== AccountStatus::Enabled || $user->email_verified_at !== null
                || ! hash_equals($user->email, $email) || $membership?->status !== MembershipStatus::PendingVerification) {
                return;
            }
            $now = CarbonImmutable::now('UTC');
            $last = NotificationDelivery::query()->where('user_id', $id)->where('type', 'email_verification')
                ->latest('created_at')->orderByDesc('id')->lockForUpdate()->first();
            // Cooldown does not reveal whether an email belongs to an eligible member.
            if ($last !== null && $last->created_at->greaterThan($now->subSeconds(60))) {
                return;
            }
            app(RecordNotificationDelivery::class)->handle($user, 'email_verification', 'verification_resend:'.$id.':'.Str::uuid(), $now);
        }, attempts: 3);
    }
}
