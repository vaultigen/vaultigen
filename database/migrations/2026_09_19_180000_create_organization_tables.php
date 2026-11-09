<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->createTable('tags', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('vault_id')->constrained('vaults');
            $table->string('name', 60);
            $table->string('normalized_name', 60);
            $table->string('color', 20)->nullable();
            $table->timestamps();
            $table->unique(['vault_id', 'normalized_name'], 'uk_tags_vault_name');
            $table->unique(['id', 'vault_id'], 'uk_tags_id_vault');
        }, []);

        $this->createTable('entry_tag', function (Blueprint $table): void {
            $table->foreignUuid('vault_id');
            $table->uuid('entry_id');
            $table->uuid('tag_id');
            $table->unique(['entry_id', 'tag_id'], 'uk_entry_tag_entry_tag');
            $table->index(['tag_id', 'vault_id'], 'ix_entry_tag_tag_vault');
            $table->foreign('vault_id', 'fk_entry_tag_vault')->references('id')->on('vaults');
            $table->foreign(['entry_id', 'vault_id'], 'fk_entry_tag_entry_vault')
                ->references(['id', 'vault_id'])->on('entries');
            $table->foreign(['tag_id', 'vault_id'], 'fk_entry_tag_tag_vault')
                ->references(['id', 'vault_id'])->on('tags');
        }, []);

        $this->createTable('folder_grants', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('principal_id')->constrained('principals');
            $table->uuid('folder_id');
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
            $table->foreign('folder_id', 'fk_folder_grants_folder')->references('id')->on('folders');
            $table->unique(['principal_id', 'folder_id', 'active_slot'], 'uk_folder_grants_principal_folder_slot');
            $table->index(['folder_id', 'active_slot'], 'ix_folder_grants_folder_active');
            $table->index(['principal_id', 'active_slot'], 'ix_folder_grants_principal_active');
        }, [
            'ck_folder_grants_permission' => "permission in ('viewer', 'editor', 'manager')",
            'ck_folder_grants_source' => "source in ('manual', 'request', 'migration', 'future_directory_sync')",
            'ck_folder_grants_slot' => '(active_slot is null or active_slot = 1)',
            'ck_folder_grants_lifecycle' => '((active_slot is null) = (revoked_at is not null))',
        ]);

        $this->createTable('entry_grants', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('principal_id')->constrained('principals');
            $table->uuid('entry_id');
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
            $table->foreign('entry_id', 'fk_entry_grants_entry')->references('id')->on('entries');
            $table->unique(['principal_id', 'entry_id', 'active_slot'], 'uk_entry_grants_principal_entry_slot');
            $table->index(['entry_id', 'active_slot'], 'ix_entry_grants_entry_active');
            $table->index(['principal_id', 'active_slot'], 'ix_entry_grants_principal_active');
        }, [
            'ck_entry_grants_permission' => "permission in ('viewer', 'editor', 'manager')",
            'ck_entry_grants_source' => "source in ('manual', 'request', 'migration', 'future_directory_sync')",
            'ck_entry_grants_slot' => '(active_slot is null or active_slot = 1)',
            'ck_entry_grants_lifecycle' => '((active_slot is null) = (revoked_at is not null))',
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('entry_grants');
        Schema::dropIfExists('folder_grants');
        Schema::dropIfExists('entry_tag');
        Schema::dropIfExists('tags');
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
