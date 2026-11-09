<?php

use App\Auth\EmailAuthentication;
use App\Models\BackupRun;
use App\Models\Entry;
use App\Models\FileAttachment;
use App\Models\Folder;
use App\Models\ImportRun;
use App\Models\Tag;
use App\Models\User;
use App\Models\Vault;
use App\Services\Crypto\AssociatedData;
use App\Services\Crypto\FileStreamCipher;
use App\Services\EntryAttachments;
use App\Services\EntryPayload;
use App\Services\EntryVault;
use App\Services\Imports\ImportPlanEditor;
use App\Services\Imports\ImportPlanner;
use App\Services\Imports\ImportSourceStore;
use App\Services\Imports\Keepass\KeepassXmlDriver;
use App\Services\Imports\Syspass\SyspassXmlDriver;
use App\Services\Licensing\TrustedKeys;
use App\Services\Operations\BackupCipher;
use App\Services\Operations\BackupService;
use App\Services\ScopedGrants;
use App\Services\VaultAdministration;
use App\Services\VaultDirectory;
use App\Services\VaultFolders;
use App\Services\VaultMembership;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Fixtures\FakeBackupRuntime;
use Tests\Fixtures\LicenseVectorBuilder;
use Tests\OperationsTestCase;
use Tests\TestCase;

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

// Operations tests cover database snapshots, which cannot run inside the
// per-test transaction RefreshDatabase wraps around a test. Their base case
// gives them a freshly migrated database and clears it between tests.
pest()->extend(OperationsTestCase::class)->in('Operations');

final class VaultigenFailingReadStream
{
    /** @var resource|null */
    public $context;

    public function stream_open(string $path, string $mode, int $options, ?string &$openedPath): bool
    {
        return true;
    }

    public function stream_read(int $count): string|false
    {
        return false;
    }

    public function stream_eof(): bool
    {
        return false;
    }

    /**
     * @return array<string, int>|false
     */
    public function stream_stat(): array|false
    {
        return [];
    }
}

final class VaultigenFailingWriteStream
{
    /** @var resource|null */
    public $context;

    public function stream_open(string $path, string $mode, int $options, ?string &$openedPath): bool
    {
        return true;
    }

    public function stream_write(string $data): int
    {
        return 0;
    }

    public function stream_read(int $count): string
    {
        return '';
    }

    public function stream_eof(): bool
    {
        return true;
    }

    /**
     * @return array<string, int>|false
     */
    public function stream_stat(): array|false
    {
        return [];
    }
}

require_once __DIR__.'/Fixtures/FakeBackupRuntime.php';

pest()->afterEach(function (): void {
    FakeBackupRuntime::$overrides = [];
})->in('Operations');

pest()->beforeEach(function (): void {
    @stream_wrapper_register('vaultigen-failread', VaultigenFailingReadStream::class);
    @stream_wrapper_register('vaultigen-failwrite', VaultigenFailingWriteStream::class);

    config([
        'vaultigen.backup_temporary_root' => storage_path('framework/testing/backup-work'),
        'vaultigen.installation_id' => '550e8400-e29b-41d4-a716-446655440000',
        'vaultigen.passkey_secret' => base64_encode(str_repeat('p', 32)),
        'vaultigen.audit_key' => base64_encode(str_repeat('a', 32)),
        'vaultigen.kek_provider' => 'environment',
        'vaultigen.kek_keyring' => json_encode([
            'active_key_id' => 'kek-test-v1',
            'keys' => ['kek-test-v1' => base64_encode(str_repeat('k', 32))],
        ]),
    ]);
})->in('Feature', 'Operations');

/**
 * @param  array<string, mixed>  $attributes
 */
function vaultigen_make_vault(User $actor, User $manager, array $attributes = []): Vault
{
    $id = app(VaultAdministration::class)->create($actor, [
        'name' => $attributes['name'] ?? 'Operations',
        'description' => $attributes['description'] ?? null,
        'icon' => $attributes['icon'] ?? null,
        'primary_custodian_user_id' => $attributes['primary_custodian_user_id'] ?? $manager->id,
        'initial_manager_user_id' => $attributes['initial_manager_user_id'] ?? $manager->id,
    ]);

    return Vault::query()->findOrFail($id);
}

