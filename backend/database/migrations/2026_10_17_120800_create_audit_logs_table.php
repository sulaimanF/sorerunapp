<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $table): void {
            $table->engine('InnoDB');
            $table->charset('utf8mb4');
            $table->collation('utf8mb4_unicode_ci');
            $table->id();
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('action', 100)->collation('utf8mb4_bin');
            $table->string('target_type', 100)->collation('utf8mb4_bin');
            $table->unsignedBigInteger('target_id')->nullable();
            $table->dateTime('occurred_at', 6);
            $table->string('reason_code', 64)->nullable();
            $table->text('reason_text')->nullable();
            $table->json('before')->nullable();
            $table->json('after')->nullable();
            $table->string('request_id', 64)->nullable()->index();
            $table->index(['target_type', 'target_id', 'occurred_at'], 'idx_audit_target_time');
            $table->index(['actor_user_id', 'occurred_at'], 'idx_audit_actor_time');
        });

    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
    }
};
