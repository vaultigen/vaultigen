<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Str;

class InitializeInstallation extends Command
{
    protected $signature = 'vaultigen:initialize';

    protected $description = 'Create protected, independent installation identity and security secrets once';

    public function handle(): int
    {
        $path = config('vaultigen.installation_file');
        if (is_file($path) || config('vaultigen.installation_id') !== null) {
            $this->error('Installation identity already exists; it must never be replaced.');

            return self::FAILURE;
        }
        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0700, true);
        }
        $mask = umask(0077);
        $file = @fopen($path, 'x');
        umask($mask);
        if ($file === false) {
            $this->error('Unable to create protected installation configuration.');

            return self::FAILURE;
        }
        fwrite($file, json_encode([
            'installation_id' => (string) Str::uuid(),
            'passkey_secret' => base64_encode(random_bytes(32)),
            'audit_key' => base64_encode(random_bytes(32)),
        ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
        fclose($file);
        $this->call('vaultigen:kek:generate', ['--if-missing' => true]);
        $this->info('Installation configuration created with owner-only permissions. Escrow it separately from data backups. Run config:clear, migrate, then vaultigen:bootstrap.');

        return self::SUCCESS;
    }
}
