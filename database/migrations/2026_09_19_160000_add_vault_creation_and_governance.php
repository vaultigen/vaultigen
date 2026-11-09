<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('platform_roles')->insertOrIgnore([['name' => 'vault_creator']]);

        Schema::table('installation_settings', function (Blueprint $table): void {
            $table->string('vault_creation_policy', 30)->default('vault_creators');
            $table->unsignedSmallInteger('vault_creation_limit_per_user')->default(50);
            $table->unsignedInteger('vault_creation_limit_installation')->default(500);
        });
    }

    public function down(): void
    {
        Schema::table('installation_settings', function (Blueprint $table): void {
            $table->dropColumn(['vault_creation_policy', 'vault_creation_limit_per_user', 'vault_creation_limit_installation']);
        });

        DB::table('platform_roles')->where('name', 'vault_creator')->delete();
    }
};
