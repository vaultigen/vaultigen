<?php

use App\Models\AuditEvent;
use App\Models\Team;
use App\Models\User;
use App\Services\AuditWriter;
use App\Services\Crypto\EntryCipher;
use App\Services\Crypto\VaultKeyManager;
use App\Services\EntryVault;
use App\Services\VaultAdministration;
use App\Services\VaultMembership;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

test('creating an entry stores only encrypted secrets and a safe projection', function () {
    ['alice' => $alice, 'vault' => $vault] = vaultigen_entry_fixture();

    $entry = vaultigen_make_entry($alice, $vault, [
        'password' => 'UniquePassword-Zx9-Aaa',
        'username' => 'UniqueUsername-Zx9-Bbb',
        'url' => 'https://example.com/deep/path?token=UniqueUrlToken-Zx9',
    ]);

    expect($entry->type)->toBe('login')
        ->and($entry->title)->toBe('Example login')
        ->and($entry->normalized_title)->toBe('example login')
        ->and($entry->url_host)->toBe('example.com')
        ->and($entry->lock_version)->toBe(1);

    $raw = DB::table('entries')->where('id', $entry->id)->sole();
    expect($raw->encrypted_payload)->not->toContain('UniquePassword-Zx9-Aaa')
        ->and($raw->encrypted_payload)->not->toContain('UniqueUsername-Zx9-Bbb')
        ->and($raw->url_host)->toBe('example.com')
        ->and($raw->url_host)->not->toContain('UniqueUrlToken-Zx9');

    $this->assertDatabaseHas('audit_events', ['action' => 'entry.created']);
});

test('entry writes and reads are denied beyond the effective vault authority', function () {
    ['alice' => $alice, 'bob' => $bob, 'carol' => $carol, 'vault' => $vault] = vaultigen_entry_fixture();
    $entry = vaultigen_make_entry($alice, $vault);

    expect(fn () => vaultigen_make_entry($bob, $vault))->toThrow(AuthorizationException::class);
    expect(fn () => vaultigen_make_entry($carol, $vault))->toThrow(NotFoundHttpException::class);
    expect(fn () => app(EntryVault::class)->query($carol, $vault->id))->toThrow(NotFoundHttpException::class);

    expect(fn () => app(EntryVault::class)->update($bob, $entry->id, vaultigen_entry_update($bob), 1))
        ->toThrow(AuthorizationException::class);
    expect(fn () => app(EntryVault::class)->update($carol, $entry->id, vaultigen_entry_update($carol), 1))
        ->toThrow(NotFoundHttpException::class);
    expect(fn () => app(EntryVault::class)->reveal($carol, $entry->id, 'password'))
        ->toThrow(NotFoundHttpException::class);
    expect(fn () => app(EntryVault::class)->restore($bob, $entry->id, (string) Str::uuid()))
        ->toThrow(AuthorizationException::class);
});

test('entries cannot be created or changed in a vault that is not active', function () {
    ['admin' => $admin, 'alice' => $alice, 'vault' => $vault] = vaultigen_entry_fixture();
    $entry = vaultigen_make_entry($alice, $vault);

    app(VaultAdministration::class)->setState($admin, $vault->id, 'read_only');

    expect(fn () => vaultigen_make_entry($alice, $vault))->toThrow(ValidationException::class);
    expect(fn () => app(EntryVault::class)->update($alice, $entry->id, vaultigen_entry_update($alice), 1))
        ->toThrow(ValidationException::class);
    expect(fn () => app(EntryVault::class)->restore($alice, $entry->id, (string) Str::uuid()))
        ->toThrow(ValidationException::class);
});

test('an entry owner must already have access to the vault', function () {
    ['alice' => $alice, 'bob' => $bob, 'carol' => $carol, 'vault' => $vault] = vaultigen_entry_fixture();

    expect(fn () => vaultigen_make_entry($alice, $vault, ['owner_user_id' => $carol->id]))
        ->toThrow(ValidationException::class);

    $entry = vaultigen_make_entry($alice, $vault, ['owner_user_id' => $bob->id]);
    expect($entry->owner_user_id)->toBe($bob->id);
});

