<?php

declare(strict_types=1);

namespace Tests\Feature\Schema;

use Database\Seeders\CoreRolesSeeder;
use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

final class MembershipCoreSchemaTest extends TestCase
{
    private ?Connection $schemaConnection = null;

    protected function setUp(): void
    {
        parent::setUp();

        $connection = DB::connection();

        // Guard runs before any fixture is written or transaction is started.
        if (! app()->environment('testing')
            || $connection->getDriverName() !== 'mysql'
            || $connection->getDatabaseName() !== 'sorerunapp_test') {
            throw new RuntimeException('Schema tests require testing + MySQL + sorerunapp_test.');
        }

        $version = (string) DB::selectOne('SELECT VERSION() AS version')->version;

        if (! str_starts_with($version, '8.4.')) {
            throw new RuntimeException('Schema tests require real MySQL 8.4.');
        }

        if (! Schema::hasTable('member_credentials')) {
            throw new RuntimeException('Run migrations on sorerunapp_test before schema tests.');
        }

        DB::statement("SET SESSION time_zone = '+00:00'");
        $connection->beginTransaction();
        $this->schemaConnection = $connection;
    }

    protected function tearDown(): void
    {
        try {
            if ($this->schemaConnection !== null && $this->schemaConnection->transactionLevel() > 0) {
                $this->schemaConnection->rollBack();
            }
        } finally {
            parent::tearDown();
        }
    }

    public function test_core_tables_use_innodb_and_utf8mb4(): void
    {
        foreach (['users', 'roles', 'user_roles', 'member_profiles', 'memberships',
            'member_number_sequences', 'member_credentials', 'policy_acknowledgements',
            'audit_logs', 'notification_deliveries'] as $table) {
            $metadata = DB::selectOne(
                'SELECT ENGINE AS engine, TABLE_COLLATION AS collation_name FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?',
                [$table],
            );

            self::assertNotNull($metadata, $table);
            self::assertSame('InnoDB', $metadata->engine, $table);
            self::assertStringStartsWith('utf8mb4_', $metadata->collation_name, $table);
        }
    }

    public function test_credentials_use_fixed_binary_hash_and_stored_generated_guard(): void
    {
        $hash = DB::selectOne("SELECT DATA_TYPE AS data_type, CHARACTER_MAXIMUM_LENGTH AS maximum_length FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'member_credentials' AND COLUMN_NAME = 'token_hash'");
        self::assertSame('binary', $hash->data_type);
        self::assertSame(32, (int) $hash->maximum_length);

        $guard = DB::selectOne("SELECT EXTRA AS extra FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'member_credentials' AND COLUMN_NAME = 'active_membership_id'");
        self::assertStringContainsString('STORED GENERATED', $guard->extra);

        $date = DB::selectOne("SELECT DATA_TYPE AS data_type, DATETIME_PRECISION AS precision_value FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'memberships' AND COLUMN_NAME = 'joined_at'");
        self::assertSame('datetime', $date->data_type);
        self::assertSame(6, (int) $date->precision_value);
    }

    public function test_account_status_and_auth_version_are_enforced(): void
    {
        $userId = $this->account();
        $this->assertRejected(fn () => DB::table('users')->where('id', $userId)->update(['status' => 'active']), 3819);
        $this->assertRejected(fn () => DB::table('users')->where('id', $userId)->update(['auth_version' => 0]), 3819);
    }

    public function test_email_remains_unique_case_insensitively(): void
    {
        $email = 'schema-'.bin2hex(random_bytes(8)).'@example.test';
        $this->account(['email' => $email]);
        $this->assertRejected(fn () => $this->account(['email' => strtoupper($email)]), 1062);
    }

    public function test_one_profile_per_user_and_nonunique_names_and_phones(): void
    {
        $userId = $this->account();
        $profile = [
            'user_id' => $userId, 'full_name' => 'Anggota Uji', 'birth_date' => '2010-01-01',
            'phone' => '+628000000000', 'city' => 'Kota Uji',
        ];
        DB::table('member_profiles')->insert($profile);
        $this->assertRejected(fn () => DB::table('member_profiles')->insert($profile), 1062);

        $profile['user_id'] = $this->account();
        DB::table('member_profiles')->insert($profile);
        self::assertSame(2, DB::table('member_profiles')->where('full_name', 'Anggota Uji')->count());
    }

    public function test_user_can_have_only_one_membership(): void
    {
        $userId = $this->account();
        $this->membership($userId);
        $this->assertRejected(fn () => $this->membership($userId), 1062);
    }

    public function test_pending_members_can_share_null_member_numbers(): void
    {
        $first = $this->membership($this->account());
        $second = $this->membership($this->account());
        self::assertNotSame($first, $second);
        self::assertNull(DB::table('memberships')->where('id', $first)->value('member_number'));
    }

    public function test_issued_member_number_is_unique(): void
    {
        $number = 'TEST-'.bin2hex(random_bytes(8));
        $this->membership($this->account(), ['member_number' => $number]);
        $this->assertRejected(fn () => $this->membership($this->account(), ['member_number' => $number]), 1062);
    }

    public function test_invalid_membership_status_and_active_without_number_are_rejected(): void
    {
        $membershipId = $this->membership($this->account());
        $this->assertRejected(fn () => DB::table('memberships')->where('id', $membershipId)->update(['status' => 'expired']), 3819);
        $this->assertRejected(fn () => DB::table('memberships')->where('id', $membershipId)->update(['status' => 'active']), 3819);
    }

    public function test_only_one_active_credential_can_exist(): void
    {
        $membershipId = $this->membership($this->account());
        $this->credential($membershipId);
        $this->assertRejected(fn () => $this->credential($membershipId), 1062);
    }

