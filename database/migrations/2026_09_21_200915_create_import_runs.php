<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('import_runs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('requested_by_user_id')->constrained('users')->restrictOnDelete();
            $table->string('source_format', 30);
            $table->char('source_fingerprint', 64);
            $table->string('original_filename', 255)->nullable();
            $table->string('status', 20)->default('staging');
            $table->string('random_storage_path', 255)->nullable();
            $table->unsignedSmallInteger('envelope_format_version')->nullable();
            $table->string('import_key_algorithm', 60)->nullable();
            $table->string('import_key_nonce', 64)->nullable();
            $table->string('import_key_key_id', 60)->nullable();
            $table->longText('wrapped_import_key')->nullable();
            $table->string('layout', 30)->nullable();
            $table->json('axis_config')->nullable();
            $table->json('plan')->nullable();
            $table->json('counters')->nullable();
            $table->json('created_vault_ids')->nullable();
            $table->json('failures')->nullable();
            $table->unsignedInteger('failure_count')->default(0);
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->timestamps();
            $table->index(['requested_by_user_id', 'status'], 'ix_import_runs_requester_status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('import_runs');
    }
};