test('owner options include direct grants and team members', function () {
    ['alice' => $alice, 'vault' => $vault] = vaultigen_entry_fixture();
    $team = Team::factory()->create();
    $dave = User::factory()->withTwoFactor()->create();
    app(VaultMembership::class)->grant($alice, $vault->id, $team->principal_id, 'viewer');
    DB::table('team_memberships')->insert([
        'team_id' => $team->id,
        'user_id' => $dave->id,
        'created_by_user_id' => $alice->id,
        'created_at' => now(),
    ]);

    $options = app(EntryVault::class)->ownerOptions($vault);

    expect($options)->toHaveKey($alice->id)
        ->and($options)->toHaveKey($dave->id)
        ->and($options)->not->toHaveKey(User::factory()->withTwoFactor()->create()->id);
});

test('revealing delivers only the requested field and audits each delivery', function () {
    ['alice' => $alice, 'bob' => $bob, 'vault' => $vault] = vaultigen_entry_fixture();
    $entry = vaultigen_make_entry($alice, $vault, [
        'username' => 'UniqueUsername-Zx9',
        'password' => 'UniquePassword-Zx9',
    ]);

    expect(app(EntryVault::class)->reveal($bob, $entry->id, 'password'))->toBe('UniquePassword-Zx9')
        ->and(app(EntryVault::class)->reveal($bob, $entry->id, 'username'))->toBe('UniqueUsername-Zx9');

    expect(AuditEvent::query()->where('action', 'entry.secret_delivered')->count())->toBe(2);

    expect(app(EntryVault::class)->reveal($alice, $entry->id, 'totp_secret'))->toBe('');

    expect(fn () => app(EntryVault::class)->reveal($alice, $entry->id, 'bogus'))
        ->toThrow(NotFoundHttpException::class);
});

test('an update keeps blank secrets records a revision and bumps the lock', function () {
    ['alice' => $alice, 'vault' => $vault] = vaultigen_entry_fixture();
    $entry = vaultigen_make_entry($alice, $vault, [
        'username' => 'OriginalUsername',
        'password' => 'OriginalPassword',
    ]);

    app(EntryVault::class)->update($alice, $entry->id, vaultigen_entry_update($alice, [
        'title' => 'Renamed login',
        'password' => 'RotatedPassword',
    ]), 1);

    $fresh = $entry->fresh();
    expect($fresh->title)->toBe('Renamed login')
        ->and($fresh->normalized_title)->toBe('renamed login')
        ->and($fresh->lock_version)->toBe(2)
        ->and(app(EntryVault::class)->reveal($alice, $entry->id, 'password'))->toBe('RotatedPassword')
        ->and(app(EntryVault::class)->reveal($alice, $entry->id, 'username'))->toBe('OriginalUsername');

    expect(DB::table('entry_revisions')->where('entry_id', $entry->id)->count())->toBe(1);
    $this->assertDatabaseHas('audit_events', ['action' => 'entry.updated']);
});

test('a stale update is rejected without overwriting the newer change', function () {
    ['alice' => $alice, 'vault' => $vault] = vaultigen_entry_fixture();
    $entry = vaultigen_make_entry($alice, $vault);

    app(EntryVault::class)->update($alice, $entry->id, vaultigen_entry_update($alice, ['title' => 'First save']), 1);

    expect(fn () => app(EntryVault::class)->update($alice, $entry->id, vaultigen_entry_update($alice, ['title' => 'Second save']), 1))
        ->toThrow(ValidationException::class);

    expect($entry->fresh()->title)->toBe('First save');
});

test('history is manager-only and names the changing user', function () {
    ['alice' => $alice, 'bob' => $bob, 'carol' => $carol, 'vault' => $vault] = vaultigen_entry_fixture();
    $entry = vaultigen_make_entry($alice, $vault);
    app(EntryVault::class)->update($alice, $entry->id, vaultigen_entry_update($alice, ['password' => 'Rotated']), 1);

    $history = app(EntryVault::class)->history($alice, $entry->id);
    expect($history)->toHaveCount(1)
        ->and($history->first()->changedBy?->id)->toBe($alice->id);

    expect(fn () => app(EntryVault::class)->history($bob, $entry->id))->toThrow(AuthorizationException::class);
    expect(fn () => app(EntryVault::class)->history($carol, $entry->id))->toThrow(NotFoundHttpException::class);
});

