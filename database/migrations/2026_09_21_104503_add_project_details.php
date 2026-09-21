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
        Schema::table('projects', function (Blueprint $table): void {
            $table->text('notes')->nullable();
            $table->json('asset_files')->nullable();
            $table->unsignedInteger('position')->default(0)->index();
        });
        foreach (DB::table('projects')->orderByRaw('name COLLATE NOCASE')->orderBy('id')->pluck('id') as $position => $id) {
            DB::table('projects')->where('id', $id)->update(['position' => $position]);
        }
        Schema::table('package_roots', function (Blueprint $table): void {
            $table->json('outdated')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table): void {
            $table->dropIndex(['position']);
            $table->dropColumn(['notes', 'asset_files', 'position']);
        });
        Schema::table('package_roots', function (Blueprint $table): void {
            $table->dropColumn('outdated');
        });
    }
};
