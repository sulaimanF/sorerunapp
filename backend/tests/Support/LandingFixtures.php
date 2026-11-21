<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Models\Role;
use App\Models\User;
use App\Services\Content\LandingDocument;
use Illuminate\Support\Facades\DB;

trait LandingFixtures
{
    private function requireLandingDatabase(): void
    {
        $db = DB::connection();
        if (! app()->environment('testing') || $db->getDriverName() !== 'mysql' || $db->getDatabaseName() !== ApprovedTestDatabase::name() || config('cache.default') !== 'array') {
            throw new \RuntimeException('Landing tests require isolated MySQL sorerunapp_test.');
        }
        $actual = $db->selectOne('SELECT DATABASE() AS db_name, VERSION() AS db_version');
        if ($actual->db_name !== ApprovedTestDatabase::name() || ! str_starts_with($actual->db_version, '8.4.')) {
            throw new \RuntimeException('Landing database must actually be isolated MySQL 8.4 sorerunapp_test.');
        }
        $db->statement("SET SESSION time_zone = '+00:00'");
    }

    private function contentAdmin(): User
    {
        $user = User::factory()->create(['email' => 'content-'.bin2hex(random_bytes(12)).'@example.test', 'password' => 'Test-only-password-123', 'email_verified_at' => now('UTC')]);
        $user->roles()->attach(Role::query()->where('code', 'admin')->value('id'), ['assigned_by' => null, 'assigned_at' => now('UTC')]);

        return $user;
    }

    private function publishableContent(): array
    {
        $content = app(LandingDocument::class)->empty();
        $content['hero']['title'] = 'Headline sintetis';
        $content['hero']['description'] = 'Deskripsi pengujian, bukan konten resmi.';
        $content['seo']['title'] = 'SEO sintetis';
        $content['seo']['description'] = 'Deskripsi SEO pengujian.';

        return $content;
    }
}
