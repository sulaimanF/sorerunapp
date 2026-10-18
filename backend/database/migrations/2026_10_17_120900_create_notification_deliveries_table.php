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
        Schema::create('notification_deliveries', function (Blueprint $table): void {
            $table->engine('InnoDB');
            $table->charset('utf8mb4');
            $table->collation('utf8mb4_unicode_ci');
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('type', 64)->collation('utf8mb4_bin');
            $table->string('dedupe_key', 191)->collation('utf8mb4_bin')->unique();
            $table->string('status', 16)->collation('utf8mb4_bin')->default('pending');
            $table->unsignedInteger('attempt_count')->default(0);
            $table->dateTime('available_at', 6);
            $table->dateTime('lease_until', 6)->nullable();
            $table->dateTime('sent_at', 6)->nullable();
            $table->string('provider_reference', 255)->nullable();
            $table->string('last_error_code', 100)->nullable();
            $table->index(['status', 'available_at'], 'idx_delivery_status_available');
            $table->index(['status', 'lease_until'], 'idx_delivery_status_lease');
            $table->dateTime('created_at', 6)->nullable();
            $table->dateTime('updated_at', 6)->nullable();
        });

        DB::statement("ALTER TABLE notification_deliveries ADD CONSTRAINT chk_delivery_status CHECK (status IN ('pending', 'processing', 'sent', 'failed', 'cancelled'))");

    }

    public function down(): void
    {
        Schema::dropIfExists('notification_deliveries');
    }
};
