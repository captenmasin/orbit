<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('restore_states', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('phase');
            $table->string('source_hash', 64);
            $table->string('snapshot_path');
            $table->string('asset_prefix')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('restore_states');
    }
};
