<?php

namespace App\Services\Operations;

use App\Contracts\KekProvider;

final class BackupManifest
{
    public function __construct(private readonly KekProvider $kek) {}

    /** @param array<string, mixed> $manifest */
    public function validate(array $manifest): void
    {
        if (! in_array($manifest['format_version'] ?? null, [1, 2], true)) {
            throw new OperationFailedException('unsupported_format');
        }
        foreach (['installation_id', 'backup_run_id', 'backup_key_id', 'database_driver', 'app_version', 'schema_version', 'created_at'] as $key) {
            if (! is_string($manifest[$key] ?? null) || $manifest[$key] === '') {
                throw new OperationFailedException('manifest_malformed');
            }
        }
        $required = $manifest['required_key_ids'] ?? null;
        if (! is_array($required) || ! is_array($required['kek'] ?? null) || ! is_array($required['audit'] ?? null)
            || $required['kek'] === [] || $required['audit'] === []
            || ! is_bool($manifest['app_key_present'] ?? null) || ! is_bool($manifest['passkey_secret_present'] ?? null)
            || ! is_array($manifest['snapshot'] ?? null) || ! is_array($manifest['objects'] ?? null)
            || ! array_is_list($manifest['objects'])) {
            throw new OperationFailedException('manifest_malformed');
        }
        foreach ([...$required['kek'], ...$required['audit']] as $id) {
            if (! is_string($id) || $id === '') {
                throw new OperationFailedException('manifest_malformed');
            }
        }
        if (($manifest['snapshot']['file'] ?? null) !== BackupService::DATABASE_OBJECT) {
            throw new OperationFailedException('unsafe_path');
        }
        $this->file($manifest['snapshot']);
        $seen = [];
        foreach ($manifest['objects'] as $object) {
            if (! is_array($object) || ! in_array($object['kind'] ?? null, ['attachment', 'import_source'], true)
                || ! is_string($object['id'] ?? null) || $object['id'] === ''
                || ! is_string($object['source_path'] ?? null) || ! is_string($object['file'] ?? null)) {
                throw new OperationFailedException('manifest_malformed');
            }
            PrivatePaths::relative($object['source_path']);
            PrivatePaths::relative($object['file']);
            if (! str_starts_with($object['file'], 'objects/') || isset($seen['path:'.$object['source_path']])
                || isset($seen['file:'.$object['file']]) || isset($seen[$object['kind'].':'.$object['id']])) {
                throw new OperationFailedException('unsafe_path');
            }
            $seen['path:'.$object['source_path']] = true;
            $seen['file:'.$object['file']] = true;
            $seen[$object['kind'].':'.$object['id']] = true;
            $this->file($object);
        }
        $total = $manifest['snapshot']['bytes'] + array_sum(array_column($manifest['objects'], 'bytes'));
        if (($manifest['object_count'] ?? null) !== count($manifest['objects']) || ($manifest['total_bytes'] ?? null) !== $total) {
            throw new OperationFailedException('manifest_malformed');
        }
    }

    /** @param array<string, mixed> $file */
    private function file(array $file): void
    {
        if (! is_int($file['bytes'] ?? null) || $file['bytes'] < 0) {
            throw new OperationFailedException('manifest_malformed');
        }
        if (! is_string($file['checksum'] ?? null) || preg_match('/^[a-f0-9]{64}$/D', $file['checksum']) !== 1) {
            throw new OperationFailedException('integrity_failed');
        }
    }

    /**
     * @param  list<string>  $kekIds
     * @return array<string, string>
     */
    public function recoveryChecks(array $kekIds): array
    {
        $secrets = [
            'app_key' => (string) config('app.key'),
            'audit_key' => (string) config('vaultigen.audit_key'),
            'passkey_secret' => (string) config('vaultigen.passkey_secret'),
        ];
        foreach ($kekIds as $id) {
            $secrets['kek:'.$id] = $this->kek->key($id);
        }
        $checks = [];
        foreach ($secrets as $name => $secret) {
            if ($secret === '') {
                throw new OperationFailedException('recovery_input_missing');
            }
            $checks[$name] = hash_hmac('sha256', 'vaultigen-recovery-v2|'.config('vaultigen.installation_id').'|'.$name, $secret);
        }

        return $checks;
    }

    /** @param array<string, mixed> $manifest */
    public function verifyRecovery(array $manifest): void
    {
        if ($manifest['format_version'] === 1) {
            return;
        }
        $expected = $manifest['recovery_checks'] ?? null;
        $actual = $this->recoveryChecks($manifest['required_key_ids']['kek']);
        if (! is_array($expected) || array_keys($expected) !== array_keys($actual)) {
            throw new OperationFailedException('manifest_malformed');
        }
        foreach ($actual as $name => $check) {
            if (! is_string($expected[$name]) || ! hash_equals($check, $expected[$name])) {
                throw new OperationFailedException('recovery_input_mismatch');
            }
        }
    }
}
