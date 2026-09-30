<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['project_links', 'project_documents'] as $name) {
            Schema::table($name, function (Blueprint $table): void {
                $table->boolean('important')->default(false);
            });
        }
    }

    public function down(): void
    {
        foreach (['project_links', 'project_documents'] as $name) {
            Schema::table($name, function (Blueprint $table): void {
                $table->dropColumn('important');
            });
        }
    }
};
