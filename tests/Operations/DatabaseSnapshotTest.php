<?php

use App\Services\Operations\CommandRunner;
use App\Services\Operations\DatabaseSnapshot;
use App\Services\Operations\OperationFailedException;
use Illuminate\Support\Facades\Schema;
use Tests\Fixtures\FakeBackupRuntime;

it('dumps and restores a SQLite database through a temporary file', function (): void {
    if (DB::connection()->getDriverName() !== 'sqlite') {
        $this->markTestSkipped('SQLite-specific test.');
    }

    $snapshot = app(DatabaseSnapshot::class);
    $path = $snapshot->newTemporaryPath();

    expect(is_file($path))->toBeFalse();

    $snapshot->dumpTo($path);
    expect(is_file($path))->toBeTrue();

    $target = $snapshot->newTemporaryPath();
    config(['database.connections.sqlite.database' => $target]);
    $snapshot->restoreUsing('sqlite', $path);

    expect(is_file($target))->toBeTrue();

    $handle = new PDO('sqlite:'.$target);
    $count = $handle->query('select count(*) from migrations')->fetchColumn();
    expect((int) $count)->toBeGreaterThan(0);

    @unlink($path);
    @unlink($target);
});

it('builds mysqldump and mysql restore commands for the mysql driver', function (): void {
    config(['database.connections.mysql' => [
        'host' => 'db.internal', 'port' => 3307, 'database' => 'vault', 'username' => 'app', 'password' => 'secret',
    ]]);

    $runner = Mockery::mock(CommandRunner::class);
    $runner->shouldReceive('runToFile')->once()->withArgs(function (array $command, array $env, string $path): bool {
        return $command[0] === 'mysqldump'
            && in_array('--host=db.internal', $command, true)
            && in_array('--port=3307', $command, true)
            && in_array('--user=app', $command, true)
            && end($command) === 'vault'
            && $env === ['MYSQL_PWD' => 'secret'];
    });
    $runner->shouldReceive('runWithFileInput')->once()->withArgs(function (array $command, array $env, string $path): bool {
        return $command[0] === 'mysql' && end($command) === 'vault' && $env === ['MYSQL_PWD' => 'secret'];
    });

    $snapshot = new DatabaseSnapshot($runner);
    $snapshot->dumpUsing('mysql', '/tmp/dump.sql');
    $snapshot->restoreUsing('mysql', '/tmp/dump.sql');
});

it('uses a unix socket for mysql when configured and omits an empty password', function (): void {
    config(['database.connections.mysql' => [
        'unix_socket' => '/var/run/mysqld/mysqld.sock', 'database' => 'vault', 'username' => 'root', 'password' => '',
    ]]);

    $runner = Mockery::mock(CommandRunner::class);
    $runner->shouldReceive('runToFile')->once()->withArgs(function (array $command, array $env): bool {
        return in_array('--socket=/var/run/mysqld/mysqld.sock', $command, true) && $env === [];
    });

    (new DatabaseSnapshot($runner))->dumpUsing('mysql', '/tmp/dump.sql');
});

it('uses mariadb tools for the mariadb driver', function (): void {
    config(['database.connections.mariadb' => [
        'host' => '127.0.0.1', 'port' => 3306, 'database' => 'vault', 'username' => 'app', 'password' => 'pw',
    ]]);

    $runner = Mockery::mock(CommandRunner::class);
    $runner->shouldReceive('runToFile')->once()->withArgs(fn (array $command): bool => $command[0] === 'mariadb-dump');
    $runner->shouldReceive('runWithFileInput')->once()->withArgs(fn (array $command): bool => $command[0] === 'mariadb');

    $snapshot = new DatabaseSnapshot($runner);
    $snapshot->dumpUsing('mariadb', '/tmp/dump.sql');
    $snapshot->restoreUsing('mariadb', '/tmp/dump.sql');
});

