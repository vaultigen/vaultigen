<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('installation_settings', function (Blueprint $table): void {
            $table->unsignedSmallInteger('audit_retention_days')->default(365);
            $table->unsignedSmallInteger('audit_ip_retention_days')->default(30);
        });

        Schema::table('audit_events', function (Blueprint $table): void {
            // Redacted, hash-covered metadata. The canonical event is rebuilt from
            // these columns during verification, so tampering is detected.
            $table->string('actor_name_snapshot', 255)->nullable()->after('actor_id');
            $table->uuid('vault_id')->nullable()->after('outcome');
            $table->string('target_type', 60)->nullable()->after('vault_id');
            $table->uuid('target_id')->nullable()->after('target_type');
            $table->string('source_channel', 20)->nullable()->after('target_id');
            $table->longText('sanitized_context')->nullable()->after('source_channel');
            $table->index('vault_id');
        });

        Schema::table('audit_chain_heads', function (Blueprint $table): void {
            // After controlled pruning the surviving chain is verified from the
            // recorded anchor (the last deleted event) instead of the zero hash.
            $table->unsignedBigInteger('anchor_sequence')->default(0);
            $table->string('anchor_hash', 64)->default(str_repeat('0', 64));
        });

        // IP address and user agent are deliberately kept outside the hash chain
        // so privacy retention can redact them without breaking verification.
        Schema::create('audit_event_metadata', function (Blueprint $table): void {
            $table->unsignedBigInteger('sequence')->primary();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->timestamp('created_at')->nullable();
        });

        // Externally exportable keyed checkpoint for a pruned range of events.
        Schema::create('audit_prune_checkpoints', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->unsignedBigInteger('first_sequence');
            $table->unsignedBigInteger('last_sequence');
            $table->string('final_event_hash', 64);
            $table->string('key_id', 50);
            $table->string('checkpoint_mac', 64);
            $table->string('reason', 30);
            $table->timestamp('created_at');
        });

        Schema::create('emergency_access_sessions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('super_admin_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignUuid('vault_id')->constrained('vaults')->restrictOnDelete();
            $table->string('reason', 1000);
            $table->string('incident_reference', 255)->nullable();
            $table->string('authentication_method', 30);
            $table->timestamp('authenticated_at');
            $table->timestamp('started_at');
            $table->timestamp('expires_at')->index();
            $table->timestamp('closed_at')->nullable();
            $table->foreignUuid('closed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedBigInteger('notification_sequence')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('emergency_access_sessions');
        Schema::dropIfExists('audit_prune_checkpoints');
        Schema::dropIfExists('audit_event_metadata');
        Schema::table('audit_chain_heads', function (Blueprint $table): void {
            $table->dropColumn(['anchor_sequence', 'anchor_hash']);
        });
        Schema::table('audit_events', function (Blueprint $table): void {
            $table->dropIndex(['vault_id']);
            $table->dropColumn(['actor_name_snapshot', 'vault_id', 'target_type', 'target_id', 'source_channel', 'sanitized_context']);
        });
        Schema::table('installation_settings', function (Blueprint $table): void {
            $table->dropColumn(['audit_retention_days', 'audit_ip_retention_days']);
        });
    }
};
