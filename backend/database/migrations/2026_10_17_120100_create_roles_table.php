<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('roles', function (Blueprint $table): void {
            $table->engine('InnoDB');
            $table->charset('utf8mb4');
            $table->collation('utf8mb4_unicode_ci');
            $table->id();
            $table->string('code', 32)->collation('utf8mb4_bin')->unique();
            $table->string('name', 100);
            $table->dateTime('created_at', 6)->nullable();
            $table->dateTime('updated_at', 6)->nullable();
        });

        DB::statement("ALTER TABLE roles ADD CONSTRAINT chk_roles_code CHECK (code IN ('member', 'staff', 'admin'))");

    }

    public function down(): void
    {
        Schema::dropIfExists('roles');
    }
};
