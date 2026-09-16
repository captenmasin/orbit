<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->string('icon_type')->default('initials');
            $table->string('icon_emoji', 32)->nullable();
            $table->string('icon_path')->nullable();
            $table->timestamp('archived_at')->nullable();
            $table->string('previous_status')->nullable();
        });

        Schema::create('tags', function (Blueprint $table) {
            $table->id();
            $table->string('name', 50)->unique();
        });
        Schema::create('project_tag', function (Blueprint $table) {
            $table->foreignUuid('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('tag_id')->constrained()->cascadeOnDelete();
            $table->primary(['project_id', 'tag_id']);
        });
        Schema::create('repositories', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('project_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->text('remote_url');
            $table->timestamps();
            $table->unique(['id', 'project_id']);
            $table->unique(['project_id', 'remote_url']);
        });
        Schema::create('project_folders', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('project_id')->constrained()->cascadeOnDelete();
            $table->uuid('repository_id')->nullable();
            $table->text('path');
            $table->string('git_state')->default('Not a Git repository');
            $table->string('branch')->nullable();
            $table->string('last_commit_hash', 64)->nullable();
            $table->timestamp('last_commit_at')->nullable();
            $table->timestamp('scanned_at')->nullable();
            $table->timestamps();
            $table->foreign(['repository_id', 'project_id'])->references(['id', 'project_id'])->on('repositories');
            $table->unique(['project_id', 'path']);
        });
        Schema::create('project_links', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('project_id')->constrained()->cascadeOnDelete();
            $table->string('label');
            $table->text('url');
            $table->string('category', 50)->nullable();
            $table->string('icon', 32)->nullable();
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();
        });

        DB::table('projects')->where('status', 'Archived')->update(['archived_at' => now()]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('project_links');
        Schema::dropIfExists('project_folders');
        Schema::dropIfExists('repositories');
        Schema::dropIfExists('project_tag');
        Schema::dropIfExists('tags');
        Schema::table('projects', fn (Blueprint $table) => $table->dropColumn([
            'icon_type', 'icon_emoji', 'icon_path', 'archived_at', 'previous_status',
        ]));
    }
};
