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
        Schema::create('member_number_sequences', function (Blueprint $table): void {
            $table->engine('InnoDB');
            $table->charset('utf8mb4');
            $table->collation('utf8mb4_unicode_ci');
            $table->string('name', 32)->collation('utf8mb4_bin')->primary();
            $table->unsignedBigInteger('next_value');
        });

        DB::statement('ALTER TABLE member_number_sequences ADD CONSTRAINT chk_member_sequence_positive CHECK (next_value >= 1)');

        DB::table('member_number_sequences')->insert(['name' => 'membership', 'next_value' => 1]);

    }

    public function down(): void
    {
        Schema::dropIfExists('member_number_sequences');
    }
};
