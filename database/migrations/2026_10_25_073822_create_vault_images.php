<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vault_images', function (Blueprint $table): void {
            $table->foreignUuid('vault_id')->primary()->constrained('vaults')->cascadeOnDelete();
            $table->string('mime', 64);
            $table->mediumText('data');
            $table->string('checksum', 64);
            $table->timestamps();
        });

        // Personal work vaults were provisioned with the briefcase icon before a
        // person icon existed; move them (and any without an icon) to it.
        DB::table('vaults')
            ->where('kind', 'private_workspace')
            ->where(fn ($query) => $query->whereNull('icon')->orWhere('icon', 'briefcase'))
            ->update(['icon' => 'user']);
    }

    public function down(): void
    {
        Schema::dropIfExists('vault_images');
    }
};
