<?php

use App\Console\Commands\DeliverSecurityNotifications;
use App\Filament\Resources\Vaults\Pages\ListVaults;
use App\Filament\Resources\Vaults\Pages\ViewVault;
use App\Mail\VaultPurgeInitiated;
use App\Models\Entry;
use App\Models\KeyRotationRun;
use App\Models\User;
use App\Models\Vault;
use App\Services\AttachmentQuota;
use App\Services\AuditWriter;
use App\Services\EmergencyAccess;
use App\Services\EntryVault;
use App\Services\InstallationSettings;
use App\Services\Offboarding;
use App\Services\PersonalVaults;
use App\Services\TrashLifecycle;
use App\Services\VaultAccess;
use App\Services\VaultAdministration;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

beforeEach(function (): void {
    vaultigen_fake_attachment_disk();
});

function vault_purge_age(Vault $vault): void
{
    DB::table('vaults')->where('id', $vault->id)->update(['purge_after' => now()->subMinute()]);
}

test('marking a vault for purge ends access for every kind of grant', function () {
    ['admin' => $admin, 'alice' => $alice, 'bob' => $bob, 'carol' => $carol, 'vault' => $vault, 'folderA' => $folderA, 'entryA' => $entryA] = vaultigen_scope_fixture();
    DB::table('installation_settings')->where('id', 1)->update(['vault_purge_retention_days' => 12]);
    $access = app(VaultAccess::class);
    expect($access->allowsOnEntry($alice, $entryA, 'secret.reveal'))->toBeTrue();

    app(VaultAdministration::class)->initiatePurge($admin, $vault->id);

    expect($vault->fresh()->purge_after->isSameDay(now()->addDays(12)))->toBeTrue();
    foreach ([$alice, $bob, $carol] as $user) {
        expect($access->allowsOnEntry($user, $entryA, 'secret.reveal'))->toBeFalse()
            ->and($access->hasVaultAccess($user, $vault->id))->toBeFalse()
            ->and($access->visibleVaults($user)->whereKey($vault->id)->exists())->toBeFalse()
            ->and($access->visibleEntries($user, $vault->id)->exists())->toBeFalse()
            ->and($access->visibleFolders($user, $vault->id)->exists())->toBeFalse();
        expect(fn () => app(EntryVault::class)->reveal($user, $entryA->id, 'password'))->toThrow(NotFoundHttpException::class);
    }
    expect($access->allowsOnFolder($bob, $folderA->id, 'entry.update'))->toBeFalse();
});

test('emergency access cannot reach a vault marked for purge, even after it is restored', function () {
    ['admin' => $admin, 'vault' => $vault, 'entryA' => $entryA] = vaultigen_scope_fixture();
    $access = app(VaultAccess::class);
    DB::table('emergency_access_sessions')->insert([
        'id' => (string) Str::uuid(), 'super_admin_user_id' => $admin->id, 'vault_id' => $vault->id, 'reason' => 'Incident',
        'authentication_method' => 'password_totp', 'authenticated_at' => now(), 'started_at' => now(), 'expires_at' => now()->addMinutes(30),
        'created_at' => now(), 'updated_at' => now(),
    ]);
    expect($access->allowsOnEntry($admin, $entryA, 'secret.reveal'))->toBeTrue();

    $administration = app(VaultAdministration::class);
    $administration->initiatePurge($admin, $vault->id);
    expect($access->allowsOnEntry($admin, $entryA, 'secret.reveal'))->toBeFalse();
    expect(fn () => app(EmergencyAccess::class)->start($admin, $vault->id, 'Incident', null, 'password', '000000'))
        ->toThrow(ValidationException::class);

    $administration->cancelPurge($admin, $vault->id);
    expect($access->allowsOnEntry($admin, $entryA, 'secret.reveal'))->toBeFalse();
});

