<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('installation_settings', function (Blueprint $table): void {
            $table->unsignedSmallInteger('vault_purge_retention_days')->default(30);
            $table->boolean('vault_purge_notifications_enabled')->default(true);
        });

        Schema::table('vaults', function (Blueprint $table): void {
            $table->string('purge_previous_state', 30)->nullable();
        });

        // Vaults marked before automatic deletion existed were promised a separate
        // confirmation, so they get a full waiting period instead of an instant purge.
        DB::table('vaults')->where('state', 'pending_purge')->update([
            'purge_after' => now()->addDays(30),
            'purge_previous_state' => 'archived',
        ]);
    }

    public function down(): void
    {
        Schema::table('vaults', function (Blueprint $table): void {
            $table->dropColumn('purge_previous_state');
        });

        Schema::table('installation_settings', function (Blueprint $table): void {
            $table->dropColumn(['vault_purge_retention_days', 'vault_purge_notifications_enabled']);
        });
    }
};
