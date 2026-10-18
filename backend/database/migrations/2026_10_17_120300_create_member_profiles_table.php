<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('member_profiles', function (Blueprint $table): void {
            $table->engine('InnoDB');
            $table->charset('utf8mb4');
            $table->collation('utf8mb4_unicode_ci');
            $table->id();
            $table->foreignId('user_id')->unique()->constrained('users')->restrictOnDelete();
            $table->string('full_name', 150)->index();
            $table->date('birth_date');
            $table->string('phone', 32);
            $table->string('city', 100)->index();
            $table->string('gender', 32)->nullable();
            $table->string('photo_path', 512)->nullable();
            $table->dateTime('created_at', 6)->nullable();
            $table->dateTime('updated_at', 6)->nullable();
        });

    }

    public function down(): void
    {
        Schema::dropIfExists('member_profiles');
    }
};
