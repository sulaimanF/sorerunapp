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
        Schema::create('member_credentials', function (Blueprint $table): void {
            $table->engine('InnoDB');
            $table->charset('utf8mb4');
            $table->collation('utf8mb4_unicode_ci');
            $table->id();
            $table->foreignId('membership_id')->constrained('memberships')->restrictOnDelete()->restrictOnUpdate();
            $table->binary('token_hash', length: 32, fixed: true)->unique('uq_credential_hash');
            $table->text('token_encrypted')->nullable();
            $table->dateTime('issued_at', 6);
            $table->dateTime('revoked_at', 6)->nullable();
            $table->foreignId('issued_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('revoked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('revocation_reason', 255)->nullable();
            $table->unsignedBigInteger('active_membership_id')
                ->nullable()
                ->storedAs('CASE WHEN revoked_at IS NULL THEN membership_id ELSE NULL END');
            $table->unique('active_membership_id', 'uq_one_active_credential');
            $table->dateTime('created_at', 6)->nullable();
            $table->dateTime('updated_at', 6)->nullable();
        });

        DB::statement('ALTER TABLE member_credentials ADD CONSTRAINT chk_credential_active_ciphertext CHECK (revoked_at IS NOT NULL OR (token_encrypted IS NOT NULL AND CHAR_LENGTH(token_encrypted) > 0)), ADD CONSTRAINT chk_credential_revocation_time CHECK (revoked_at IS NULL OR revoked_at >= issued_at)');

    }

    public function down(): void
    {
        Schema::dropIfExists('member_credentials');
    }
};
