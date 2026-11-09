<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->createTable('vaults', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('name', 100);
            $table->string('normalized_name', 100)->index('ix_vaults_normalized_name');
            $table->string('slug', 120)->unique('uk_vaults_slug');
            $table->text('description')->nullable();
            $table->string('icon', 60)->nullable();
            $table->string('kind', 30);
            $table->foreignUuid('private_workspace_owner_user_id')->nullable()->unique('uk_vaults_private_owner')->constrained('users');
            $table->foreignUuid('primary_custodian_user_id')->constrained('users');
            $table->string('state', 30)->default('active');
            $table->foreignUuid('created_by_user_id')->constrained('users');
            $table->timestamps();
        }, [
            'ck_vaults_kind' => "kind in ('shared', 'private_workspace')",
            'ck_vaults_state' => "state in ('active', 'read_only', 'archived', 'pending_purge')",
            'ck_vaults_private_owner' => "((kind = 'private_workspace') = (private_workspace_owner_user_id is not null))",
        ]);

        $this->createTable('vault_key_versions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('vault_id')->constrained('vaults');
            $table->unsignedInteger('version');
            $table->foreignUuid('created_by_user_id')->constrained('users');
            $table->timestamp('created_at');
            $table->timestamp('retired_at')->nullable();
            $table->timestamp('compromised_at')->nullable();
            $table->unique(['vault_id', 'version'], 'uk_vault_key_versions_vault_version');
            $table->unique(['id', 'vault_id'], 'uk_vault_key_versions_id_vault');
        }, []);

        $this->createTable('vault_key_wrappings', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('vault_key_version_id')->constrained('vault_key_versions');
            $table->string('recipient_type', 30);
            $table->string('recipient_key_id', 64);
            $table->string('algorithm', 40);
            $table->string('nonce', 64);
            $table->longText('wrapped_key');
            $table->timestamp('created_at');
            $table->timestamp('retired_at')->nullable();
            $table->unique(['vault_key_version_id', 'recipient_type', 'recipient_key_id'], 'uk_vault_key_wrappings_recipient');
        }, [
            'ck_vault_key_wrappings_recipient' => "recipient_type in ('server_kek')",
        ]);

        $this->createTable('vault_active_keys', function (Blueprint $table): void {
            $table->foreignUuid('vault_id')->primary()->constrained('vaults');
            $table->foreignUuid('vault_key_version_id');
            $table->foreign(['vault_key_version_id', 'vault_id'], 'fk_vault_active_keys_version_vault')
                ->references(['id', 'vault_id'])->on('vault_key_versions');
        }, []);

        $this->createTable('vault_grants', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('principal_id')->constrained('principals');
            $table->foreignUuid('vault_id')->constrained('vaults');
            $table->string('permission', 20);
            $table->string('source', 30)->default('manual');
            $table->text('reason')->nullable();
            $table->foreignUuid('granted_by_user_id')->constrained('users');
            $table->timestamp('expires_at')->nullable();
            $table->unsignedTinyInteger('active_slot')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->foreignUuid('revoked_by_user_id')->nullable()->constrained('users');
            $table->text('revocation_reason')->nullable();
            $table->timestamps();
            $table->unique(['principal_id', 'vault_id', 'active_slot'], 'uk_vault_grants_principal_vault_slot');
            $table->index(['vault_id', 'active_slot'], 'ix_vault_grants_vault_active');
            $table->index(['principal_id', 'active_slot'], 'ix_vault_grants_principal_active');
        }, [
            'ck_vault_grants_permission' => "permission in ('viewer', 'editor', 'manager')",
            'ck_vault_grants_source' => "source in ('manual', 'request', 'migration', 'future_directory_sync')",
            'ck_vault_grants_slot' => '(active_slot is null or active_slot = 1)',
            'ck_vault_grants_lifecycle' => '((active_slot is null) = (revoked_at is not null))',
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('vault_grants');
        Schema::dropIfExists('vault_active_keys');
        Schema::dropIfExists('vault_key_wrappings');
        Schema::dropIfExists('vault_key_versions');
        Schema::dropIfExists('vaults');
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