test('a Super Admin restores a vault to its previous state with access intact', function (string $previousState) {
    ['admin' => $admin, 'alice' => $alice, 'vault' => $vault, 'entryA' => $entryA] = vaultigen_scope_fixture();
    DB::table('vaults')->where('id', $vault->id)->update(['state' => $previousState]);
    $administration = app(VaultAdministration::class);
    $administration->initiatePurge($admin, $vault->id);

    $administration->cancelPurge($admin, $vault->id);

    $vault = $vault->fresh();
    expect($vault->state)->toBe($previousState)
        ->and($vault->purge_after)->toBeNull()
        ->and(app(VaultAccess::class)->allowsOnEntry($alice, $entryA, 'secret.reveal'))->toBeTrue();
    $this->assertDatabaseHas('audit_events', ['action' => 'vault.purge_cancelled', 'actor_id' => $admin->id, 'vault_id' => $vault->id]);

    vault_purge_age($vault);
    app(TrashLifecycle::class)->purgeDue();
    $this->assertDatabaseHas('vaults', ['id' => $vault->id]);
})->with(['active', 'read_only', 'archived']);

test('only a Super Admin can restore, and only a vault that is pending purge', function () {
    $admin = User::factory()->superAdmin()->withTwoFactor()->create();
    $custodian = User::factory()->withTwoFactor()->create();
    $vault = vaultigen_make_vault($admin, $admin, ['primary_custodian_user_id' => $custodian->id]);
    $administration = app(VaultAdministration::class);

    expect(fn () => $administration->cancelPurge($admin, $vault->id))->toThrow(ValidationException::class);

    $administration->initiatePurge($custodian, $vault->id);

    expect(fn () => $administration->cancelPurge($custodian, $vault->id))->toThrow(AuthorizationException::class);
    expect($vault->fresh()->state)->toBe('pending_purge');
});

test('manager recovery cannot reopen a vault that is pending purge', function () {
    $admin = User::factory()->superAdmin()->withTwoFactor()->create();
    $vault = vaultigen_make_vault($admin, $admin);
    $administration = app(VaultAdministration::class);
    $administration->initiatePurge($admin, $vault->id);

    expect(fn () => $administration->recover($admin, $vault->id, $admin->id, 'Reason', 'password', '000000'))
        ->toThrow(ValidationException::class);
});

test('the scheduled purge deletes a due vault with everything it references', function () {
    ['admin' => $admin, 'alice' => $alice, 'vault' => $vault, 'entryA' => $entryA, 'folderA' => $folderA] = vaultigen_scope_fixture();
    $other = vaultigen_make_vault($admin, $alice, ['name' => 'Unrelated vault']);
    $otherEntry = vaultigen_make_entry($alice, $other);
    $tag = vaultigen_make_tag($alice, $vault, 'Production');
    app(EntryVault::class)->update($alice, $entryA->id, vaultigen_entry_update($alice, ['title' => 'Tagged', 'folder_id' => $folderA->id, 'tags' => [$tag->id]]), $entryA->lock_version);
    $attachment = vaultigen_upload_attachment($alice, $entryA->fresh(), 'attachment-bytes');
    app(EntryVault::class)->reveal($alice, $entryA->id, 'password');
    $entryIds = Entry::query()->where('vault_id', $vault->id)->pluck('id')->all();
    app(VaultAdministration::class)->initiatePurge($admin, $vault->id);

    expect(app(TrashLifecycle::class)->purgeDue()['vaults'])->toBe(0);
    $this->assertDatabaseHas('vaults', ['id' => $vault->id]);

    vault_purge_age($vault);
    $result = app(TrashLifecycle::class)->purgeDue();

    expect($result['vaults'])->toBe(1)->and($result['failures'])->toBe(0);
    foreach (Schema::getTableListing(schemaQualified: false) as $table) {
        if ($table === 'audit_events') {
            continue;
        }
        if (Schema::hasColumn($table, 'vault_id')) {
            expect(DB::table($table)->where('vault_id', $vault->id)->count())->toBe(0, "{$table} kept vault rows");
        }
        if (Schema::hasColumn($table, 'entry_id')) {
            expect(DB::table($table)->whereIn('entry_id', $entryIds)->count())->toBe(0, "{$table} kept entry rows");
        }
    }
    $this->assertDatabaseMissing('vaults', ['id' => $vault->id]);
    Storage::disk('attachments')->assertMissing($attachment->random_storage_path);
    $this->assertDatabaseHas('audit_events', ['action' => 'vault.purged', 'outcome' => 'success', 'vault_id' => $vault->id]);
    expect(Vault::query()->whereKey($other->id)->exists())->toBeTrue()
        ->and(app(EntryVault::class)->find($alice, $otherEntry->id)->id)->toBe($otherEntry->id);
});

