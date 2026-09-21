<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('provider_connections', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('provider');
            $table->string('label');
            $table->string('account_id');
            $table->string('login');
            $table->text('encrypted_token');
            $table->unsignedInteger('revision')->default(1);
            $table->string('state')->default('Current');
            $table->timestamp('verified_at')->nullable();
            $table->timestamp('retry_at')->nullable();
            $table->timestamps();
        });
        Schema::table('repositories', function (Blueprint $table): void {
            $table->foreignUuid('provider_connection_id')->nullable()->constrained()->nullOnDelete();
            $table->string('provider_repository_id')->nullable();
            $table->string('provider_name')->nullable();
            $table->string('default_branch')->nullable();
            $table->string('provider_url', 2048)->nullable();
            $table->unsignedInteger('provider_revision')->default(1);
            $table->timestamp('remote_commit_at')->nullable();
        });
        Schema::create('provider_snapshots', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('repository_id')->constrained()->cascadeOnDelete();
            $table->string('resource');
            $table->json('payload')->nullable();
            $table->string('etag', 512)->nullable();
            $table->unsignedInteger('next_page')->nullable();
            $table->unsignedInteger('requested_page')->default(1);
            $table->string('state')->default('Not scanned');
            $table->string('error')->nullable();
            $table->uuid('request_token')->nullable();
            $table->timestamp('attempted_at')->nullable();
            $table->timestamp('checked_at')->nullable();
            $table->timestamp('succeeded_at')->nullable();
            $table->timestamps();
            $table->unique(['repository_id', 'resource']);
        });
        Schema::create('credential_access_events', function (Blueprint $table): void {
            $table->id();
            $table->uuid('connection_id')->nullable();
            $table->string('operation');
            $table->string('result');
            $table->timestamp('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('credential_access_events');
        Schema::dropIfExists('provider_snapshots');
        Schema::table('repositories', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('provider_connection_id');
            $table->dropColumn(['provider_repository_id', 'provider_name', 'default_branch', 'provider_url', 'provider_revision', 'remote_commit_at']);
        });
        Schema::dropIfExists('provider_connections');
    }
};
