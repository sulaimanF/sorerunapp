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
        DB::statement("ALTER TABLE roles DROP CHECK chk_roles_code, ADD CONSTRAINT chk_roles_code CHECK (code IN ('member', 'staff', 'admin', 'super_admin'))");
        DB::table('roles')->insert([
            'code' => 'super_admin', 'name' => 'Super Admin', 'created_at' => now('UTC'), 'updated_at' => now('UTC'),
        ]);

        Schema::create('admin_provisionings', function (Blueprint $table): void {
            $table->engine('InnoDB');
            $table->charset('utf8mb4');
            $table->collation('utf8mb4_unicode_ci');
            $table->id();
            $table->foreignId('user_id')->unique()->constrained('users')->restrictOnDelete();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->uuid('request_key');
            $table->char('payload_hash', 64)->collation('utf8mb4_bin');
            $table->dateTime('activated_at', 6)->nullable();
            $table->timestamps(6);
            $table->unique(['created_by', 'request_key'], 'uq_admin_provisioning_request');
        });
        Schema::create('admin_invitations', function (Blueprint $table): void {
            $table->engine('InnoDB');
            $table->charset('utf8mb4');
            $table->collation('utf8mb4_unicode_ci');
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->string('email', 254);
            $table->char('token_hash', 64)->collation('utf8mb4_bin');
            $table->unsignedInteger('user_version');
            $table->dateTime('expires_at', 6);
            $table->dateTime('consumed_at', 6)->nullable();
            $table->dateTime('revoked_at', 6)->nullable();
            $table->timestamps(6);
            $table->index(['user_id', 'created_at'], 'idx_admin_invitation_user_time');
        });
        Schema::create('security_bootstraps', function (Blueprint $table): void {
            $table->string('name', 64)->primary();
            $table->foreignId('user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->dateTime('completed_at', 6)->nullable();
        });
        DB::table('security_bootstraps')->insert(['name' => 'first_super_admin']);
    }

    public function down(): void
    {
        // Rollback must not silently remove operational accounts or the bootstrap fence.
        if (DB::table('admin_provisionings')->exists()
            || DB::table('security_bootstraps')->whereNotNull('completed_at')->exists()
            || DB::table('user_roles')->whereIn('role_id', DB::table('roles')->where('code', 'super_admin')->select('id'))->exists()) {
            throw new RuntimeException('Administrative data exists; reviewed data migration is required before rollback.');
        }
        Schema::dropIfExists('security_bootstraps');
        Schema::dropIfExists('admin_invitations');
        Schema::dropIfExists('admin_provisionings');
        DB::table('roles')->where('code', 'super_admin')->delete();
        DB::statement("ALTER TABLE roles DROP CHECK chk_roles_code, ADD CONSTRAINT chk_roles_code CHECK (code IN ('member', 'staff', 'admin'))");
    }
};
