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
        // Fail before changing schema if an existing email would be truncated.
        if (DB::table('users')->whereRaw('CHAR_LENGTH(email) > 254')->exists()) {
            throw new RuntimeException('Resolve users.email longer than 254 characters before this migration.');
        }

        Schema::table('users', function (Blueprint $table): void {
            $table->string('email', 254)->change();
            $table->dateTime('email_verified_at', 6)->nullable()->change();
            $table->dateTime('created_at', 6)->nullable()->change();
            $table->dateTime('updated_at', 6)->nullable()->change();
            $table->string('status', 16)->collation('utf8mb4_bin')->default('enabled')->index();
            $table->unsignedInteger('auth_version')->default(1);
            $table->dateTime('blocked_at', 6)->nullable();
        });

        DB::statement("ALTER TABLE users ADD CONSTRAINT chk_users_status CHECK (status IN ('enabled', 'blocked')), ADD CONSTRAINT chk_users_auth_version CHECK (auth_version >= 1)");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE users DROP CHECK chk_users_status, DROP CHECK chk_users_auth_version');

        Schema::table('users', function (Blueprint $table): void {
            $table->dropIndex(['status']);
            $table->dropColumn(['status', 'auth_version', 'blocked_at']);
            $table->string('email', 255)->change();
            // Preserve DATETIME(6) to avoid losing precision during rollback.
        });
    }
};
