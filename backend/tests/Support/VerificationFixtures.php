<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Enums\AccountStatus;
use App\Models\Membership;
use App\Models\NotificationDelivery;
use App\Models\Role;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use RuntimeException;

trait VerificationFixtures
{
    private array $fixtureIds = [];

    private function requireIsolatedMysql(): void
    {
        $db = DB::connection();
        if (! app()->environment('testing') || $db->getDriverName() !== 'mysql'
            || $db->getDatabaseName() !== 'sorerunapp_test' || config('cache.default') !== 'array'
            || ! str_starts_with((string) DB::selectOne('SELECT VERSION() AS version')->version, '8.4.')) {
            throw new RuntimeException('Stage05 tests require isolated MySQL 8.4 sorerunapp_test and array cache.');
        }
        DB::statement("SET SESSION time_zone = '+00:00'");
    }

    private function member(): User
    {
        $user = User::factory()->create(['email' => 'verification-'.bin2hex(random_bytes(12)).'@example.test', 'password' => 'Test-only-password-123']);
        $this->fixtureIds[] = (int) $user->id;
        $user->forceFill(['email_verified_at' => null, 'status' => AccountStatus::Enabled])->save();
        $user->memberProfile()->create(['full_name' => 'Anggota Verifikasi Uji', 'birth_date' => '2000-01-01', 'phone' => '+6281234567890', 'city' => 'Kota Uji']);
        $user->membership()->save(new Membership);
        $user->roles()->attach(Role::query()->where('code', 'member')->value('id'), ['assigned_by' => null, 'assigned_at' => now('UTC')]);

        return $user;
    }

    private function delivery(User $user, string $type = 'email_verification'): NotificationDelivery
    {
        $row = new NotificationDelivery;
        $row->forceFill(['type' => $type, 'dedupe_key' => 'stage05:'.bin2hex(random_bytes(16)), 'available_at' => CarbonImmutable::now('UTC')->subSecond()]);
        $user->notificationDeliveries()->save($row);

        return $row;
    }

    private function cleanupFixtures(): void
    {
        DB::transaction(function (): void {
            $ids = $this->fixtureIds;
            $memberships = DB::table('memberships')->whereIn('user_id', $ids)->pluck('id');
            DB::table('member_credentials')->whereIn('membership_id', $memberships)->delete();
            DB::table('audit_logs')->where('target_type', 'membership')->whereIn('target_id', $memberships)->delete();
            DB::table('sessions')->whereIn('user_id', $ids)->delete();
            foreach (['notification_deliveries', 'policy_acknowledgements', 'user_roles', 'member_profiles', 'memberships'] as $table) {
                DB::table($table)->whereIn('user_id', $ids)->delete();
            }
            DB::table('users')->whereIn('id', $ids)->delete();
        });
    }
}
