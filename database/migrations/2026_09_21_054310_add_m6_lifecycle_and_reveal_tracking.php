<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('installation_settings', function (Blueprint $table): void {
            $table->unsignedSmallInteger('trash_retention_days')->default(30);
            $table->unsignedSmallInteger('revision_retention_count')->default(50);
            $table->unsignedInteger('revision_retention_days')->default(365);
        });

        Schema::table('vaults', function (Blueprint $table): void {
            $table->timestamp('purge_after')->nullable();
        });

        Schema::create('secret_reveals', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained('users');
            $table->uuid('vault_id');
            $table->uuid('entry_id');
            $table->unsignedInteger('reveal_count')->default(1);
            $table->timestamp('first_revealed_at');
            $table->timestamp('last_revealed_at');
            $table->unique(['user_id', 'entry_id'], 'uk_secret_reveals_user_entry');
            $table->index(['user_id', 'last_revealed_at'], 'ix_secret_reveals_user_last');
            $table->foreign('vault_id')->references('id')->on('vaults');
            $table->foreign(['entry_id', 'vault_id'], 'fk_secret_reveals_entry_vault')
                ->references(['id', 'vault_id'])->on('entries')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('secret_reveals');

        Schema::table('vaults', function (Blueprint $table): void {
            $table->dropColumn('purge_after');
        });

        Schema::table('installation_settings', function (Blueprint $table): void {
            $table->dropColumn(['trash_retention_days', 'revision_retention_count', 'revision_retention_days']);
        });
    }
};