/**
 * @param  array<string, mixed>  $attributes
 */
function vaultigen_make_entry(User $actor, Vault $vault, array $attributes = []): Entry
{
    $input = array_merge([
        'title' => 'Example login',
        'owner_user_id' => $actor->id,
        'expires_at' => null,
        'generated' => false,
        'username' => 'alice',
        'password' => 'correct-horse-battery-staple-42',
        'url' => 'https://example.com/login',
        'notes' => 'Synthetic note',
    ], $attributes);
    $input['type'] = is_string($attributes['type'] ?? null) ? (string) $attributes['type'] : EntryPayload::TYPE_LOGIN;

    $id = app(EntryVault::class)->create($actor, $vault->id, $input);

    return Entry::query()->findOrFail($id);
}

/**
 * @param  array<string, mixed>  $fields
 */
function vaultigen_associated(array $fields): string
{
    return AssociatedData::encode($fields);
}

/**
 * @param  array<string, mixed>  $attributes
 */
function vaultigen_make_folder(User $actor, Vault $vault, string $name): Folder
{
    $id = app(VaultFolders::class)->create($actor, $vault->id, $name);

    return Folder::query()->findOrFail($id);
}

function vaultigen_make_tag(User $actor, Vault $vault, string $name, ?string $color = null): Tag
{
    $id = app(VaultDirectory::class)->createTag($actor, $vault->id, ['name' => $name, 'color' => $color]);

    return Tag::query()->findOrFail($id);
}

