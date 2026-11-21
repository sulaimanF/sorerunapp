<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Actions\Administration\CreateAdminAccount;
use App\Models\AdminInvitation;
use App\Models\NotificationDelivery;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use RuntimeException;

trait AdministrationFixtures
{
    private function requireAdministrationDatabase(): void
    {
        $db = DB::connection();
        if (! app()->environment('testing') || app()->configurationIsCached() || $db->getDriverName() !== 'mysql'
            || ! in_array($db->getConfig('host'), ['localhost', '127.0.0.1'], true)
            || config('cache.default') !== 'array' || config('mail.default') !== 'array'
            || config('sorerunapp.outbox.dispatch_on_commit') !== false) {
            throw new RuntimeException('Unsafe administration context: '.json_encode([
                'environment' => app()->environment(), 'cached' => app()->configurationIsCached(),
                'driver' => $db->getDriverName(), 'host' => $db->getConfig('host'),
                'database' => $db->getDatabaseName(), 'cache' => config('cache.default'),
                'mail' => config('mail.default'), 'dispatch' => config('sorerunapp.outbox.dispatch_on_commit'),
            ], JSON_THROW_ON_ERROR));
        }
        $actual = $db->selectOne('SELECT DATABASE() AS db_name, VERSION() AS version');
        if ($actual->db_name !== 'sorerunapp_stage11_test' || ! str_starts_with($actual->version, '8.4.')
            || ! Schema::hasTable('admin_provisionings') || ! Schema::hasTable('security_bootstraps')) {
            throw new RuntimeException('Requires actual MySQL 8.4 sorerunapp_stage11_test and Stage11 migration; no automatic migration/reset.');
        }
        $db->statement("SET SESSION time_zone = '+00:00'");
    }

    private function administrationActor(string $role = 'super_admin'): User
    {
        $user = User::factory()->create([
            'name' => 'Pengurus Uji Sintetis', 'email' => 'stage11-'.Str::uuid().'@example.test',
            'password' => 'Test-only-password-123', 'email_verified_at' => now('UTC'),
        ]);
        $user->roles()->attach(Role::query()->where('code', $role)->value('id'), ['assigned_by' => null, 'assigned_at' => now('UTC')]);

        return $user;
    }

    private function provisionAdmin(User $actor): array
    {
        return app(CreateAdminAccount::class)->handle((int) $actor->id, $actor->auth_version, [
            'full_name' => 'Admin Uji Sintetis', 'email' => 'stage11-invited-'.Str::uuid().'@example.test', 'request_key' => (string) Str::uuid(),
        ], (string) Str::uuid());
    }

    private function invitationProof(int $target): array
    {
        $invitation = AdminInvitation::query()->where('user_id', $target)->latest('created_at')->orderByDesc('id')->firstOrFail();
        $payload = NotificationDelivery::query()->where('dedupe_key', 'admin_invitation:'.$invitation->id)->firstOrFail()->payload_encrypted;

        return ['invitation_id' => $invitation->id, 'email' => $invitation->email, 'token' => $payload['token']];
    }
}
