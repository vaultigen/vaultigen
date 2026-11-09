<?php

namespace App\Services\Imports;

use App\Contracts\KekProvider;
use App\Models\ImportRun;
use App\Services\Crypto\AssociatedData;
use App\Services\Crypto\EnvelopeCipher;
use JsonException;
use RuntimeException;

/**
 * Short-lived, KEK-wrapped credentials for a queued import.
 *
 * The master password cannot be placed in the queue payload or kept in the
 * request while a background job runs. Instead it is sealed under the active
 * installation KEK with a dedicated purpose code and canonical associated data
 * that binds it to the run. The worker unwraps it, and every terminal path
 * purges it. It is never written to a queue payload, log, audit context, plan,
 * or report.
 */
final class ImportCredentialStore
{
    public function __construct(
        private readonly KekProvider $kek,
        private readonly EnvelopeCipher $cipher,
    ) {}

    /**
     * Seal the driver credentials for a run.
     *
     * @param  array<string, string>  $credentials
     *
     * @throws JsonException
     */
    public function seal(ImportRun $run, #[\SensitiveParameter] array $credentials): void
    {
        $kekKeyId = $this->kek->activeKeyId();
        $sealed = $this->cipher->seal(
            EnvelopeCipher::PURPOSE_IMPORT_CREDENTIAL,
            $kekKeyId,
            $this->kek->key($kekKeyId),
            json_encode($credentials, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            $this->associatedData((string) $run->id, $kekKeyId),
        );

        $run->forceFill([
            'wrapped_credentials' => $sealed->envelope,
            'credentials_key_id' => $kekKeyId,
        ])->save();
    }

    /**
     * Unseal the credentials for a run.
     *
     * @return array<string, string>
     *
     * @throws JsonException
     */
    public function open(ImportRun $run): array
    {
        if ($run->wrapped_credentials === null || $run->credentials_key_id === null) {
            throw new RuntimeException('The import credentials are missing.');
        }

        $json = $this->cipher->open(
            EnvelopeCipher::PURPOSE_IMPORT_CREDENTIAL,
            $run->credentials_key_id,
            $this->kek->key($run->credentials_key_id),
            $run->wrapped_credentials,
            $this->associatedData((string) $run->id, $run->credentials_key_id),
        );

        $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        $decoded = is_array($decoded) ? $decoded : [];

        $credentials = [];
        foreach ($decoded as $key => $value) {
            if (is_string($key)) {
                $credentials[$key] = is_string($value) ? $value : '';
            }
        }

        return $credentials;
    }

    /**
     * Remove the sealed credentials. Idempotent.
     */
    public function purge(ImportRun $run): void
    {
        if ($run->wrapped_credentials === null && $run->credentials_key_id === null) {
            return;
        }

        $run->forceFill([
            'wrapped_credentials' => null,
            'credentials_key_id' => null,
        ])->save();
    }

    private function associatedData(string $runId, string $kekKeyId): string
    {
        return AssociatedData::encode([
            'installation_id' => (string) config('vaultigen.installation_id'),
            'purpose' => EnvelopeCipher::PURPOSE_IMPORT_CREDENTIAL,
            'import_run_id' => $runId,
            'kek_key_id' => $kekKeyId,
        ]);
    }
}
