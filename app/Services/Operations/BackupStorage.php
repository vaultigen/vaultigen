<?php

namespace App\Services\Operations;

use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Storage;

final class BackupStorage
{
    public function disk(): FilesystemAdapter
    {
        $root = PrivatePaths::root((string) config('vaultigen.backup_root'));

        $disk = Storage::build([
            'driver' => 'local', 'root' => $root, 'throw' => false,
            'visibility' => 'private', 'directory_visibility' => 'private',
        ]);
        if (! $disk instanceof FilesystemAdapter) {
            throw new OperationFailedException('backup_configuration_invalid');
        }

        return $disk;
    }

    public function directory(string $path): string
    {
        $relative = str_starts_with($path, 'backups/') ? substr($path, 8) : $path;
        PrivatePaths::relative($relative);
        PrivatePaths::within($this->disk()->path(''), $relative);

        return $relative;
    }

    public function path(string $directory): string
    {
        return $this->disk()->path($this->directory($directory));
    }

    public function writable(): bool
    {
        $disk = $this->disk();
        $probe = '.probe-'.bin2hex(random_bytes(8));
        try {
            return $disk->put($probe, 'ok') && $disk->get($probe) === 'ok' && $disk->delete($probe);
        } finally {
            $disk->delete($probe);
        }
    }

    public function delete(string $directory): void
    {
        if (! $this->disk()->deleteDirectory($this->directory($directory))) {
            throw new OperationFailedException('object_unwritable');
        }
    }
}
