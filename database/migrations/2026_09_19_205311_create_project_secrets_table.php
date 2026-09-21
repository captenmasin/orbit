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
        Schema::create('project_secrets', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('project_id')->constrained()->cascadeOnDelete();
            $table->string('environment', 100);
            $table->string('name', 255);
            $table->text('ciphertext');
            $table->unsignedInteger('revision')->default(1);
            $table->timestamps();
            $table->unique(['project_id', 'environment', 'name']);
        });

        Schema::table('credential_access_events', function (Blueprint $table): void {
            $table->uuid('secret_id')->nullable()->index();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('credential_access_events', function (Blueprint $table): void {
            $table->dropIndex(['secret_id']);
            $table->dropColumn('secret_id');
        });
        Schema::dropIfExists('project_secrets');
    }
};
