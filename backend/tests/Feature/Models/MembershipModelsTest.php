<?php

declare(strict_types=1);

namespace Tests\Feature\Models;

use App\Enums\AccountStatus;
use App\Enums\MembershipStatus;
use App\Enums\NotificationDeliveryStatus;
use App\Enums\RoleCode;
use App\Models\AuditLog;
use App\Models\MemberCredential;
use App\Models\MemberNumberSequence;
use App\Models\MemberProfile;
use App\Models\Membership;
use App\Models\NotificationDelivery;
use App\Models\PolicyAcknowledgement;
use App\Models\Role;
use App\Models\User;
use App\Models\UserRole;
use Carbon\CarbonImmutable;
use Database\Seeders\CoreRolesSeeder;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

final class MembershipModelsTest extends TestCase
{
    private ?Connection $modelConnection = null;

    protected function setUp(): void
    {
        parent::setUp();

        $connection = DB::connection();

        if (! app()->environment('testing')
            || $connection->getDriverName() !== 'mysql'
            || $connection->getDatabaseName() !== 'sorerunapp_test') {
            throw new RuntimeException('Model tests require testing + MySQL + sorerunapp_test.');
        }

        $version = (string) DB::selectOne('SELECT VERSION() AS version')->version;

        if (! str_starts_with($version, '8.4.') || ! Schema::hasTable('member_credentials')) {
            throw new RuntimeException('Use real MySQL 8.4 with stage 01 migrations applied.');
        }

        DB::statement("SET SESSION time_zone = '+00:00'");
        $connection->beginTransaction();
        $this->modelConnection = $connection;
    }

    protected function tearDown(): void
    {
        try {
            if ($this->modelConnection !== null && $this->modelConnection->transactionLevel() > 0) {
                $this->modelConnection->rollBack();
            }
        } finally {
            parent::tearDown();
        }
    }

    public function test_email_is_normalized_without_rewriting_gmail_aliases(): void
    {
        $suffix = bin2hex(random_bytes(5));
        $user = $this->account(['email' => '  Runner.Name+'.$suffix.'@GMAIL.COM  ']);
        self::assertSame('runner.name+'.$suffix.'@gmail.com', $user->fresh()->email);

        $user->email = '  Runner.Updated+'.$suffix.'@EXAMPLE.TEST  ';
        $user->save();
        self::assertSame('runner.updated+'.$suffix.'@example.test', $user->fresh()->email);
    }

    public function test_password_is_hashed_and_account_secrets_are_hidden(): void
    {
        $plain = 'Test-only-password-123';
        $user = $this->account(['password' => $plain]);
        $raw = DB::table('users')->where('id', $user->id)->value('password');
        self::assertNotSame($plain, $raw);
        self::assertTrue(Hash::check($plain, $raw));

        $serialized = $user->fresh()->toArray();
        foreach (['password', 'remember_token', 'auth_version'] as $field) {
            self::assertArrayNotHasKey($field, $serialized);
        }
    }

    public function test_privileged_and_ownership_fields_are_not_mass_assignable(): void
    {
        $fields = [
            User::class => ['id', 'status', 'auth_version', 'blocked_at', 'email_verified_at', 'role'],
            MemberProfile::class => ['id', 'user_id', 'photo_path'],
            Membership::class => ['user_id', 'status', 'member_number', 'joined_at', 'anonymized_at'],
            MemberCredential::class => ['membership_id', 'token_hash', 'token_encrypted', 'revoked_at', 'active_membership_id'],
            Role::class => ['id', 'code', 'name'],
            UserRole::class => ['user_id', 'role_id'],
            MemberNumberSequence::class => ['name', 'next_value'],
            PolicyAcknowledgement::class => ['user_id', 'policy_version', 'acknowledged_at'],
            AuditLog::class => ['actor_user_id', 'action', 'before', 'after'],
            NotificationDelivery::class => ['user_id', 'status', 'dedupe_key', 'attempt_count'],
        ];

        foreach ($fields as $class => $attributes) {
            $model = new $class;
            foreach ($attributes as $attribute) {
                self::assertFalse($model->isFillable($attribute), $class.'::'.$attribute);
            }
        }
    }

