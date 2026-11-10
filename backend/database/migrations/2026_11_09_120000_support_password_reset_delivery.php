<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('password_reset_tokens', function (Blueprint $table): void {
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedInteger('auth_version')->nullable();
        });
        Schema::table('notification_deliveries', function (Blueprint $table): void {
            $table->text('payload_encrypted')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('notification_deliveries', fn (Blueprint $table) => $table->dropColumn('payload_encrypted'));
        Schema::table('password_reset_tokens', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('user_id');
            $table->dropColumn('auth_version');
        });
    }
};
