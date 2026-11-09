<?php

use Illuminate\Support\Facades\DB;

test('sqlite is configured for wal, a busy timeout, and foreign keys', function () {
    $file = tempnam(sys_get_temp_dir(), 'vaultigen-sqlite-');
    if ($file === false) {
        $this->markTestSkipped('Could not create a temporary SQLite file.');
    }

    try {
        $config = config('database.connections.sqlite');
        expect($config)->toBeArray();
        $config['database'] = $file;
        config(['database.connections.sqlite_profile' => $config]);
        DB::purge('sqlite_profile');

        $connection = DB::connection('sqlite_profile');
        $connection->statement('CREATE TABLE profile_probe (id INTEGER PRIMARY KEY)');

        expect($connection->select('PRAGMA journal_mode')[0]->journal_mode)->toBe('wal')
            ->and($connection->select('PRAGMA busy_timeout')[0]->timeout)->toBe(5000)
            ->and($connection->select('PRAGMA foreign_keys')[0]->foreign_keys)->toBe(1)
            ->and($connection->select('PRAGMA synchronous')[0]->synchronous)->toBe(1);
    } finally {
        DB::purge('sqlite_profile');
        @unlink($file);
    }
});
