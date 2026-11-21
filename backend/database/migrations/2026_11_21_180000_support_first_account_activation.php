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
        Schema::table('admin_invitations', function (Blueprint $table): void {
            $table->string('purpose', 32)->default('admin_invitation')->after('id');
            $table->unsignedBigInteger('created_by')->nullable()->change();
            $table->index(['purpose', 'user_id'], 'idx_invitation_purpose_user');
        });
        DB::statement("ALTER TABLE admin_invitations ADD CONSTRAINT chk_invitation_purpose CHECK (purpose IN ('admin_invitation', 'first_account_activation'))");
        DB::statement("ALTER TABLE admin_invitations ADD CONSTRAINT chk_invitation_issuer CHECK ((purpose = 'admin_invitation' AND created_by IS NOT NULL) OR (purpose = 'first_account_activation' AND created_by IS NULL))");
    }

    public function down(): void
    {
        if (DB::table('admin_invitations')->where('purpose', 'first_account_activation')->exists()) {
            throw new RuntimeException('Initial activation history exists; reviewed data migration is required before rollback.');
        }
        DB::statement('ALTER TABLE admin_invitations DROP CHECK chk_invitation_issuer, DROP CHECK chk_invitation_purpose');
        Schema::table('admin_invitations', function (Blueprint $table): void {
            $table->dropIndex('idx_invitation_purpose_user');
            $table->dropColumn('purpose');
            $table->unsignedBigInteger('created_by')->nullable(false)->change();
        });
    }
};
