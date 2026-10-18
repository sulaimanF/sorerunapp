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
        Schema::create('memberships', function (Blueprint $table): void {
            $table->engine('InnoDB');
            $table->charset('utf8mb4');
            $table->collation('utf8mb4_unicode_ci');
            $table->id();
            $table->foreignId('user_id')->nullable()->unique()->constrained('users')->restrictOnDelete();
            $table->string('member_number', 32)->collation('utf8mb4_bin')->nullable()->unique();
            $table->string('status', 32)->collation('utf8mb4_bin')->default('pending_verification');
            $table->dateTime('joined_at', 6)->nullable();
            $table->dateTime('suspended_at', 6)->nullable();
            $table->dateTime('ended_at', 6)->nullable()->index();
            $table->dateTime('anonymized_at', 6)->nullable();
            $table->text('status_reason')->nullable();
            $table->index(['status', 'joined_at']);
            $table->dateTime('created_at', 6)->nullable();
            $table->dateTime('updated_at', 6)->nullable();
        });

        DB::statement("ALTER TABLE memberships ADD CONSTRAINT chk_memberships_status CHECK (status IN ('pending_verification', 'active', 'suspended', 'ended')), ADD CONSTRAINT chk_memberships_issued_number CHECK (status = 'pending_verification' OR (member_number IS NOT NULL AND joined_at IS NOT NULL)), ADD CONSTRAINT chk_memberships_anonymized CHECK (anonymized_at IS NULL OR (user_id IS NULL AND status = 'ended' AND member_number IS NOT NULL AND status_reason IS NULL))");

    }

    public function down(): void
    {
        Schema::dropIfExists('memberships');
    }
};
