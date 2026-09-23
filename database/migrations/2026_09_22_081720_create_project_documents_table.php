<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('project_documents', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('project_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->text('body');
            $table->unsignedInteger('position')->default(0);
            $table->unsignedInteger('revision')->default(1);
            $table->timestamps();
        });
        Schema::table('projects', function (Blueprint $table): void {
            $table->date('reviewed_at')->nullable();
        });
        DB::transaction(function (): void {
            foreach (DB::table('projects')->whereNotNull('notes')->where('notes', '!=', '')->get() as $project) {
                DB::table('project_documents')->insert([
                    'id' => (string) Str::uuid7(), 'project_id' => $project->id, 'title' => 'Notes', 'body' => $project->notes,
                    'position' => 0, 'revision' => 1, 'created_at' => $project->created_at, 'updated_at' => $project->updated_at,
                ]);
            }
            DB::table('projects')->update(['notes' => null]);
        });
    }

    public function down(): void
    {
        foreach (DB::table('projects')->pluck('id') as $id) {
            $documents = DB::table('project_documents')->where('project_id', $id)->orderBy('position')->orderBy('id')->get();
            if ($documents->isNotEmpty()) {
                $notes = $documents->count() === 1 && $documents->first()->title === 'Notes'
                    ? $documents->first()->body
                    : $documents->map(fn (object $document): string => '# '.$document->title."\n\n".$document->body)->implode("\n\n");
                DB::table('projects')->where('id', $id)->update(['notes' => $notes]);
            }
        }
        Schema::dropIfExists('project_documents');
        Schema::table('projects', function (Blueprint $table): void {
            $table->dropColumn('reviewed_at');
        });
    }
};
