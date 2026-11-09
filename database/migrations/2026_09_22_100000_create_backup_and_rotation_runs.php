<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Non-secret operation status only. The backup set itself lives outside
        // the database under a random directory; this row is the UI's view of
        // what happened, never a key or a plaintext value.
        Schema::create('backup_runs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('state', 20)->default('pending');
            $table->string('trigger', 20)->default('manual');
            $table->foreignUuid('requested_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('path', 255)->nullable();
            $table->char('manifest_checksum', 64)->nullable();
            $table->string('database_driver', 20)->nullable();
            $table->unsignedInteger('object_count')->default(0);
            $table->unsignedBigInteger('total_bytes')->default(0);
            $table->string('app_version', 50)->nullable();
            $table->string('schema_version', 50)->nullable();
            $table->string('error_code', 60)->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->timestamps();
            $table->index(['state'], 'ix_backup_runs_state');
        });

        // One rewrap or vault-key rotation attempt. `cursor` is an opaque,
        // restartable position; counters describe progress; every column is
        // non-secret metadata.
        Schema::create('key_rotation_runs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('operation', 40);
            $table->foreignUuid('vault_id')->nullable()->constrained('vaults')->nullOnDelete();
            $table->string('source_key_id', 64);
            $table->string('target_key_id', 64);
            $table->string('status', 20)->default('pending');
            $table->string('cursor', 100)->nullable();
            $table->unsignedInteger('processed_count')->default(0);
            $table->unsignedInteger('failed_count')->default(0);
            $table->foreignUuid('started_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->string('error_code', 60)->nullable();
            $table->timestamps();
        });

        // A singleton write-quiescence window. While `active` is true the HTTP
        // write middleware and the scheduled commands refuse new mutations so a
        // backup or rotation sees a consistent database and object set.
        Schema::create('maintenance_windows', function (Blueprint $table): void {
            $table->unsignedTinyInteger('id')->primary();
            $table->boolean('active')->default(false);
            $table->string('operation', 40)->nullable();
            $table->uuid('operation_id')->nullable();
            $table->string('reason', 120)->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('ended_at')->nullable();
        });

        DB::table('maintenance_windows')->insert(['id' => 1, 'active' => false]);
    }

    public function down(): void
    {
        Schema::dropIfExists('maintenance_windows');
        Schema::dropIfExists('key_rotation_runs');
        Schema::dropIfExists('backup_runs');
    }
};
