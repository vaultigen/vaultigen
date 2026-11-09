<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class GenerateBackupKey extends Command
{
    protected $signature = 'vaultigen:backup-key:generate {--if-missing : Only create the keyring when it does not exist yet}';

    protected $description = 'Create the protected backup encryption keyring once';

    public function handle(): int
    {
        if (is_string(config('vaultigen.backup_keyring')) && config('vaultigen.backup_keyring') !== '') {
            if ($this->option('if-missing')) {
                $this->info('The environment backup keyring is configured; no keyring file is required.');

                return self::SUCCESS;
            }
            $this->error('This installation is configured to use the environment backup keyring.');

            return self::FAILURE;
        }

        $path = (string) config('vaultigen.backup_key_file');
        if (is_file($path)) {
            if ($this->option('if-missing')) {
                $this->info('The backup keyring already exists.');

                return self::SUCCESS;
            }
            $this->error('The backup keyring already exists; it must never be replaced.');

            return self::FAILURE;
        }
        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0700, true);
        }
        $mask = umask(0077);
        $file = @fopen($path, 'x');
        umask($mask);
        if ($file === false) {
            $this->error('Unable to create the protected backup keyring.');

            return self::FAILURE;
        }
        $keyId = 'backup-'.now()->utc()->format('Ymd').'-'.bin2hex(random_bytes(4));
        fwrite($file, json_encode([
            'active_key_id' => $keyId,
            'keys' => [$keyId => base64_encode(random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES))],
        ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
        fclose($file);
        $this->info('Backup keyring created with owner-only permissions. Escrow it separately from the backups and the live installation KEK.');

        return self::SUCCESS;
    }
}
