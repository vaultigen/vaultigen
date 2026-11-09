<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class GenerateKek extends Command
{
    protected $signature = 'vaultigen:kek:generate {--if-missing : Only create the keyring when it does not exist yet}';

    protected $description = 'Create the protected installation key-encryption keyring once';

    public function handle(): int
    {
        $ifMissing = (bool) $this->option('if-missing');

        if (config('vaultigen.kek_provider') !== 'file') {
            if ($ifMissing) {
                $this->info('The environment KEK provider is configured; no keyring file is required.');

                return self::SUCCESS;
            }
            $this->error('This installation is configured to use the environment KEK provider.');

            return self::FAILURE;
        }

        $path = (string) config('vaultigen.kek_file');
        if (is_file($path)) {
            if ($ifMissing) {
                $this->info('The installation KEK keyring already exists.');

                return self::SUCCESS;
            }
            $this->error('The installation KEK keyring already exists; it must never be replaced.');

            return self::FAILURE;
        }
        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0700, true);
        }
        $mask = umask(0077);
        $file = @fopen($path, 'x');
        umask($mask);
        if ($file === false) {
            $this->error('Unable to create the protected installation KEK keyring.');

            return self::FAILURE;
        }
        $keyId = 'kek-'.now()->utc()->format('Ymd').'-'.bin2hex(random_bytes(4));
        fwrite($file, json_encode([
            'active_key_id' => $keyId,
            'keys' => [$keyId => base64_encode(random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES))],
        ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
        fclose($file);
        $this->info('Installation KEK keyring created with owner-only permissions. Escrow it separately from data backups.');

        return self::SUCCESS;
    }
}
