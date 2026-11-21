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
        Schema::create('landing_pages', function (Blueprint $table): void {
            $table->engine('InnoDB');
            $table->charset('utf8mb4');
            $table->collation('utf8mb4_unicode_ci');
            $table->unsignedBigInteger('id')->primary();
            $table->json('draft')->nullable();
            $table->unsignedInteger('draft_version')->default(0);
            $table->unsignedBigInteger('published_revision_id')->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps(6);
        });
        DB::statement('ALTER TABLE landing_pages ADD CONSTRAINT chk_landing_singleton CHECK (id = 1)');
        DB::table('landing_pages')->insert(['id' => 1, 'created_at' => now('UTC'), 'updated_at' => now('UTC')]);

        Schema::create('landing_page_revisions', function (Blueprint $table): void {
            $table->engine('InnoDB');
            $table->charset('utf8mb4');
            $table->collation('utf8mb4_unicode_ci');
            $table->id();
            $table->foreignId('landing_page_id')->constrained('landing_pages')->restrictOnDelete();
            $table->unsignedInteger('draft_version');
            $table->json('content');
            $table->foreignId('published_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('published_at', 6);
            $table->unique(['landing_page_id', 'draft_version'], 'uq_landing_publication_version');
        });
        Schema::table('landing_pages', function (Blueprint $table): void {
            $table->foreign('published_revision_id')->references('id')->on('landing_page_revisions')->restrictOnDelete();
        });

        Schema::create('landing_page_media', function (Blueprint $table): void {
            $table->engine('InnoDB');
            $table->charset('utf8mb4');
            $table->collation('utf8mb4_unicode_ci');
            $table->id();
            $table->uuid('public_id')->unique();
            $table->string('path', 255)->unique();
            $table->string('mime', 32);
            $table->unsignedInteger('size');
            $table->unsignedInteger('width');
            $table->unsignedInteger('height');
            $table->string('state', 16)->default('ready');
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('created_at', 6);
        });
        DB::statement("ALTER TABLE landing_page_media ADD CONSTRAINT chk_landing_media_state CHECK (state IN ('ready', 'deleting'))");

        Schema::create('landing_page_revision_media', function (Blueprint $table): void {
            $table->engine('InnoDB');
            $table->charset('utf8mb4');
            $table->collation('utf8mb4_unicode_ci');
            $table->foreignId('revision_id')->constrained('landing_page_revisions')->restrictOnDelete();
            $table->foreignId('media_id')->constrained('landing_page_media')->restrictOnDelete();
            $table->primary(['revision_id', 'media_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('landing_page_revision_media');
        Schema::table('landing_pages', fn (Blueprint $table) => $table->dropForeign(['published_revision_id']));
        Schema::dropIfExists('landing_page_revisions');
        Schema::dropIfExists('landing_page_media');
        Schema::dropIfExists('landing_pages');
    }
};
