<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('site_icons', function (Blueprint $table): void {
            $table->string('host')->primary();
            $table->string('state', 16)->default('pending')->index();
            $table->string('mime', 64)->nullable();
            $table->mediumText('data')->nullable();
            $table->string('checksum', 64)->nullable();
            $table->unsignedSmallInteger('failures')->default(0);
            $table->timestamp('claimed_at')->nullable();
            $table->timestamp('checked_at')->nullable();
            $table->timestamp('next_check_at')->nullable()->index();
            $table->timestamps();
        });

        Schema::table('installation_settings', function (Blueprint $table): void {
            $table->boolean('site_icons_enabled')->default(true);
        });
    }

    public function down(): void
    {
        Schema::table('installation_settings', function (Blueprint $table): void {
            $table->dropColumn('site_icons_enabled');
        });

        Schema::dropIfExists('site_icons');
    }
};
