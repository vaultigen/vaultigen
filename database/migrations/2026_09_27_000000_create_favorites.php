<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('favorite_vaults', function (Blueprint $table): void {
            $table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignUuid('vault_id')->constrained('vaults')->cascadeOnDelete();
            $table->primary(['user_id', 'vault_id']);
        });

        Schema::create('favorite_entries', function (Blueprint $table): void {
            $table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignUuid('entry_id')->constrained('entries')->cascadeOnDelete();
            $table->primary(['user_id', 'entry_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('favorite_entries');
        Schema::dropIfExists('favorite_vaults');
    }
};