test('a failed vault purge is recorded and stays eligible for the next run', function () {
    $admin = User::factory()->superAdmin()->withTwoFactor()->create();
    $vault = vaultigen_make_vault($admin, $admin);
    app(VaultAdministration::class)->initiatePurge($admin, $vault->id);
    vault_purge_age($vault);
    $lifecycle = Mockery::mock(TrashLifecycle::class, [
        app(AuditWriter::class),
        app(VaultAccess::class),
        app(InstallationSettings::class),
        app(AttachmentQuota::class),
    ])->makePartial();
    $lifecycle->shouldReceive('purgeVault')->once()->andThrow(new RuntimeException('boom'));

    $result = $lifecycle->purgeDue();

    expect($result['vaults'])->toBe(0)->and($result['failures'])->toBe(1);
    $this->assertDatabaseHas('audit_events', ['action' => 'vault.purged', 'outcome' => 'failed', 'vault_id' => $vault->id]);
    expect(app(TrashLifecycle::class)->purgeDue()['vaults'])->toBe(1);
});

test('purging a vault directly does nothing before its deadline', function () {
    $admin = User::factory()->superAdmin()->withTwoFactor()->create();
    $vault = vaultigen_make_vault($admin, $admin);
    app(VaultAdministration::class)->initiatePurge($admin, $vault->id);

    app(TrashLifecycle::class)->purgeVault($vault->id);

    $this->assertDatabaseHas('vaults', ['id' => $vault->id, 'state' => 'pending_purge']);
    $this->assertDatabaseMissing('audit_events', ['action' => 'vault.purged']);
});

test('a vault with a key rotation in flight waits for the next purge run', function () {
    $admin = User::factory()->superAdmin()->withTwoFactor()->create();
    $vault = vaultigen_make_vault($admin, $admin);
    app(VaultAdministration::class)->initiatePurge($admin, $vault->id);
    vault_purge_age($vault);
    DB::table('key_rotation_runs')->insert([
        'id' => $runId = (string) Str::uuid(), 'operation' => 'vault_rotation', 'vault_id' => $vault->id,
        'source_key_id' => 'source', 'target_key_id' => 'target', 'status' => KeyRotationRun::STATUS_RUNNING,
        'created_at' => now(), 'updated_at' => now(),
    ]);

    expect(app(TrashLifecycle::class)->purgeDue()['vaults'])->toBe(0);

    DB::table('key_rotation_runs')->where('id', $runId)->update(['status' => KeyRotationRun::STATUS_COMPLETED]);
    expect(app(TrashLifecycle::class)->purgeDue()['vaults'])->toBe(1);
});

test('offboarding uses the same purge lifecycle for a personal vault', function () {
    $admin = User::factory()->superAdmin()->withTwoFactor()->create();
    $target = User::factory()->withTwoFactor()->create();
    $vault = Vault::query()->findOrFail(app(PersonalVaults::class)->provisionFor($target));
    DB::table('installation_settings')->where('id', 1)->update(['vault_purge_retention_days' => 12]);

    app(Offboarding::class)->offboard($admin, $target, ['personal' => [['vault_id' => $vault->id, 'decision' => 'purge']]]);

    expect($vault->fresh()->purge_after->isSameDay(now()->addDays(12)))->toBeTrue();
    vault_purge_age($vault);
    expect(app(TrashLifecycle::class)->purgeDue()['vaults'])->toBe(1);
    $this->assertDatabaseMissing('vaults', ['id' => $vault->id]);
});

