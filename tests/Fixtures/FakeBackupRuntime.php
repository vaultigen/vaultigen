<?php

namespace Tests\Fixtures {
    use App\Services\Operations\BackupStorage;
    use Illuminate\Filesystem\FilesystemAdapter;
    use Illuminate\Support\Facades\Storage;

    final class FakeBackupRuntime
    {
        /** @var array<string, \Closure> */
        public static array $overrides = [];

        public static function refuseStorage(string $method, \Closure $matches): void
        {
            $manager = Storage::getFacadeRoot();
            $disk = app(BackupStorage::class)->disk();
            $mock = \Mockery::mock(FilesystemAdapter::class, [$disk->getDriver(), $disk->getAdapter(), $disk->getConfig()])->makePartial();
            $mock->shouldReceive($method)->andReturnUsing(fn (...$arguments) => $matches(...$arguments) ? false : $disk->{$method}(...$arguments));
            Storage::shouldReceive('build')->andReturn($mock);
            Storage::shouldReceive('disk')->andReturnUsing($manager->disk(...));
        }
    }
}

namespace App\Services\Operations {
    use Tests\Fixtures\FakeBackupRuntime;

    /** @return resource|false */
    function fopen(string $filename, string $mode): mixed
    {
        return isset(FakeBackupRuntime::$overrides['fopen'])
            ? (FakeBackupRuntime::$overrides['fopen'])($filename, $mode)
            : \fopen($filename, $mode);
    }

    /** @param resource $stream */
    function fwrite(mixed $stream, string $data): int|false
    {
        return isset(FakeBackupRuntime::$overrides['fwrite'])
            ? (FakeBackupRuntime::$overrides['fwrite'])($stream, $data)
            : \fwrite($stream, $data);
    }

    function copy(string $from, string $to): bool
    {
        return isset(FakeBackupRuntime::$overrides['copy'])
            ? (FakeBackupRuntime::$overrides['copy'])($from, $to)
            : \copy($from, $to);
    }

    function rename(string $from, string $to): bool
    {
        return isset(FakeBackupRuntime::$overrides['rename'])
            ? (FakeBackupRuntime::$overrides['rename'])($from, $to)
            : \rename($from, $to);
    }

    function file_put_contents(string $filename, mixed $data): int|false
    {
        return isset(FakeBackupRuntime::$overrides['file_put_contents'])
            ? (FakeBackupRuntime::$overrides['file_put_contents'])($filename, $data)
            : \file_put_contents($filename, $data);
    }

    function unlink(string $filename): bool
    {
        return isset(FakeBackupRuntime::$overrides['unlink'])
            ? (FakeBackupRuntime::$overrides['unlink'])($filename)
            : \unlink($filename);
    }
}