function vaultigen_make_global_tag(User $actor, string $name, ?string $color = null): Tag
{
    $id = app(VaultDirectory::class)->createTag($actor, null, ['name' => $name, 'color' => $color]);

    return Tag::query()->findOrFail($id);
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function vaultigen_entry_update(User $owner, array $overrides = []): array
{
    return array_merge([
        'title' => 'Example login',
        'owner_user_id' => $owner->id,
        'expires_at' => null,
        'generated' => false,
        'username' => null,
        'password' => null,
        'url' => null,
        'notes' => null,
    ], $overrides);
}

/**
 * A shared arrangement: one manager (Alice), one viewer (Bob) and one user with
 * no access (Carol) on Alice's vault.
 *
 * @return array{admin: User, alice: User, bob: User, carol: User, vault: Vault}
 */
function vaultigen_entry_fixture(): array
{
    $admin = User::factory()->superAdmin()->withTwoFactor()->create();
    $alice = User::factory()->withTwoFactor()->create();
    $bob = User::factory()->withTwoFactor()->create();
    $carol = User::factory()->withTwoFactor()->create();
    $vault = vaultigen_make_vault($admin, $alice);
    app(VaultMembership::class)->grant($alice, $vault->id, $bob->principal_id, 'viewer');

    return compact('admin', 'alice', 'bob', 'carol', 'vault');
}

/**
 * A shared arrangement with a vault, two folders, a root entry and a folded
 * entry. Bob holds a folder grant on Operations; Carol an entry grant on the
 * folder entry; Dave has no access.
 *
 * @return array{admin: User, alice: User, bob: User, carol: User, dave: User, vault: Vault, folderA: Folder, folderB: Folder, rootEntry: Entry, entryA: Entry}
 */
function vaultigen_scope_fixture(): array
{
    $admin = User::factory()->superAdmin()->withTwoFactor()->create();
    $alice = User::factory()->withTwoFactor()->create();
    $bob = User::factory()->withTwoFactor()->create();
    $carol = User::factory()->withTwoFactor()->create();
    $dave = User::factory()->withTwoFactor()->create();
    $vault = vaultigen_make_vault($admin, $alice);
    $folderA = vaultigen_make_folder($alice, $vault, 'Operations');
    $folderB = vaultigen_make_folder($alice, $vault, 'Servers');
    $rootEntry = vaultigen_make_entry($alice, $vault, ['title' => 'Root entry']);
    $entryA = vaultigen_make_entry($alice, $vault, ['title' => 'Folder entry']);
    app(EntryVault::class)->update($alice, $entryA->id, vaultigen_entry_update($alice, ['title' => 'Folder entry', 'folder_id' => $folderA->id]), 1);
    $entryA = Entry::query()->findOrFail($entryA->id);

    $grant = app(ScopedGrants::class);
    $grant->grantOnFolder($alice, $folderA->id, $bob->principal_id, 'editor');
    $grant->grantOnEntry($alice, $entryA->id, $carol->principal_id, 'viewer');

    return compact('admin', 'alice', 'bob', 'carol', 'dave', 'vault', 'folderA', 'folderB', 'rootEntry', 'entryA');
}

/**
 * Extract the entry form's non-secret Livewire state from a rendered page, so
 * tests can prove what is (and is not) bound into the component snapshot.
 *
 * @return array<string, mixed>
 */
function vaultigen_entry_form_state(string $html): array
{
    preg_match_all('/wire:snapshot="([^"]*)"/', $html, $matches);

    foreach ($matches[1] as $encoded) {
        $snapshot = json_decode(html_entity_decode($encoded), true, 512, JSON_THROW_ON_ERROR);
        if (! is_array($snapshot)) {
            continue;
        }

        $data = $snapshot['data']['data'] ?? null;

        // Livewire 4 wraps a serialized array as [value, metadata].
        if (is_array($data) && is_array($data[0] ?? null)) {
            $data = $data[0];
        }

        if (is_array($data) && array_key_exists('owner_user_id', $data)) {
            return $data;
        }
    }

    throw new RuntimeException('No entry form Livewire state was found.');
}

/**
 * Set the installation-wide MFA policy without going through the gate.
 *
 * @param  list<string>  $methods
 */
function vaultigen_configure_mfa(array $methods, int $rememberDeviceDays = 0): void
{
    DB::table('installation_settings')->where('id', 1)->update([
        'mfa_methods' => json_encode(array_values($methods), JSON_THROW_ON_ERROR),
        'remember_device_days' => $rememberDeviceDays,
    ]);
}

/**
 * Insert a trusted-device record and return its raw cookie selector and token.
 *
 * @return array{0: string, 1: string}
 */
function vaultigen_issue_trusted_device(User $user, int $days = 30): array
{
    $selector = bin2hex(random_bytes(16));
    $token = bin2hex(random_bytes(32));

    DB::table('trusted_devices')->insert([
        'id' => (string) Str::uuid(),
        'user_id' => $user->id,
        'selector' => $selector,
        'token_hash' => hash('sha256', $token),
        'user_agent' => 'PHPUnit',
        'ip_address' => '127.0.0.1',
        'created_at' => now(),
        'expires_at' => now()->addDays($days),
    ]);

    return [$selector, $token];
}

/**
 * Bind the email provider so it always issues a predictable code.
 */
function vaultigen_fake_email_code(string $code = '654321'): void
{
    app()->singleton(EmailAuthentication::class, fn (): EmailAuthentication => (new EmailAuthentication)->generateCodesUsing(fn (): string => $code));
}

/**
 * Use an isolated private disk for encrypted attachment objects.
 */
function vaultigen_fake_attachment_disk(): void
{
    Storage::fake('attachments');
}

/**
 * Upload synthetic bytes through the encrypted attachment pipeline.
 */
function vaultigen_upload_attachment(User $actor, Entry $entry, string $contents, string $name = 'notes.txt'): FileAttachment
{
    $id = app(EntryAttachments::class)->upload(
        $actor,
        $entry->id,
        UploadedFile::fake()->createWithContent($name, $contents),
    );

    return FileAttachment::query()->findOrFail($id);
}

/**
 * Decrypt an attachment through the authorized streaming path.
 */
function vaultigen_decrypt_attachment(FileAttachment $attachment): string
{
    $stream = fopen('php://temp', 'r+b');
    app(EntryAttachments::class)->streamTo($attachment, $stream);

    return vaultigen_stream_contents($stream);
}

/**
 * @return resource
 */
function vaultigen_stream_from(string $contents)
{
    $stream = fopen('php://temp', 'r+b');
    fwrite($stream, $contents);
    rewind($stream);

    return $stream;
}

/**
 * @return resource
 */
function vaultigen_stream_open()
{
    return fopen('php://temp', 'r+b');
}

/**
 * @param  resource  $stream
 */
function vaultigen_stream_contents($stream): string
{
    rewind($stream);
    $contents = stream_get_contents($stream);
    fclose($stream);

    return $contents === false ? '' : $contents;
}

/**
 * Run a backup synchronously as the given administrator and return the run.
 */
function vaultigen_run_backup(User $actor): BackupRun
{
    $service = app(BackupService::class);
    $run = $service->start($actor, BackupRun::TRIGGER_MANUAL);
    $service->run($run);

    return $run->refresh();
}

/**
 * The on-disk directory of a completed backup run.
 */
function vaultigen_backup_directory(BackupRun $run): string
{
    return Storage::disk('local')->path((string) $run->path);
}

/**
 * Build a syntactically valid backup set on disk for preflight tests.
 *
 * @param  array<string, mixed>  $overrides
 * @return array{0: string, 1: array<string, mixed>}
 */
function vaultigen_fake_backup_set(array $overrides = []): array
{
    $directory = sys_get_temp_dir().'/vaultigen-set-'.Str::uuid();
    mkdir($directory.'/objects', 0700, true);

    $snapshot = 'snapshot-ciphertext';
    file_put_contents($directory.'/database.enc', $snapshot);
    $object = 'object-ciphertext';
    file_put_contents($directory.'/objects/a.enc', $object);

    $manifest = array_merge([
        'format_version' => 1,
        'installation_id' => (string) config('vaultigen.installation_id'),
        'backup_run_id' => (string) Str::uuid(),
        'created_at' => now()->toIso8601String(),
        'app_version' => 'test',
        'schema_version' => '1-x',
        'database_driver' => DB::connection()->getDriverName(),
        'backup_key_id' => 'backup-test-v1',
        'snapshot' => ['file' => 'database.enc', 'checksum' => hash('sha256', $snapshot), 'bytes' => strlen($snapshot)],
        'required_key_ids' => ['kek' => ['kek-test-v1'], 'audit' => ['audit-v1']],
        'app_key_present' => false,
        'passkey_secret_present' => false,
        'objects' => [['kind' => 'attachment', 'id' => (string) Str::uuid(), 'file' => 'objects/a.enc', 'source_path' => 'x', 'bytes' => strlen($object), 'checksum' => hash('sha256', $object)]],
        'object_count' => 1,
        'total_bytes' => strlen($snapshot) + strlen($object),
    ], $overrides);

    if (is_array($manifest['snapshot']) && is_array($manifest['objects'])) {
        $manifest['object_count'] = count($manifest['objects']);
        $manifest['total_bytes'] = ($manifest['snapshot']['bytes'] ?? 0) + array_sum(array_column($manifest['objects'], 'bytes'));
    }

    $canonical = json_encode($manifest, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    file_put_contents($directory.'/manifest.json', $canonical);
    $cipher = new BackupCipher(app(FileStreamCipher::class));
    file_put_contents($directory.'/manifest.mac', $cipher->manifestMac((string) $manifest['backup_key_id'], str_repeat('b', 32), (string) $manifest['backup_run_id'], $canonical));

    return [$directory, $manifest];
}

function vaultigen_base64url_decode(string $value): string
{
    return sodium_base642bin($value, SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING);
}

function vaultigen_base64url_encode(string $value): string
{
    return sodium_bin2base64($value, SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING);
}

function vaultigen_flip_byte(string $binary, int $offset): string
{
    $binary[$offset] = chr(ord($binary[$offset]) ^ 0xFF);

    return $binary;
}

/**
 * @param  list<array<string, mixed>>  $accounts
 * @param  list<array<string, mixed>>  $clients
 * @param  list<array<string, mixed>>  $categories
 * @param  list<array<string, mixed>>  $tags
 * @param  array<string, mixed>  $meta
 * @return array<string, mixed>
 */
function vaultigen_syspass_ir(array $accounts = [], array $clients = [], array $categories = [], array $tags = [], array $meta = []): array
{
    return [
        'meta' => array_merge(['generator' => 'sysPass', 'version' => '322.21031301', 'user' => 'admin'], $meta),
        'categories' => $categories,
        'clients' => $clients,
        'tags' => $tags,
        'accounts' => $accounts,
    ];
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function vaultigen_syspass_account(string $id, array $overrides = []): array
{
    return array_merge([
        'id' => $id,
        'name' => 'Account '.$id,
        'client_id' => null,
        'category_id' => null,
        'login' => 'user'.$id,
        'url' => 'https://example.com/'.$id,
        'notes' => 'note '.$id,
        'pass' => 'secret-'.$id,
        'key' => '',
        'tag_ids' => [],
    ], $overrides);
}

/**
 * @param  array<string, mixed>  $ir
 * @param  array<string, mixed>  $input
 * @return array<string, mixed>
 */
function vaultigen_syspass_plan(array $ir, array $input, User $admin): array
{
    $driver = app(SyspassXmlDriver::class);

    return app(ImportPlanner::class)->resolve($driver, $driver->canonicalIr($ir), $input, $admin);
}

/**
 * @param  array<string, mixed>  $ir
 * @param  array<string, mixed>  $plan
 */
function vaultigen_staged_syspass_run(User $actor, array $ir, array $plan): ImportRun
{
    $run = ImportRun::factory()->create([
        'requested_by_user_id' => $actor->id,
        'status' => ImportRun::STATUS_STAGING,
    ]);
    $sealed = app(ImportSourceStore::class)->seal($run->id, json_encode($ir, JSON_THROW_ON_ERROR));
    $run->forceFill([...$sealed, 'status' => ImportRun::STATUS_STAGED, 'plan' => $plan])->save();

    return $run->refresh();
}

/**
 * Stage a KeePass XML export through the driver, as the upload request would.
 */
function vaultigen_staged_keepass_run(User $actor, string $xml): ImportRun
{
    return app(KeepassXmlDriver::class)->stage(
        UploadedFile::fake()->createWithContent('keepass.xml', $xml),
        [],
        $actor,
    );
}

/**
 * Stage a KeePass export and store a plan for it.
 *
 * @param  array<string, mixed>  $input
 */
function vaultigen_planned_keepass_run(User $actor, string $xml, array $input = []): ImportRun
{
    $run = vaultigen_staged_keepass_run($actor, $xml);
    app(ImportPlanEditor::class)->plan($run, [
        'layout' => 'single_vault',
        'vault_axis' => 'none',
        'folder_axis' => 'group_path',
        'top_group' => 'skip',
        'subgroup' => 'skip',
        'group_path' => 'folder',
        'single_vault_name' => 'KeePass',
        ...$input,
    ]);

    return $run->refresh();
}

/**
 * Make the public test signing key trusted for the rest of the test. Production
 * code never trusts it; this container binding is the only way it becomes valid.
 */
function vaultigen_trust_test_license_key(): void
{
    app()->instance(TrustedKeys::class, new TrustedKeys([
        LicenseVectorBuilder::TEST_KID => LicenseVectorBuilder::keypairFile()['public_key'],
    ]));
    app()->forgetScopedInstances();
}

/**
 * Store a test-key license in the installation settings, as an administrator would.
 *
 * @param  array<string, mixed>  $overrides
 */
function vaultigen_install_test_license(array $overrides = []): void
{
    vaultigen_trust_test_license_key();
    DB::table('installation_settings')->where('id', 1)->update(['license' => LicenseVectorBuilder::testLicense($overrides)]);
    app()->forgetScopedInstances();
}
