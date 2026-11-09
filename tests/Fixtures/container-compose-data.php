<?php

/*
 * Synthetic data for Compose recovery drills, piped into the web container:
 *   docker compose exec -T web php -- seed   < tests/Fixtures/container-compose-data.php
 *   docker compose exec -T web php -- verify < tests/Fixtures/container-compose-data.php
 * "seed" enrolls an authenticator for the first administrator and stores a
 * known secret and attachment; "verify" decrypts both and checks the audit
 * chain, printing a JSON summary that includes an authenticator fingerprint.
 */

use App\Models\Entry;
use App\Models\FileAttachment;
use App\Models\User;
use App\Services\AuditWriter;
use App\Services\EntryAttachments;
use App\Services\EntryVault;
use App\Services\VaultAdministration;
use Filament\Auth\MultiFactor\App\AppAuthentication;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;

chdir('/app');
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

const DRILL_SECRET = 'container-drill-secret-7f3a';
const DRILL_ATTACHMENT = 'container drill attachment bytes';

$admin = User::query()->orderBy('created_at')->firstOrFail();

if (($argv[1] ?? '') === 'seed') {
    $admin->forceFill([
        'app_authentication_secret' => AppAuthentication::make()->generateSecret(),
        'app_authentication_recovery_codes' => [Hash::make('drill-recovery-code')],
    ])->save();
    $vaultId = app(VaultAdministration::class)->create($admin, [
        'name' => 'Recovery drill', 'description' => null, 'icon' => null,
        'primary_custodian_user_id' => $admin->id, 'initial_manager_user_id' => $admin->id,
    ]);
    $entryId = app(EntryVault::class)->create($admin, $vaultId, [
        'type' => 'login', 'title' => 'Drill login', 'owner_user_id' => $admin->id, 'expires_at' => null,
        'generated' => false, 'username' => 'drill', 'password' => DRILL_SECRET, 'url' => null, 'notes' => null,
    ]);
    app(EntryAttachments::class)->upload($admin, $entryId, UploadedFile::fake()->createWithContent('drill.txt', DRILL_ATTACHMENT));
}

$entry = Entry::query()->where('title', 'Drill login')->firstOrFail();
$attachment = FileAttachment::query()->where('entry_id', $entry->id)->firstOrFail();
$stream = fopen('php://temp', 'r+b');
app(EntryAttachments::class)->streamTo($attachment, $stream);
rewind($stream);

echo json_encode([
    'installation_id' => config('vaultigen.installation_id'),
    'administrator' => $admin->email,
    'authenticator' => hash('sha256', (string) $admin->app_authentication_secret),
    'secret' => app(EntryVault::class)->reveal($admin, $entry->id, 'password') === DRILL_SECRET,
    'attachment' => stream_get_contents($stream) === DRILL_ATTACHMENT,
    'audit' => app(AuditWriter::class)->verify(),
], JSON_THROW_ON_ERROR).PHP_EOL;
