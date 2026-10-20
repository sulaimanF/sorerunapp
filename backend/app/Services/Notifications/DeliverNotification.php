<?php

declare(strict_types=1);

namespace App\Services\Notifications;

use App\Data\Notifications\DeliveryClaim;
use App\Enums\AccountStatus;
use App\Enums\MembershipStatus;
use App\Enums\NotificationDeliveryStatus;
use App\Models\NotificationDelivery;
use App\Models\User;
use App\Notifications\MembershipActivated;
use App\Notifications\VerifyMembershipEmail;
use App\Services\Auth\EmailVerificationUrl;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use LogicException;
use Throwable;

class DeliverNotification
{
    public const MAX_ATTEMPTS = 5;

    public const LEASE_SECONDS = 180;

    public function handle(int $id): void
    {
        if (DB::transactionLevel() !== 0) {
            throw new LogicException('External delivery must run outside a database transaction.');
        }
        $claim = $this->claim($id);
        if ($claim === null) {
            return;
        }
        $delivery = NotificationDelivery::query()->find($id);
        $user = $delivery?->user_id === null ? null : User::query()->find($delivery->user_id);
        if ($user === null || $user->status !== AccountStatus::Enabled) {
            $this->cancel($claim, 'RECIPIENT_UNAVAILABLE');

            return;
        }
        if ($delivery->type === 'email_verification') {
            if ($user->email_verified_at !== null || $user->membership?->status !== MembershipStatus::PendingVerification) {
                $this->cancel($claim, 'VERIFICATION_NOT_REQUIRED');

                return;
            }
        } elseif ($delivery->type === 'membership_activated') {
            if ($user->email_verified_at === null || $user->membership?->status !== MembershipStatus::Active) {
                $this->cancel($claim, 'MEMBERSHIP_NOT_ACTIVE');

                return;
            }
        } else {
            $this->cancel($claim, 'UNSUPPORTED_NOTIFICATION_TYPE');

            return;
        }
        // Prevent a log transport from exposing signed URLs. Array is allowed only in isolated tests.
        if (config('mail.default') !== 'smtp' && ! (app()->environment('testing') && config('mail.default') === 'array')) {
            $this->fail($claim, 'SMTP_MAILER_REQUIRED');

            return;
        }
        try {
            $notification = $delivery->type === 'email_verification'
                ? new VerifyMembershipEmail(app(EmailVerificationUrl::class)->forBrowser($user))
                : new MembershipActivated((string) $user->membership->member_number);
            Notification::sendNow($user, $notification);
        } catch (Throwable) {
            // Never persist provider exception text, email, signed URL, or transport credentials.
            $this->fail($claim, 'MAIL_SEND_FAILED');

            return;
        }
        $this->sent($claim);
    }

    public function claim(int $id): ?DeliveryClaim
    {
        return DB::transaction(function () use ($id): ?DeliveryClaim {
            $delivery = NotificationDelivery::query()->whereKey($id)->lockForUpdate()->first();
            if ($delivery === null || in_array($delivery->status, [NotificationDeliveryStatus::Sent, NotificationDeliveryStatus::Cancelled], true)) {
                return null;
            }
            $now = CarbonImmutable::now('UTC');
            if ($delivery->status === NotificationDeliveryStatus::Processing) {
                if ($delivery->lease_until !== null && $delivery->lease_until->greaterThan($now)) {
                    return null;
                }
            } elseif ($delivery->available_at->greaterThan($now)) {
                return null;
            }
            if ($delivery->attempt_count >= self::MAX_ATTEMPTS) {
                $delivery->forceFill(['status' => NotificationDeliveryStatus::Failed, 'lease_until' => null, 'last_error_code' => 'ATTEMPTS_EXHAUSTED'])->save();

                return null;
            }
            $lease = $now->addSeconds(self::LEASE_SECONDS);
            $delivery->forceFill([
                'status' => NotificationDeliveryStatus::Processing, 'attempt_count' => $delivery->attempt_count + 1,
                'lease_until' => $lease, 'last_error_code' => null,
            ])->save();

            return new DeliveryClaim((int) $delivery->id, $delivery->attempt_count, $lease->format('Y-m-d H:i:s.u'));
        }, attempts: 3);
    }

    public function sent(DeliveryClaim $claim): void
    {
        $this->fence($claim)->update([
            'status' => NotificationDeliveryStatus::Sent->value, 'sent_at' => CarbonImmutable::now('UTC')->format('Y-m-d H:i:s.u'),
            'lease_until' => null, 'last_error_code' => null,
        ]);
    }

    public function fail(DeliveryClaim $claim, string $code): void
    {
        $delay = [60, 300, 900, 3600, 3600][min(max($claim->attempt - 1, 0), 4)];
        $this->fence($claim)->update([
            'status' => NotificationDeliveryStatus::Failed->value, 'lease_until' => null, 'last_error_code' => $code,
            'available_at' => CarbonImmutable::now('UTC')->addSeconds($delay)->format('Y-m-d H:i:s.u'),
        ]);
    }

    public function cancel(DeliveryClaim $claim, string $code): void
    {
        $this->fence($claim)->update(['status' => NotificationDeliveryStatus::Cancelled->value, 'lease_until' => null, 'last_error_code' => $code]);
    }

    private function fence(DeliveryClaim $claim): Builder
    {
        // A timed-out worker cannot overwrite the outcome of a newer lease.
        return NotificationDelivery::query()->whereKey($claim->id)
            ->where('status', NotificationDeliveryStatus::Processing->value)
            ->where('attempt_count', $claim->attempt)->where('lease_until', $claim->leaseUntil);
    }
}
