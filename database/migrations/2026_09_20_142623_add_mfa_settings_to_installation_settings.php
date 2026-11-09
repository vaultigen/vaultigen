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
            $table->json('mfa_methods')->nullable();
            $table->unsignedSmallInteger('remember_device_days')->default(0);
        });

        DB::table('installation_settings')->where('id', 1)->update([
            'mfa_methods' => json_encode(['app'], JSON_THROW_ON_ERROR),
        ]);
    }

    public function down(): void
    {
        Schema::table('installation_settings', function (Blueprint $table): void {
            $table->dropColumn(['mfa_methods', 'remember_device_days']);
        });
    }
};
