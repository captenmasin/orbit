<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('project_folders', function (Blueprint $table) {
            $table->text('git_root')->nullable();
            $table->text('git_remote')->nullable();
            $table->text('commit_subject')->nullable();
            $table->string('scan_state')->default('Not scanned');
            $table->string('scan_error')->nullable();
            $table->uuid('scan_token')->nullable();
            $table->unsignedBigInteger('scan_job_id')->nullable();
            $table->timestamp('scan_attempted_at')->nullable();
            $table->timestamp('scan_started_at')->nullable();
        });
        Schema::create('package_roots', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('project_folder_id')->constrained()->cascadeOnDelete();
            $table->text('relative_path');
            $table->json('executable_overrides')->nullable();
            $table->unsignedInteger('revision')->default(1);
            $table->json('snapshot')->nullable();
            $table->timestamp('scanned_at')->nullable();
            $table->string('scan_state')->default('Not scanned');
            $table->string('scan_error')->nullable();
            $table->uuid('scan_token')->nullable();
            $table->unsignedBigInteger('scan_job_id')->nullable();
            $table->timestamp('scan_attempted_at')->nullable();
            $table->timestamp('scan_started_at')->nullable();
            $table->timestamps();
            $table->unique(['project_folder_id', 'relative_path']);
        });
        DB::table('project_folders')->orderBy('id')->chunkById(100, function ($folders) {
            foreach ($folders as $folder) {
                DB::table('package_roots')->insert([
                    'id' => (string) Str::uuid7(), 'project_folder_id' => $folder->id, 'relative_path' => '.',
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('package_roots');
        Schema::table('project_folders', fn (Blueprint $table) => $table->dropColumn([
            'git_root', 'git_remote', 'commit_subject', 'scan_state', 'scan_error', 'scan_token',
            'scan_job_id', 'scan_attempted_at', 'scan_started_at',
        ]));
    }
};