    public function test_default_states_and_dates_use_typed_casts(): void
    {
        $user = $this->account();
        self::assertSame(AccountStatus::Enabled, $user->status);
        $user->forceFill([
            'email_verified_at' => '2026-10-17 00:00:00.123456',
            'status' => AccountStatus::Blocked,
            'blocked_at' => '2026-10-17 00:00:00.654321',
        ])->save();
        $user->refresh();

        self::assertSame(AccountStatus::Blocked, $user->status);
        self::assertSame(1, $user->auth_version);
        self::assertInstanceOf(CarbonImmutable::class, $user->email_verified_at);
        self::assertInstanceOf(CarbonImmutable::class, $user->blocked_at);

        $membership = $this->membership($user);
        self::assertSame(MembershipStatus::PendingVerification, $membership->status);
    }

    public function test_profile_and_membership_relations_resolve_owners(): void
    {
        $user = $this->account();
        $profile = $this->profile($user);
        $profile->forceFill(['photo_path' => 'private/test-only-photo.jpg'])->save();
        $membership = $this->membership($user);

        self::assertTrue($user->fresh()->memberProfile->is($profile));
        self::assertTrue($profile->fresh()->user->is($user));
        self::assertTrue($user->fresh()->membership->is($membership));
        self::assertTrue($membership->fresh()->user->is($user));
        self::assertArrayNotHasKey('photo_path', $profile->fresh()->toArray());
    }

    public function test_user_factory_and_email_verification_contract_are_preserved(): void
    {
        $unverified = User::factory()->unverified()->create();
        self::assertInstanceOf(MustVerifyEmail::class, $unverified);
        self::assertFalse($unverified->hasVerifiedEmail());

        $verified = User::factory()->create();
        self::assertTrue($verified->hasVerifiedEmail());
        self::assertSame(AccountStatus::Enabled, $verified->fresh()->status);
    }

    public function test_completeness_accepts_minors_and_does_not_require_photo_or_gender(): void
    {
        $profile = new MemberProfile([
            'full_name' => 'Anggota Uji',
            'birth_date' => now('UTC')->subYears(10)->toDateString(),
            'phone' => '+628000000000',
            'city' => 'Kota Uji',
        ]);
        self::assertTrue($profile->isComplete());
        self::assertSame($profile->birth_date->format('Y-m-d'), $profile->toArray()['birth_date']);

        $profile->city = '   ';
        self::assertFalse($profile->isComplete());
        $profile->city = 'Kota Uji';
        $profile->birth_date = now('UTC')->addDay()->toDateString();
        self::assertFalse($profile->isComplete());
    }

    public function test_role_pivot_preserves_assignment_time_and_actor(): void
    {
        $this->seed(CoreRolesSeeder::class);
        $member = $this->account();
        $actor = $this->account();
        $role = Role::query()->where('code', RoleCode::Member->value)->firstOrFail();
        $member->roles()->attach($role->id, [
            'assigned_by' => $actor->id,
            'assigned_at' => '2026-10-17 00:00:00.123456',
        ]);

        $loadedRole = $member->fresh()->roles->sole();
        self::assertSame(RoleCode::Member, $loadedRole->code);
        self::assertInstanceOf(UserRole::class, $loadedRole->pivot);
        self::assertInstanceOf(CarbonImmutable::class, $loadedRole->pivot->assigned_at);
        self::assertSame('123456', $loadedRole->pivot->assigned_at->format('u'));
        self::assertTrue($loadedRole->pivot->assignedBy->is($actor));
        self::assertTrue($loadedRole->pivot->user->is($member));
        self::assertTrue($loadedRole->pivot->role->is($role));
        self::assertTrue($role->fresh()->users->sole()->is($member));
    }