test('restoring a revision brings back the previous values and keeps history', function () {
    ['alice' => $alice, 'vault' => $vault] = vaultigen_entry_fixture();
    $entry = vaultigen_make_entry($alice, $vault, ['password' => 'PasswordOne', 'username' => 'UsernameOne']);

    app(EntryVault::class)->update($alice, $entry->id, vaultigen_entry_update($alice, ['password' => 'PasswordTwo']), 1);
    app(EntryVault::class)->update($alice, $entry->id, vaultigen_entry_update($alice, ['password' => 'PasswordThree']), 2);

    $first = DB::table('entry_revisions')->where('entry_id', $entry->id)->where('revision_number', 1)->sole();
    app(EntryVault::class)->restore($alice, $entry->id, $first->id);

    expect(app(EntryVault::class)->reveal($alice, $entry->id, 'password'))->toBe('PasswordOne')
        ->and(app(EntryVault::class)->reveal($alice, $entry->id, 'username'))->toBe('UsernameOne')
        ->and(DB::table('entry_revisions')->where('entry_id', $entry->id)->count())->toBe(3);

    $this->assertDatabaseHas('audit_events', ['action' => 'entry.restored']);
});

test('restoring a missing revision is a 404 and a malformed snapshot fails closed', function () {
    ['alice' => $alice, 'vault' => $vault] = vaultigen_entry_fixture();
    $entry = vaultigen_make_entry($alice, $vault);

    expect(fn () => app(EntryVault::class)->restore($alice, $entry->id, (string) Str::uuid()))
        ->toThrow(NotFoundHttpException::class);

    $versionId = app(VaultKeyManager::class)->activeVersionId($vault->id);
    $revisionId = (string) Str::uuid();
    $sealed = app(EntryCipher::class)->sealSnapshot($vault->id, $revisionId, $versionId, json_encode(['nope' => true], JSON_THROW_ON_ERROR));
    DB::table('entry_revisions')->insert([
        'id' => $revisionId,
        'vault_id' => $vault->id,
        'entry_id' => $entry->id,
        'revision_number' => 99,
        'vault_key_version_id' => $versionId,
        'cipher_suite' => 'xchacha20-poly1305',
        'payload_format_version' => 1,
        'payload_schema_version' => 1,
        'payload_nonce' => $sealed->nonce,
        'encrypted_snapshot' => $sealed->envelope,
        'changed_by_user_id' => $alice->id,
        'created_at' => now(),
    ]);

    expect(fn () => app(EntryVault::class)->restore($alice, $entry->id, $revisionId))
        ->toThrow(RuntimeException::class);

    expect(DB::table('entry_revisions')->where('entry_id', $entry->id)->count())->toBe(1);
});

test('entry queries and lookups hide other vaults and soft-deleted rows', function () {
    ['admin' => $admin, 'alice' => $alice, 'vault' => $vault] = vaultigen_entry_fixture();
    $entry = vaultigen_make_entry($alice, $vault);
    $other = vaultigen_make_vault($admin, $admin, ['name' => 'Other']);
    $foreign = vaultigen_make_entry($admin, $other);

    expect(app(EntryVault::class)->query($alice, $vault->id)->pluck('id')->all())->toContain($entry->id);
    expect(fn () => app(EntryVault::class)->find($alice, $foreign->id))->toThrow(NotFoundHttpException::class);
    expect(fn () => app(EntryVault::class)->find($alice, (string) Str::uuid()))->toThrow(NotFoundHttpException::class);

    DB::table('entries')->where('id', $entry->id)->update(['deleted_at' => now()]);
    expect(app(EntryVault::class)->query($alice, $vault->id)->pluck('id')->all())->not->toContain($entry->id);
});

test('secret delivery fails closed when the delivery audit cannot be written', function () {
    ['alice' => $alice, 'vault' => $vault] = vaultigen_entry_fixture();
    $entry = vaultigen_make_entry($alice, $vault);

    $writer = Mockery::mock(AuditWriter::class)->makePartial();
    $writer->shouldReceive('record')->withArgs(fn (string $action, ?string $actorId): bool => $action === 'entry.secret_delivered' && $actorId === $alice->id)->once()
        ->andThrow(new RuntimeException('audit storage is unavailable'));
    $this->app->instance(AuditWriter::class, $writer);

    expect(fn () => app(EntryVault::class)->reveal($alice, $entry->id, 'password'))
        ->toThrow(RuntimeException::class);
});
