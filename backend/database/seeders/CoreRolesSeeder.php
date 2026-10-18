<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\RoleCode;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

final class CoreRolesSeeder extends Seeder
{
    public function run(): void
    {
        $now = now('UTC')->format('Y-m-d H:i:s.u');

        foreach ([
            RoleCode::Member->value => 'Anggota',
            RoleCode::Staff->value => 'Petugas',
            RoleCode::Admin->value => 'Administrator',
        ] as $code => $name) {
            // Running the seeder again preserves role IDs and assignments.
            DB::table('roles')->upsert([
                ['code' => $code, 'name' => $name, 'created_at' => $now, 'updated_at' => $now],
            ], ['code'], ['name', 'updated_at']);
        }
    }
}
