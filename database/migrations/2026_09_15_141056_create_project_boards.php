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
        Schema::create('board_columns', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('project_id')->constrained()->cascadeOnDelete();
            $table->string('name', 100);
            $table->unsignedInteger('position');
            $table->timestamps();
            $table->index(['project_id', 'position']);
        });
        Schema::create('tasks', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('board_column_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->text('description')->nullable();
            $table->unsignedInteger('position');
            $table->timestamps();
            $table->index(['board_column_id', 'position']);
        });
        DB::table('projects')->orderBy('id')->each(function ($project) {
            foreach (['Backlog', 'To Do', 'In Progress', 'Done'] as $position => $name) {
                DB::table('board_columns')->insert([
                    'id' => (string) Str::uuid7(), 'project_id' => $project->id,
                    'name' => $name, 'position' => $position, 'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tasks');
        Schema::dropIfExists('board_columns');
    }
};
