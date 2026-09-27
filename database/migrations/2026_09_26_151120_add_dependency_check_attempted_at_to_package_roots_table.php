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
        Schema::table('package_roots', function (Blueprint $table): void {
            $table->timestamp('dependency_check_attempted_at')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('package_roots', function (Blueprint $table): void {
            $table->dropColumn('dependency_check_attempted_at');
        });
    }
};
