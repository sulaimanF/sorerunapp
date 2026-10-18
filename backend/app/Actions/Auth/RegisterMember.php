<?php

declare(strict_types=1);

namespace App\Actions\Auth;

use App\Actions\Notifications\RecordEmailVerificationDelivery;
use App\Data\Auth\RegisterMemberData;
use App\Enums\RoleCode;
use App\Exceptions\ApiProblem;
use App\Models\AuditLog;
use App\Models\Membership;
use App\Models\PolicyAcknowledgement;
use App\Models\Role;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use SensitiveParameter;

class RegisterMember
{
    public function __construct(private readonly RecordEmailVerificationDelivery $deliveries) {}

    public function handle(#[SensitiveParameter] RegisterMemberData $data, string $requestId): void
    {
        // Hash on both paths to reduce a simple timing difference for existing email.
        $passwordHash = Hash::make($data->password);
        $memberRoleId = Role::query()->where('code', RoleCode::Member->value)->value('id');

        if ($memberRoleId === null) {
            throw new ApiProblem('REGISTRATION_UNAVAILABLE', 'Layanan registrasi belum tersedia. Silakan coba kembali nanti.', 503);
        }

        try {
            DB::transaction(function () use ($data, $passwordHash, $memberRoleId, $requestId): void {
                if (User::query()->where('email', $data->email)->exists()) {
                    return;
                }

                $now = CarbonImmutable::now('UTC');
                $user = User::query()->create([
                    'name' => $data->fullName,
                    'email' => $data->email,
                    'password' => $passwordHash,
                ]);

                $user->memberProfile()->create([
                    'full_name' => $data->fullName,
                    'birth_date' => $data->birthDate,
                    'phone' => $data->phone,
                    'city' => $data->city,
                    'gender' => $data->gender,
                ]);

                $membership = new Membership;
                $user->membership()->save($membership);
                $user->roles()->attach($memberRoleId, ['assigned_by' => null, 'assigned_at' => $now]);

                foreach (['privacy_notice' => $data->privacyNoticeVersion, 'terms' => $data->termsVersion] as $type => $version) {
                    $acknowledgement = new PolicyAcknowledgement;
                    $acknowledgement->forceFill([
                        'policy_type' => $type,
                        'policy_version' => $version,
                        'acknowledged_at' => $now,
                    ]);
                    $user->policyAcknowledgements()->save($acknowledgement);
                }

                $audit = new AuditLog;
                $audit->forceFill([
                    'actor_user_id' => null,
                    'action' => 'member.registered',
                    'target_type' => 'membership',
                    'target_id' => $membership->id,
                    'occurred_at' => $now,
                    'after' => ['status' => 'pending_verification'],
                    'request_id' => $requestId,
                ])->save();

                // The outbox is committed with the account. No network call occurs here.
                $this->deliveries->handle($user, $now);
            }, attempts: 3);
        } catch (UniqueConstraintViolationException $exception) {
            // Only swallow the known users.email race; other constraint failures remain errors.
            $isEmailConflict = (int) ($exception->errorInfo[1] ?? 0) === 1062
                && str_contains((string) ($exception->errorInfo[2] ?? ''), 'users_email_unique');

            if (! $isEmailConflict || ! User::query()->where('email', $data->email)->exists()) {
                throw $exception;
            }
        }
    }
}