    public function test_credential_encrypts_at_rest_and_hides_lookup_material(): void
    {
        $token = $this->token();
        $membership = $this->membership($this->account(), true);
        $credential = $this->credential($membership, $token);
        $raw = DB::table('member_credentials')->where('id', $credential->id)->first();

        self::assertNotSame($token, $raw->token_encrypted);
        self::assertSame($token, Crypt::decryptString($raw->token_encrypted));
        self::assertSame(hash('sha256', $token, true), $raw->token_hash);
        self::assertSame($token, $credential->fresh()->token_encrypted);

        $serialized = $credential->fresh()->toArray();
        foreach (['token_hash', 'token_encrypted', 'active_membership_id'] as $field) {
            self::assertArrayNotHasKey($field, $serialized);
        }
        self::assertStringNotContainsString($token, json_encode($serialized, JSON_THROW_ON_ERROR));
    }

    public function test_active_credential_relation_excludes_revoked_history(): void
    {
        $owner = $this->account();
        $membership = $this->membership($owner, true);
        $old = $this->credential($membership, $this->token(), $owner);
        $old->forceFill([
            'revoked_at' => now('UTC')->addSecond(),
            'revoked_by' => $owner->id,
            'token_encrypted' => null,
        ])->save();
        $new = $this->credential($membership, $this->token(), $owner);

        self::assertTrue($membership->fresh()->activeCredential->is($new));
        self::assertCount(2, $membership->fresh()->credentials);
        self::assertTrue($old->fresh()->issuedBy->is($owner));
        self::assertTrue($old->fresh()->revokedBy->is($owner));
        self::assertTrue($new->fresh()->membership->is($membership));
        self::assertNull($old->fresh()->token_encrypted);
    }

    public function test_eloquent_preserves_microsecond_datetime_values(): void
    {
        $user = $this->account();
        $membership = $this->membership($user);
        $stamp = '2026-10-17 00:00:00.123456';
        $membership->forceFill([
            'status' => MembershipStatus::Active,
            'member_number' => 'TEST-'.bin2hex(random_bytes(8)),
            'joined_at' => $stamp,
        ])->save();

        self::assertSame($stamp, DB::table('memberships')->where('id', $membership->id)->value('joined_at'));
        self::assertSame('123456', $membership->fresh()->joined_at->format('u'));
        self::assertSame(MembershipStatus::Active, $membership->fresh()->status);
    }

    public function test_supporting_models_cast_fields_and_use_explicit_timestamps(): void
    {
        $user = $this->account();
        $policy = new PolicyAcknowledgement;
        $policy->forceFill([
            'user_id' => $user->id, 'policy_type' => 'privacy_notice',
            'policy_version' => 'test-v1', 'acknowledged_at' => now('UTC'),
        ])->save();
        self::assertInstanceOf(CarbonImmutable::class, $policy->fresh()->acknowledged_at);
        self::assertTrue($policy->fresh()->user->is($user));
        self::assertTrue($user->fresh()->policyAcknowledgements->sole()->is($policy));

        $audit = new AuditLog;
        $audit->forceFill([
            'actor_user_id' => $user->id, 'action' => 'schema_test', 'target_type' => 'membership',
            'occurred_at' => now('UTC'), 'before' => ['status' => 'pending_verification'],
            'after' => ['status' => 'active'],
        ])->save();
        self::assertSame(['status' => 'active'], $audit->fresh()->after);
        self::assertTrue($audit->fresh()->actor->is($user));
        self::assertTrue($user->fresh()->auditLogs->sole()->is($audit));
        self::assertArrayNotHasKey('before', $audit->fresh()->toArray());
        self::assertArrayNotHasKey('after', $audit->fresh()->toArray());

        $delivery = new NotificationDelivery;
        $delivery->forceFill([
            'user_id' => $user->id, 'type' => 'email_verification',
            'dedupe_key' => 'model-test-'.bin2hex(random_bytes(8)), 'available_at' => now('UTC'),
        ])->save();
        self::assertSame(NotificationDeliveryStatus::Pending, $delivery->fresh()->status);
        self::assertSame(0, $delivery->fresh()->attempt_count);
        self::assertInstanceOf(CarbonImmutable::class, $delivery->fresh()->available_at);
        self::assertTrue($delivery->fresh()->user->is($user));
        self::assertTrue($user->fresh()->notificationDeliveries->sole()->is($delivery));
        self::assertArrayNotHasKey('dedupe_key', $delivery->fresh()->toArray());
    }

