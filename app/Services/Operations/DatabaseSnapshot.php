<?php

namespace App\Services\Operations;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use PDO;
use RuntimeException;

/**
 * Creates and restores an engine-native database snapshot.
 *
 * SQLite is copied with `VACUUM INTO`, which is transactionally consistent while
 * the connection stays open. MySQL, MariaDB, and PostgreSQL are dumped and
 * restored with their own command-line tools, which must be installed on the
 * host. Passwords are never placed on the command line.
 *
 * The public `dumpUsing`/`restoreUsing` methods take an explicit driver so the
 * command construction for every engine can be exercised without running that
 * engine's tools.
 */
class DatabaseSnapshot
{
    public function __construct(private readonly CommandRunner $runner) {}

    public function driver(): string
    {
        return DB::connection()->getDriverName();
    }

    /**
     * A path that does not exist yet, suitable for `VACUUM INTO` and for tools
     * that create their destination.
     */
    public function newTemporaryPath(): string
    {
        $root = PrivatePaths::root((string) config('vaultigen.backup_temporary_root'));
        PrivatePaths::directory($root);
        $directory = $root.'/snapshot-'.bin2hex(random_bytes(12));
        PrivatePaths::directory($directory);

        return $directory.'/database';
    }

    public function removeTemporary(string $path): void
    {
        @unlink($path);
        @rmdir(dirname($path));
    }

    /**
     * Remove plaintext staging left by a process that died mid-operation.
     * Callers must hold the operation lock, so no live operation is staging.
     */
    public function removeAbandonedTemporaries(): void
    {
        $root = PrivatePaths::root((string) config('vaultigen.backup_temporary_root'));
        foreach (glob($root.'/snapshot-*', GLOB_ONLYDIR) ?: [] as $directory) {
            File::deleteDirectory($directory);
        }
    }

    public function estimatedBytes(): int
    {
        if ($this->driver() === 'sqlite') {
            return (int) DB::selectOne('PRAGMA page_count')->page_count * (int) DB::selectOne('PRAGMA page_size')->page_size;
        }

        return match ($this->driver()) {
            'pgsql' => (int) DB::selectOne('SELECT pg_database_size(current_database()) AS bytes')->bytes,
            'mysql', 'mariadb' => (int) DB::selectOne('SELECT COALESCE(SUM(data_length + index_length), 0) AS bytes FROM information_schema.tables WHERE table_schema = DATABASE()')->bytes,
            default => throw new RuntimeException('Unsupported database driver.'),
        };
    }

    public function validateSnapshot(string $path): void
    {
        if (! is_file($path) || filesize($path) === 0) {
            throw new OperationFailedException('integrity_failed');
        }
        if ($this->driver() === 'sqlite') {
            $pdo = new PDO('sqlite:'.$path);
            $integrity = $pdo->query('PRAGMA integrity_check');
            $schema = $pdo->query("SELECT count(*) FROM sqlite_master WHERE type = 'table' AND name = 'migrations'");
            if ($integrity === false || $schema === false || $integrity->fetchColumn() !== 'ok' || (int) $schema->fetchColumn() !== 1) {
                throw new OperationFailedException('integrity_failed');
            }
        } else {
            $handle = fopen($path, 'rb');
            if ($handle === false) {
                throw new OperationFailedException('snapshot_unreadable');
            }
            $header = fread($handle, 4096);
            fclose($handle);
            $validHeader = is_string($header) && ($this->driver() === 'pgsql'
                ? str_contains($header, 'PostgreSQL database dump')
                : preg_match('/(?:MySQL|MariaDB) dump/', $header) === 1 || str_contains($header, 'SET @OLD_CHARACTER_SET_CLIENT='));
            if (! $validHeader) {
                throw new OperationFailedException('integrity_failed');
            }
        }
    }

    public function dumpTo(string $path): void
    {
        $this->dumpUsing($this->driver(), $path);
    }

    /**
     * Replace the configured database with the snapshot. Server engines are
     * cleared first: their dumps drop and recreate only the tables they
     * contain, so tables from a newer schema (a rollback after an upgrade)
     * would otherwise survive and break the next migration.
     */
    public function restoreFrom(string $path): void
    {
        $driver = $this->driver();
        if ($driver !== 'sqlite') {
            Schema::dropAllViews();
            Schema::dropAllTables();
        }
        $this->restoreUsing($driver, $path);
    }

    public function dumpUsing(string $driver, string $path): void
    {
        match ($driver) {
            'sqlite' => $this->dumpSqlite($path),
            'mysql' => $this->dumpMysql($path, 'mysql'),
            'mariadb' => $this->dumpMysql($path, 'mariadb'),
            'pgsql' => $this->dumpPgsql($path),
            default => throw new RuntimeException('The configured database driver is not supported for backups.'),
        };
    }