it('builds pg_dump and psql commands for the pgsql driver', function (): void {
    config(['database.connections.pgsql' => [
        'host' => 'pg.internal', 'port' => 5433, 'database' => 'vault', 'username' => 'app', 'password' => 'pgsecret',
    ]]);

    $runner = Mockery::mock(CommandRunner::class);
    $runner->shouldReceive('runToFile')->once()->withArgs(function (array $command, array $env): bool {
        return $command[0] === 'pg_dump'
            && in_array('--dbname=vault', $command, true)
            && $env === ['PGPASSWORD' => 'pgsecret'];
    });
    $runner->shouldReceive('runWithFileInput')->once()->withArgs(function (array $command, array $env): bool {
        return $command[0] === 'psql' && in_array('--dbname=vault', $command, true) && $env === ['PGPASSWORD' => 'pgsecret'];
    });

    $snapshot = new DatabaseSnapshot($runner);
    $snapshot->dumpUsing('pgsql', '/tmp/dump.sql');
    $snapshot->restoreUsing('pgsql', '/tmp/dump.sql');
});

it('clears a server database before applying the dump so tables of a newer schema do not survive', function (): void {
    config(['database.connections.pgsql' => ['host' => 'pg.internal', 'database' => 'vault', 'username' => 'app', 'password' => 'pgsecret']]);
    $runner = Mockery::mock(CommandRunner::class);
    $snapshot = Mockery::mock(DatabaseSnapshot::class, [$runner])->makePartial();
    $snapshot->shouldReceive('driver')->andReturn('pgsql');
    Schema::shouldReceive('dropAllViews')->once()->globally()->ordered();
    Schema::shouldReceive('dropAllTables')->once()->globally()->ordered();
    $runner->shouldReceive('runWithFileInput')->once()->globally()->ordered();

    $snapshot->restoreFrom('/tmp/dump.sql');
});

it('rejects an unsupported database driver', function (): void {
    $snapshot = app(DatabaseSnapshot::class);

    expect(fn () => $snapshot->dumpUsing('sqlsrv', '/tmp/dump.sql'))
        ->toThrow(RuntimeException::class);
    expect(fn () => $snapshot->restoreUsing('sqlsrv', '/tmp/dump.sql'))
        ->toThrow(RuntimeException::class);
});

it('refuses to restore SQLite into an in-memory database', function (): void {
    if (DB::connection()->getDriverName() !== 'sqlite') {
        $this->markTestSkipped('SQLite-specific test.');
    }

    config(['database.connections.sqlite.database' => ':memory:']);

    expect(fn () => app(DatabaseSnapshot::class)->restoreUsing('sqlite', '/tmp/dump.sql'))
        ->toThrow(RuntimeException::class);
});

it('replaces an existing snapshot file and reports a restore copy failure', function (): void {
    if (DB::connection()->getDriverName() !== 'sqlite') {
        $this->markTestSkipped('SQLite-specific test.');
    }

    $snapshot = app(DatabaseSnapshot::class);

    $path = $snapshot->newTemporaryPath();
    file_put_contents($path, 'stale');
    $snapshot->dumpUsing('sqlite', $path);
    expect(filesize($path))->toBeGreaterThan(4);

    $directory = sys_get_temp_dir().'/vaultigen-dir-'.uniqid();
    mkdir($directory);
    config(['database.connections.sqlite.database' => $directory]);
    expect(fn () => $snapshot->restoreUsing('sqlite', $path))->toThrow(RuntimeException::class);

    @unlink($path);
    @rmdir($directory);
});

it('runs external commands through the command runner', function (): void {
    $runner = new CommandRunner;
    $destination = tempnam(sys_get_temp_dir(), 'vaultigen-cmd-');
    @unlink($destination);

    $runner->runToFile([PHP_BINARY, '-r', 'echo "hello";'], [], $destination);
    expect(file_get_contents($destination))->toBe('hello');

    $source = tempnam(sys_get_temp_dir(), 'vaultigen-cmd-');
    file_put_contents($source, 'payload');
    $runner->runWithFileInput([PHP_BINARY, '-r', 'while (!feof(STDIN)) { fread(STDIN, 1024); }'], [], $source);

    expect(fn () => $runner->runToFile([PHP_BINARY, '-r', 'exit(1);'], [], $destination))
        ->toThrow(RuntimeException::class);
    expect(fn () => $runner->runWithFileInput([PHP_BINARY, '-r', 'exit(1);'], [], $source))
        ->toThrow(RuntimeException::class);
    expect(fn () => $runner->runToFile([PHP_BINARY, '-r', 'exit(0);'], [], '/nonexistent-dir/out.sql'))
        ->toThrow(RuntimeException::class);
    expect(fn () => $runner->runWithFileInput([PHP_BINARY, '-r', 'exit(0);'], [], '/nonexistent-dir/in.sql'))
        ->toThrow(RuntimeException::class);
    expect(fn () => $runner->runToFile([], [], $destination))->toThrow(RuntimeException::class);
    expect(fn () => $runner->runWithFileInput([], [], $source))->toThrow(RuntimeException::class);

    @unlink($destination);
    @unlink($source);
});

