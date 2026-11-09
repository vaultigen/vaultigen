<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->createTable('file_attachments', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('vault_id')->constrained('vaults');
            $table->uuid('entry_id');
            $table->foreignUuid('vault_key_version_id');
            $table->longText('encrypted_metadata');
            $table->unsignedBigInteger('byte_size');
            $table->string('random_storage_path', 191);
            $table->unsignedSmallInteger('stream_format_version');
            $table->string('wrapped_file_key_algorithm', 40);
            $table->string('wrapped_file_key_nonce', 64);
            $table->longText('wrapped_file_key');
            $table->string('ciphertext_checksum', 64);
            $table->string('keyed_plaintext_fingerprint', 64)->nullable();
            $table->string('state', 20);
            $table->foreignUuid('created_by_user_id')->constrained('users');
            $table->timestamp('deleted_at')->nullable();
            $table->timestamp('purge_after')->nullable();
            $table->timestamps();
            $table->unique(['id', 'vault_id'], 'uk_file_attachments_id_vault');
            $table->unique('random_storage_path', 'uk_file_attachments_path');
            $table->index(['vault_id', 'state'], 'ix_file_attachments_vault_state');
            $table->index(['entry_id', 'deleted_at'], 'ix_file_attachments_entry_deleted');
            $table->index(['state', 'purge_after'], 'ix_file_attachments_state_purge');
            $table->foreign(['entry_id', 'vault_id'], 'fk_file_attachments_entry_vault')
                ->references(['id', 'vault_id'])->on('entries');
            $table->foreign(['vault_key_version_id', 'vault_id'], 'fk_file_attachments_key_version_vault')
                ->references(['id', 'vault_id'])->on('vault_key_versions');
        }, [
            'ck_file_attachments_state' => "state in ('staging', 'available', 'quarantined', 'deleting', 'deleted', 'failed')",
            'ck_file_attachments_size' => 'byte_size > 0',
            'ck_file_attachments_stream_version' => 'stream_format_version = 1',
        ]);

        $this->createTable('attachment_reservations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('vault_id')->constrained('vaults');
            $table->foreignUuid('requested_by_user_id')->constrained('users');
            $table->unsignedBigInteger('reserved_bytes');
            $table->timestamp('expires_at');
            $table->string('state', 20);
            $table->uuid('attachment_id')->nullable();
            $table->timestamps();
            $table->index(['vault_id', 'state', 'expires_at'], 'ix_attachment_reservations_vault_state');
            $table->foreign('attachment_id', 'fk_attachment_reservations_attachment')
                ->references('id')->on('file_attachments')->nullOnDelete();
        }, [
            'ck_attachment_reservations_state' => "state in ('reserved', 'committed', 'released')",
            'ck_attachment_reservations_bytes' => 'reserved_bytes > 0',
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('attachment_reservations');
        Schema::dropIfExists('file_attachments');
    }

    /**
     * Create a table with named check constraints on every supported engine.
     *
     * SQLite cannot add constraints to an existing table, so its check clauses
     * are injected into the generated CREATE TABLE statement instead.
     *
     * @param  Closure(Blueprint): void  $definition
     * @param  array<string, string>  $checks
     */
    private function createTable(string $table, Closure $definition, array $checks): void
    {
        $connection = DB::connection();
        $driver = $connection->getDriverName();

        if ($driver !== 'sqlite') {
            Schema::create($table, $definition);
            $quote = in_array($driver, ['mysql', 'mariadb'], true) ? '`' : '"';
            foreach ($checks as $name => $expression) {
                $connection->statement("alter table {$quote}{$table}{$quote} add constraint {$quote}{$name}{$quote} check ({$expression})");
            }

            return;
        }

        $connection->useDefaultSchemaGrammar();
        $blueprint = new Blueprint($connection, $table);
        $blueprint->create();
        $definition($blueprint);
        $clauses = [];
        foreach ($checks as $name => $expression) {
            $clauses[] = "constraint \"{$name}\" check ({$expression})";
        }
        foreach ($blueprint->toSql() as $statement) {
            $position = strrpos($statement, ')');
            if ($clauses !== [] && $position !== false && str_starts_with(strtolower(ltrim($statement)), 'create table')) {
                $statement = substr($statement, 0, $position).', '.implode(', ', $clauses).substr($statement, $position);
            }
            $connection->statement($statement);
        }
    }
};
