<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use JsonException;
use RuntimeException;

class AddKek extends Command
{
    protected $signature = 'vaultigen:kek:add';

    protected $description = 'Add a new installation key-encryption key and make it active, then run vaultigen:rotate-kek';

    public function handle(): int
    {
        if (config('vaultigen.kek_provider') !== 'file') {
            $this->error('This installation uses the environment KEK provider; add the new key to VAULTIGEN_KEK_KEYRING and set its active_key_id instead.');

            return self::FAILURE;
        }

        $path = (string) config('vaultigen.kek_file');
        $newKeyId = 'kek-'.now()->utc()->format('Ymd').'-'.bin2hex(random_bytes(4));

        try {
            $document = $this->readDocument($path);
            $keys = is_array($document['keys'] ?? null) ? $document['keys'] : [];
            $keys[$newKeyId] = base64_encode(random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES));
            $document['keys'] = $keys;
            $document['active_key_id'] = $newKeyId;
            $this->writeDocument($path, $document);
        } catch (RuntimeException) {
            $this->error('The installation KEK keyring is malformed or could not be updated.');

            return self::FAILURE;
        }

        $this->info("Added installation KEK [{$newKeyId}] and made it active. Old keys are kept; run vaultigen:rotate-kek to rewrap every vault key.");

        return self::SUCCESS;
    }

    /**
     * @return array<string, mixed>
     */
    public function readDocument(string $path): array
    {
        try {
            $document = json_decode((string) @file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new RuntimeException('The installation KEK keyring is malformed.');
        }

        if (! is_array($document)) {
            throw new RuntimeException('The installation KEK keyring is malformed.');
        }

        return $document;
    }

    /**
     * @param  array<string, mixed>  $document
     */
    public function writeDocument(string $path, array $document): void
    {
        $mask = umask(0077);
        $written = @file_put_contents($path, json_encode($document, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
        umask($mask);

        if ($written === false) {
            throw new RuntimeException('The installation KEK keyring could not be updated.');
        }
    }
}
