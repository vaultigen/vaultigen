<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->createTable('entries', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('vault_id')->constrained('vaults');
            $table->uuid('folder_id')->nullable();
            $table->foreignUuid('owner_user_id')->constrained('users');
            $table->string('type', 20);
            $table->string('title', 200);
            $table->string('normalized_title', 200);
            $table->string('url_host', 255)->nullable();
            $table->foreignUuid('vault_key_version_id');
            $table->string('cipher_suite', 40);
            $table->unsignedSmallInteger('payload_format_version');
            $table->unsignedSmallInteger('payload_schema_version');
            $table->string('payload_nonce', 64);
            $table->longText('encrypted_payload');
            $table->unsignedInteger('lock_version')->default(1);
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('deleted_at')->nullable();
            $table->foreignUuid('deleted_by_user_id')->nullable()->constrained('users');
            $table->timestamp('purge_after')->nullable();
            $table->timestamps();
            $table->unique(['id', 'vault_id'], 'uk_entries_id_vault');
            $table->index(['vault_id', 'normalized_title'], 'ix_entries_vault_title');
            $table->index(['vault_id', 'deleted_at'], 'ix_entries_vault_deleted');
            $table->index(['folder_id', 'vault_id'], 'ix_entries_folder_vault');
            $table->foreign(['vault_key_version_id', 'vault_id'], 'fk_entries_key_version_vault')
                ->references(['id', 'vault_id'])->on('vault_key_versions');
            $table->foreign(['folder_id', 'vault_id'], 'fk_entries_folder_vault')
                ->references(['id', 'vault_id'])->on('folders');
        }, [
            'ck_entries_type' => "type in ('login', 'note', 'ssh_key', 'totp')",
            'ck_entries_lock_version' => 'lock_version >= 1',
        ]);

        $this->createTable('entry_revisions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('vault_id')->constrained('vaults');
            $table->uuid('entry_id');
            $table->unsignedInteger('revision_number');
            $table->foreignUuid('vault_key_version_id');
            $table->string('cipher_suite', 40);
            $table->unsignedSmallInteger('payload_format_version');
            $table->unsignedSmallInteger('payload_schema_version');
            $table->string('payload_nonce', 64);
            $table->longText('encrypted_snapshot');
            $table->foreignUuid('changed_by_user_id')->constrained('users');
            $table->text('change_reason')->nullable();
            $table->timestamp('created_at');
            $table->unique(['entry_id', 'revision_number'], 'uk_entry_revisions_entry_number');
            $table->index(['entry_id', 'created_at'], 'ix_entry_revisions_entry_created');
            $table->foreign(['entry_id', 'vault_id'], 'fk_entry_revisions_entry_vault')
                ->references(['id', 'vault_id'])->on('entries');
            $table->foreign(['vault_key_version_id', 'vault_id'], 'fk_entry_revisions_key_version_vault')
                ->references(['id', 'vault_id'])->on('vault_key_versions');
        }, [
            'ck_entry_revisions_number' => 'revision_number >= 1',
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('entry_revisions');
        Schema::dropIfExists('entries');
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