    public function test_revocation_releases_guard_and_keeps_history(): void
    {
        $membershipId = $this->membership($this->account());
        $first = $this->credential($membershipId);

        DB::table('member_credentials')->where('id', $first)->update([
            'revoked_at' => now('UTC')->addSecond()->format('Y-m-d H:i:s.u'),
            'token_encrypted' => null,
        ]);

        $second = $this->credential($membershipId);
        self::assertNull(DB::table('member_credentials')->where('id', $first)->value('active_membership_id'));
        self::assertSame($membershipId, (int) DB::table('member_credentials')->where('id', $second)->value('active_membership_id'));
        self::assertSame(2, DB::table('member_credentials')->where('membership_id', $membershipId)->count());
    }

    public function test_credential_hash_cannot_be_reused(): void
    {
        $hash = hash('sha256', random_bytes(32), true);
        $first = $this->credential($this->membership($this->account()), ['token_hash' => $hash]);
        DB::table('member_credentials')->where('id', $first)->update(['revoked_at' => now('UTC')->addSecond()->format('Y-m-d H:i:s.u')]);
        $this->assertRejected(fn () => $this->credential($this->membership($this->account()), ['token_hash' => $hash]), 1062);
    }

    public function test_anonymized_membership_must_be_an_ended_tombstone(): void
    {
        $id = $this->membership($this->account(), ['member_number' => 'TEST-'.bin2hex(random_bytes(8))]);
        $this->assertRejected(fn () => DB::table('memberships')->where('id', $id)->update([
            'anonymized_at' => now('UTC')->format('Y-m-d H:i:s.u'),
        ]), 3819);

        DB::table('memberships')->where('id', $id)->update([
            'user_id' => null, 'status' => 'ended', 'status_reason' => null,
            'ended_at' => now('UTC')->format('Y-m-d H:i:s.u'),
            'anonymized_at' => now('UTC')->format('Y-m-d H:i:s.u'),
        ]);
        self::assertNull(DB::table('memberships')->where('id', $id)->value('user_id'));
    }

    public function test_membership_history_prevents_direct_account_deletion(): void
    {
        $userId = $this->account();
        $this->membership($userId);
        $this->assertRejected(fn () => DB::table('users')->where('id', $userId)->delete(), 1451);
    }

    public function test_roles_seeder_is_idempotent_without_creating_accounts(): void
    {
        $userCount = DB::table('users')->count();
        $this->seed(CoreRolesSeeder::class);
        $idsBefore = DB::table('roles')->orderBy('code')->pluck('id', 'code')->all();
        $this->seed(CoreRolesSeeder::class);
        self::assertSame($idsBefore, DB::table('roles')->orderBy('code')->pluck('id', 'code')->all());
        self::assertSame(['admin', 'member', 'staff'], array_keys($idsBefore));
        self::assertSame($userCount, DB::table('users')->count());
    }

    public function test_user_role_assignment_is_unique(): void
    {
        $this->seed(CoreRolesSeeder::class);
        $assignment = [
            'user_id' => $this->account(), 'role_id' => DB::table('roles')->where('code', 'member')->value('id'),
            'assigned_at' => now('UTC')->format('Y-m-d H:i:s.u'),
        ];
        DB::table('user_roles')->insert($assignment);
        $this->assertRejected(fn () => DB::table('user_roles')->insert($assignment), 1062);
    }

    public function test_delivery_dedupe_and_status_are_enforced(): void
    {
        $delivery = [
            'type' => 'email_verification', 'dedupe_key' => 'test-'.bin2hex(random_bytes(8)),
            'available_at' => now('UTC')->format('Y-m-d H:i:s.u'),
        ];
        $id = DB::table('notification_deliveries')->insertGetId($delivery);
        $this->assertRejected(fn () => DB::table('notification_deliveries')->insert($delivery), 1062);
        $this->assertRejected(fn () => DB::table('notification_deliveries')->where('id', $id)->update(['status' => 'unknown']), 3819);
    }

    public function test_sequence_cannot_be_reset_to_zero(): void
    {
        $this->assertRejected(fn () => DB::table('member_number_sequences')->where('name', 'membership')->update(['next_value' => 0]), 3819);
    }

    private function account(array $overrides = []): int
    {
        return (int) DB::table('users')->insertGetId(array_replace([
            'name' => 'Anggota Uji', 'email' => 'schema-'.bin2hex(random_bytes(8)).'@example.test',
            'password' => password_hash('Test-only-password-123', PASSWORD_BCRYPT, ['cost' => 4]),
            'status' => 'enabled', 'auth_version' => 1,
        ], $overrides));
    }

    private function membership(int $userId, array $overrides = []): int
    {
        $data = array_replace(['user_id' => $userId, 'status' => 'pending_verification'], $overrides);

        if (isset($data['member_number'])) {
            $data['status'] = 'active';
            $data['joined_at'] = now('UTC')->format('Y-m-d H:i:s.u');
        }

        return (int) DB::table('memberships')->insertGetId($data);
    }

    private function credential(int $membershipId, array $overrides = []): int
    {
        $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');

        return (int) DB::table('member_credentials')->insertGetId(array_replace([
            'membership_id' => $membershipId, 'token_hash' => hash('sha256', $token, true),
            'token_encrypted' => Crypt::encryptString($token),
            'issued_at' => now('UTC')->format('Y-m-d H:i:s.u'),
        ], $overrides));
    }

    private function assertRejected(callable $write, int $mysqlError): void
    {
        try {
            $write();
        } catch (QueryException $exception) {
            self::assertSame($mysqlError, (int) ($exception->errorInfo[1] ?? 0), $exception->getMessage());

            return;
        }

        self::fail('MySQL accepted a write that should violate an integrity constraint.');
    }
}
