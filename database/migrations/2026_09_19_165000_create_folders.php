<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->createTable('folders', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('vault_id')->constrained('vaults');
            $table->uuid('parent_id')->nullable();
            $table->string('name', 100);
            $table->string('normalized_name', 100);
            $table->unsignedSmallInteger('depth')->default(0);
            $table->unsignedInteger('lock_version')->default(1);
            $table->timestamp('deleted_at')->nullable();
            $table->foreignUuid('deleted_by_user_id')->nullable()->constrained('users');
            $table->timestamp('purge_after')->nullable();
            $table->timestamps();
            $table->unique(['vault_id', 'normalized_name'], 'uk_folders_vault_name');
            $table->unique(['id', 'vault_id'], 'uk_folders_id_vault');
            $table->foreign(['parent_id', 'vault_id'], 'fk_folders_parent_vault')
                ->references(['id', 'vault_id'])->on('folders');
        }, [
            'ck_folders_flat' => '(parent_id is null and depth = 0)',
            'ck_folders_lock_version' => 'lock_version >= 1',
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('folders');
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