test('the purge alert goes to security recipients only while the setting is on', function () {
    $admin = User::factory()->superAdmin()->withTwoFactor()->create();
    $first = vaultigen_make_vault($admin, $admin, ['name' => 'Alerted']);
    $second = vaultigen_make_vault($admin, $admin, ['name' => 'Silent']);
    DB::table('installation_settings')->where('id', 1)->update(['security_recipients' => json_encode(['security@example.com'])]);
    $administration = app(VaultAdministration::class);

    $administration->initiatePurge($admin, $first->id);
    expect(DB::table('security_notification_deliveries')->where('recipient', 'security@example.com')->count())->toBe(1);

    DB::table('installation_settings')->where('id', 1)->update(['vault_purge_notifications_enabled' => false]);
    $administration->initiatePurge($admin, $second->id);
    expect(DB::table('security_notification_deliveries')->count())->toBe(1);

    config(['mail.default' => 'smtp']);
    Mail::fake();
    $this->artisan(DeliverSecurityNotifications::class)->assertSuccessful();
    Mail::assertSent(VaultPurgeInitiated::class, fn (VaultPurgeInitiated $mail): bool => $mail->hasTo('security@example.com'));
    expect((new VaultPurgeInitiated(1))->render())->toContain('A vault was marked for deletion');
});

test('the purge settings are validated and saved', function () {
    $admin = User::factory()->superAdmin()->withTwoFactor()->create();
    $settings = app(InstallationSettings::class);

    expect(fn () => $settings->save($admin, [...$settings->values(), 'vault_purge_retention_days' => 0]))->toThrow(ValidationException::class);

    $settings->save($admin, [...$settings->values(), 'vault_purge_retention_days' => 45, 'vault_purge_notifications_enabled' => false]);

    expect($settings->values()['vault_purge_retention_days'])->toBe(45)
        ->and($settings->values()['vault_purge_notifications_enabled'])->toBeFalse();
});

test('vault administration shows the deletion date and offers restore to Super Admins only', function () {
    $admin = User::factory()->superAdmin()->withTwoFactor()->create();
    $custodian = User::factory()->withTwoFactor()->create();
    $vault = vaultigen_make_vault($admin, $admin, ['primary_custodian_user_id' => $custodian->id]);
    app(VaultAdministration::class)->initiatePurge($custodian, $vault->id);

    $this->actingAs($custodian);
    Livewire::test(ListVaults::class)->assertCanSeeTableRecords([$vault])->assertTableActionHidden('cancelPurge', $vault);
    Livewire::test(ViewVault::class, ['record' => $vault->id])->assertOk();

    $this->actingAs($admin);
    Livewire::test(ListVaults::class)
        ->assertTableActionHidden('purge', $vault)
        ->assertTableActionHidden('recover', $vault)
        ->callTableAction('cancelPurge', $vault)
        ->assertNotified('Vault restored');
    expect($vault->fresh()->state)->toBe('active');
});

test('upgrading gives vaults already pending purge a full waiting period', function () {
    $admin = User::factory()->superAdmin()->withTwoFactor()->create();
    $vault = vaultigen_make_vault($admin, $admin);
    $this->artisan('migrate:rollback', ['--step' => 1, '--force' => true]);
    DB::table('vaults')->where('id', $vault->id)->update(['state' => 'pending_purge', 'purge_after' => now()->subYear()]);

    $this->artisan('migrate', ['--force' => true]);

    expect(app(TrashLifecycle::class)->purgeDue()['vaults'])->toBe(0);
    $vault = $vault->fresh();
    expect($vault->purge_after->isFuture())->toBeTrue()
        ->and($vault->purge_previous_state)->toBe('archived');
});
