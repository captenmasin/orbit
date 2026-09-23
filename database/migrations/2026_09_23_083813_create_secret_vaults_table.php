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
        Schema::dropIfExists('passkeys');
        if (Schema::hasColumn('users', 'orbit_account_key')) {
            DB::table('users')->where('orbit_account_key', 'owner')->delete();
            Schema::table('users', function (Blueprint $table) {
                $table->dropUnique(['orbit_account_key']);
                $table->dropColumn(['orbit_account_key', 'passkey_handle']);
            });
        }
        Schema::create('secret_vaults', function (Blueprint $table) {
            $table->id();
            $table->string('pin_hash');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('secret_vaults');
    }
};