    public function test_sequence_uses_string_primary_key_without_automatic_timestamps(): void
    {
        $sequence = MemberNumberSequence::query()->findOrFail('membership');
        self::assertSame('membership', $sequence->getKey());
        self::assertFalse($sequence->getIncrementing());
        self::assertFalse($sequence->usesTimestamps());
        self::assertIsInt($sequence->next_value);
        self::assertFalse((new PolicyAcknowledgement)->usesTimestamps());
        self::assertFalse((new AuditLog)->usesTimestamps());
        self::assertFalse((new UserRole)->usesTimestamps());
    }

    public function test_saving_verified_email_does_not_trigger_activation_side_effects(): void
    {
        $user = $this->account();
        $membership = $this->membership($user);
        $this->profile($user);
        $nextValue = MemberNumberSequence::query()->findOrFail('membership')->next_value;
        $user->forceFill(['email_verified_at' => now('UTC')])->save();

        self::assertTrue($user->fresh()->hasVerifiedEmail());
        self::assertSame(MembershipStatus::PendingVerification, $membership->fresh()->status);
        self::assertNull($membership->fresh()->member_number);
        self::assertSame($nextValue, MemberNumberSequence::query()->findOrFail('membership')->next_value);
        self::assertSame(0, $membership->credentials()->count());
    }

    public function test_decryption_failure_does_not_replace_or_reissue_credential(): void
    {
        $membership = $this->membership($this->account(), true);
        $credential = $this->credential($membership, $this->token());
        DB::table('member_credentials')->where('id', $credential->id)->update(['token_encrypted' => 'invalid-test-ciphertext']);

        try {
            $credential->fresh()->token_encrypted;
            self::fail('Invalid ciphertext should raise a decryption exception.');
        } catch (DecryptException) {
            self::assertSame(1, $membership->credentials()->count());
            self::assertSame($credential->id, $membership->fresh()->activeCredential->id);
        }
    }

    private function account(array $overrides = []): User
    {
        return User::query()->create(array_replace([
            'name' => 'Anggota Uji', 'email' => 'model-'.bin2hex(random_bytes(8)).'@example.test',
            'password' => 'Test-only-password-123',
        ], $overrides));
    }

    private function profile(User $user): MemberProfile
    {
        return $user->memberProfile()->create([
            'full_name' => 'Anggota Uji', 'birth_date' => '2010-01-01',
            'phone' => '+628000000000', 'city' => 'Kota Uji',
        ]);
    }

    private function membership(User $user, bool $active = false): Membership
    {
        $membership = new Membership;

        if ($active) {
            $membership->forceFill([
                'status' => MembershipStatus::Active,
                'member_number' => 'TEST-'.bin2hex(random_bytes(8)),
                'joined_at' => now('UTC'),
            ]);
        }

        $user->membership()->save($membership);

        return $membership;
    }

    private function credential(Membership $membership, string $token, ?User $actor = null): MemberCredential
    {
        $credential = new MemberCredential;
        $credential->forceFill([
            'token_hash' => hash('sha256', $token, true),
            // The encrypted cast receives plaintext; it encrypts on write.
            'token_encrypted' => $token,
            'issued_at' => now('UTC'),
            'issued_by' => $actor?->id,
        ]);
        $membership->credentials()->save($credential);

        return $credential;
    }

    private function token(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }
}