    public function restoreUsing(string $driver, string $path): void
    {
        match ($driver) {
            'sqlite' => $this->restoreSqlite($path),
            'mysql' => $this->restoreMysql($path, 'mysql'),
            'mariadb' => $this->restoreMysql($path, 'mariadb'),
            'pgsql' => $this->restorePgsql($path),
            default => throw new RuntimeException('The configured database driver is not supported for restores.'),
        };
    }

    private function dumpSqlite(string $path): void
    {
        if (is_file($path)) {
            @unlink($path);
        }

        DB::statement('VACUUM INTO ?', [$path]);
    }

    private function dumpMysql(string $path, string $driver): void
    {
        $config = $this->connectionConfig($driver);
        $binary = $driver === 'mariadb' ? 'mariadb-dump' : 'mysqldump';
        $command = [
            $binary, '--single-transaction', '--skip-comments', '--routines', '--triggers', '--no-tablespaces',
            ...$this->mysqlConnectionArguments($config),
            (string) ($config['database'] ?? ''),
        ];

        $this->runner->runToFile($command, $this->mysqlEnvironment($config), $path);
    }

    private function dumpPgsql(string $path): void
    {
        $config = $this->connectionConfig('pgsql');
        $command = [
            'pg_dump', '--no-owner', '--no-acl', '--clean', '--if-exists',
            '--host='.(string) ($config['host'] ?? '127.0.0.1'),
            '--port='.(string) ($config['port'] ?? 5432),
            '--username='.(string) ($config['username'] ?? ''),
            '--dbname='.(string) ($config['database'] ?? ''),
        ];

        $this->runner->runToFile($command, $this->pgsqlEnvironment($config), $path);
    }

    private function restoreSqlite(string $path): void
    {
        $target = (string) config('database.connections.sqlite.database');
        if ($target === '' || $target === ':memory:') {
            throw new RuntimeException('Restoring a SQLite database requires a file-backed database path.');
        }

        $target = PrivatePaths::file($target);
        $replacement = $target.'.restore-'.bin2hex(random_bytes(6));
        if (! @copy($path, $replacement)) {
            throw new RuntimeException('The SQLite database file could not be staged.');
        }
        chmod($replacement, 0600);
        DB::purge();
        @unlink($target.'-wal');
        @unlink($target.'-shm');
        if (! @rename($replacement, $target)) {
            @unlink($replacement);
            throw new RuntimeException('The SQLite database file could not be replaced.');
        }
    }

    private function restoreMysql(string $path, string $driver): void
    {
        $config = $this->connectionConfig($driver);
        $binary = $driver === 'mariadb' ? 'mariadb' : 'mysql';
        $command = [
            $binary,
            ...$this->mysqlConnectionArguments($config),
            (string) ($config['database'] ?? ''),
        ];

        $this->runner->runWithFileInput($command, $this->mysqlEnvironment($config), $path);
    }

    private function restorePgsql(string $path): void
    {
        $config = $this->connectionConfig('pgsql');
        $command = [
            'psql', '--set=ON_ERROR_STOP=on',
            '--host='.(string) ($config['host'] ?? '127.0.0.1'),
            '--port='.(string) ($config['port'] ?? 5432),
            '--username='.(string) ($config['username'] ?? ''),
            '--dbname='.(string) ($config['database'] ?? ''),
        ];

        $this->runner->runWithFileInput($command, $this->pgsqlEnvironment($config), $path);
    }

    /**
     * @return array<string, mixed>
     */
    private function connectionConfig(string $driver): array
    {
        $config = config('database.connections.'.$driver);

        return is_array($config) ? $config : [];
    }

    /**
     * @param  array<string, mixed>  $config
     * @return list<string>
     */
    private function mysqlConnectionArguments(array $config): array
    {
        $arguments = [];
        if (($config['unix_socket'] ?? null) !== null && $config['unix_socket'] !== '') {
            $arguments[] = '--socket='.(string) $config['unix_socket'];
        } else {
            $arguments[] = '--host='.(string) ($config['host'] ?? '127.0.0.1');
            $arguments[] = '--port='.(string) ($config['port'] ?? 3306);
        }
        $arguments[] = '--user='.(string) ($config['username'] ?? '');

        return $arguments;
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array<string, string>
     */
    private function mysqlEnvironment(array $config): array
    {
        $password = (string) ($config['password'] ?? '');

        return $password === '' ? [] : ['MYSQL_PWD' => $password];
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array<string, string>
     */
    private function pgsqlEnvironment(array $config): array
    {
        $password = (string) ($config['password'] ?? '');

        return $password === '' ? [] : ['PGPASSWORD' => $password];
    }
}
