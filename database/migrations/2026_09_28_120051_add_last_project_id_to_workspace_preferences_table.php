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
        Schema::table('workspace_preferences', function (Blueprint $table) {
            $table->string('last_project_id')->nullable();
        });
        $record = DB::table('workspace_preferences')->where('id', 1)->first();
        $values = json_decode($record?->values ?? '{}', true, flags: JSON_THROW_ON_ERROR);
        DB::table('workspace_preferences')->where('id', 1)->update(['last_project_id' => $values['startup']['last_project_id'] ?? null]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('workspace_preferences', function (Blueprint $table) {
            $table->dropColumn('last_project_id');
        });
    }
};
