<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('installation_settings', function (Blueprint $table): void {
            $table->unsignedSmallInteger('import_retention_days')->default(30);
        });
    }

    public function down(): void
    {
        Schema::table('installation_settings', function (Blueprint $table): void {
            $table->dropColumn('import_retention_days');
        });
    }
};