it('interprets native database size estimates as byte counts', function (string $driver): void {
    $snapshot = Mockery::mock(DatabaseSnapshot::class)->makePartial();
    $snapshot->shouldReceive('driver')->andReturn($driver);
    if ($driver !== 'unsupported') {
        DB::shouldReceive('selectOne')->once()->andReturn((object) ['bytes' => '65536']);
        expect($snapshot->estimatedBytes())->toBe(65536);
    } else {
        expect(fn () => $snapshot->estimatedBytes())->toThrow(RuntimeException::class, 'Unsupported database driver.');
    }
})->with(['mysql', 'pgsql', 'unsupported']);

it('accepts only snapshots matching the database format', function (string $driver, string $contents, bool $valid): void {
    Storage::fake('snapshots');
    $path = Storage::disk('snapshots')->path('database');
    Storage::disk('snapshots')->put('database', $contents);
    if ($driver === 'sqlite' && $contents !== '') {
        unlink($path);
        (new PDO('sqlite:'.$path))->exec('CREATE TABLE unrelated (id INTEGER)');
    }
    $snapshot = Mockery::mock(DatabaseSnapshot::class, [app(CommandRunner::class)])->makePartial();
    $snapshot->shouldReceive('driver')->andReturn($driver);
    if ($valid) {
        expect(fn () => $snapshot->validateSnapshot($path))->not->toThrow(Throwable::class);
    } else {
        expect(fn () => $snapshot->validateSnapshot($path))->toThrow(OperationFailedException::class);
    }
})->with([
    'PostgreSQL dump' => ['pgsql', '-- PostgreSQL database dump', true],
    'comment-free MySQL dump' => ['mysql', '/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;', true],
    'wrong dump format' => ['pgsql', 'unrelated contents', false],
    'empty snapshot' => ['sqlite', '', false],
    'SQLite without application schema' => ['sqlite', 'schema-less database', false],
]);

it('reports a snapshot that disappears before it can be opened', function (): void {
    Storage::fake('snapshots');
    Storage::disk('snapshots')->put('database', '-- PostgreSQL database dump');
    $snapshot = Mockery::mock(DatabaseSnapshot::class, [app(CommandRunner::class)])->makePartial();
    $snapshot->shouldReceive('driver')->andReturn('pgsql');
    FakeBackupRuntime::$overrides['fopen'] = fn (string $path, string $mode): false => false;

    expect(fn () => $snapshot->validateSnapshot(Storage::disk('snapshots')->path('database')))
        ->toThrow(fn (OperationFailedException $exception) => expect($exception->failureCode)->toBe('snapshot_unreadable'));
});

it('reports failure to stage a SQLite replacement and leaves the target intact', function (): void {
    Storage::fake('snapshots');
    $path = Storage::disk('snapshots')->path('target');
    Storage::disk('snapshots')->put('target', 'preserve');
    config(['database.connections.sqlite.database' => $path]);

    expect(fn () => app(DatabaseSnapshot::class)->restoreUsing('sqlite', '/nonexistent-snapshot'))
        ->toThrow(RuntimeException::class, 'could not be staged');
    expect(file_get_contents($path))->toBe('preserve');
});

it('fails the snapshot tool when its output cannot be fully written', function (): void {
    expect(fn () => app(CommandRunner::class)->runToFile([PHP_BINARY, '-r', 'echo "snapshot";'], [], 'vaultigen-failwrite://snapshot'))
        ->toThrow(RuntimeException::class, 'could not be run');
});
