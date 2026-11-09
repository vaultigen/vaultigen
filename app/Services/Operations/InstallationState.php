<?php

namespace App\Services\Operations;

use App\Contracts\KekProvider;
use Illuminate\Database\Migrations\Migrator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Read-only checks shared by container setup and the migration gate: database
 * credentials, the presence of every recovery input, whether the database
 * already holds an installation, pending migrations, and the ownership of a
 * group-readable backup directory.
 */
final class InstallationState
{
    public const PASSWORD_PLACEHOLDER = 'change-me';

    public const MINIMUM_PASSWORD_LENGTH = 24;

    public function __construct(
        private readonly BackupKeyring $backupKeys,
        private readonly Migrator $migrator,
    ) {}

    /**
     * A reason the configured database password is unacceptable, or null.
     */
    public function databasePasswordProblem(): ?string
    {
        $connection = (string) config('database.default');
        if (config("database.connections.{$connection}.driver") === 'sqlite') {
            return null;
        }
        $password = (string) config("database.connections.{$connection}.password");
        if ($password === self::PASSWORD_PLACEHOLDER) {
            return 'DB_PASSWORD still has the placeholder value. Generate one, for example with: openssl rand -hex 32';
        }
        if (strlen($password) < self::MINIMUM_PASSWORD_LENGTH) {
            return 'DB_PASSWORD must be at least '.self::MINIMUM_PASSWORD_LENGTH.' characters long.';
        }

        return null;
    }

    /**
     * @return array{app_key: bool, installation: bool, kek: bool, backup_key: bool}
     */
    public function keys(): array
    {
        try {
            $kek = app(KekProvider::class)->activeKeyId() !== '';
        } catch (Throwable) {
            $kek = false;
        }

        return [
            'app_key' => filled(config('app.key')),
            'installation' => filled(config('vaultigen.installation_id')) && filled(config('vaultigen.passkey_secret')) && filled(config('vaultigen.audit_key')),
            'kek' => $kek,
            'backup_key' => $this->backupKeys->available(),
        ];
    }

    /**
     * @return list<string>
     */
    public function missingKeys(): array
    {
        return array_keys(array_filter($this->keys(), fn (bool $present): bool => ! $present));
    }

    /**
     * Whether migrations have already been applied to this database.
     */
    public function databaseMigrated(): bool
    {
        return Schema::hasTable('migrations') && DB::table('migrations')->exists();
    }

    /**
     * The installation identity recorded by the first administrator bootstrap.
     */
    public function bootstrappedInstallationId(): ?string
    {
        if (! Schema::hasTable('installation_settings')) {
            return null;
        }
        $settings = DB::table('installation_settings')->where('id', 1)->whereNotNull('bootstrapped_at')->first(['installation_id']);

        return $settings === null ? null : (string) $settings->installation_id;
    }

    /**
     * @return list<string>
     */
    public function pendingMigrations(): array
    {
        $repository = $this->migrator->getRepository();
        $ran = $repository->repositoryExists() ? $repository->getRan() : [];
        $files = $this->migrator->getMigrationFiles([database_path('migrations'), ...$this->migrator->paths()]);

        return array_values(array_diff(array_keys($files), $ran));
    }
}
