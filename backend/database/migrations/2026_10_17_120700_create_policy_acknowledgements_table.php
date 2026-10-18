<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('policy_acknowledgements', function (Blueprint $table): void {
            $table->engine('InnoDB');
            $table->charset('utf8mb4');
            $table->collation('utf8mb4_unicode_ci');
            $table->id();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->string('policy_type', 64)->collation('utf8mb4_bin');
            $table->string('policy_version', 64)->collation('utf8mb4_bin');
            $table->dateTime('acknowledged_at', 6);
            $table->index(['user_id', 'policy_type', 'policy_version'], 'idx_policy_user_type_version');
        });

    }

    public function down(): void
    {
        Schema::dropIfExists('policy_acknowledgements');
    }
};
