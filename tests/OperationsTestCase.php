<?php

namespace Tests;

use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\ExecutableFinder;

/**
 * Base test case for the Operations suite.
 *
 * Backups and restores cannot run inside the transaction that RefreshDatabase
 * wraps around a test, and `VACUUM INTO` refuses to run inside any transaction.
 * These tests therefore use a freshly migrated database that is truncated
 * between tests instead. Like RefreshDatabase, this trait preserves the single
 * in-memory PDO connection across application rebuilds, and it excludes the
 * installation singletons that a migration inserts once.
 */
abstract class OperationsTestCase extends TestCase
{
    /** @var list<string> */
    protected array $operationSingletons = [
        'migrations',
        'installation_settings',
        'audit_chain_heads',
        'maintenance_windows',
        'platform_roles',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        if (($binary = $this->requiredSnapshotTool()) !== null && (new ExecutableFinder)->find($binary) === null) {
            $this->markTestSkipped("The {$binary} tool is not available in this PHP runtime, so engine backup tests are skipped.");
        }

        $this->refreshOperationsDatabase();
    }

    private function requiredSnapshotTool(): ?string
    {
        return match ((string) config('database.default')) {
            'mysql' => 'mysqldump',
            'mariadb' => 'mariadb-dump',
            'pgsql' => 'pg_dump',
            default => null,
        };
    }

    private function refreshOperationsDatabase(): void
    {
        $name = (string) config('database.default');
        $inMemory = config("database.connections.{$name}.database") === ':memory:';
        $database = $this->app->make('db');

        if ($inMemory && isset(RefreshDatabaseState::$inMemoryConnections[$name])) {
            $database->connection($name)->setPdo(RefreshDatabaseState::$inMemoryConnections[$name]);
        }

        if (! RefreshDatabaseState::$migrated) {
            $this->artisan('migrate:fresh');
            if ($inMemory) {
                RefreshDatabaseState::$inMemoryConnections[$name] = $database->connection($name)->getPdo();
            }
            RefreshDatabaseState::$migrated = true;

            return;
        }

        $this->truncateOperationTables();
    }

    private function truncateOperationTables(): void
    {
        $connection = DB::connection();
        $schema = $connection->getSchemaBuilder();

        $schema->withoutForeignKeyConstraints(function () use ($schema): void {
            foreach ($schema->getTables() as $table) {
                $name = is_array($table) ? ($table['name'] ?? null) : null;
                if (is_string($name) && ! in_array($name, $this->operationSingletons, true)) {
                    DB::table($name)->truncate();
                }
            }
        });

        DB::table('audit_chain_heads')->where('id', 1)->update([
            'sequence' => 0,
            'event_hash' => str_repeat('0', 64),
            'anchor_sequence' => 0,
            'anchor_hash' => str_repeat('0', 64),
        ]);
        DB::table('maintenance_windows')->where('id', 1)->update([
            'active' => false,
            'operation' => null,
            'operation_id' => null,
            'reason' => null,
            'ended_at' => null,
        ]);
    }
}
