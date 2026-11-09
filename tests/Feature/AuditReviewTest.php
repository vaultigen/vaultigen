<?php

use App\Filament\Pages\Activity;
use App\Models\User;
use App\Services\AuditWriter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;

test('redacted audit metadata is stored and hash-covered in the canonical event', function () {
    $admin = User::factory()->superAdmin()->withTwoFactor()->create();
    $alice = User::factory()->withTwoFactor()->create();
    $vault = vaultigen_make_vault($admin, $alice, ['name' => 'Operations']);
    $target = (string) Str::uuid();

    $sequence = app(AuditWriter::class)->record('entry.secret_delivered', $admin->id, 'success', [
        'vault_id' => $vault->id,
        'target_type' => 'entry',
        'target_id' => $target,
        'source_channel' => 'web',
        'sanitized_context' => ['field' => 'password', 'count' => 2],
        'ip_address' => '203.0.113.7',
        'user_agent' => 'SyntheticAgent/2.0',
    ]);

    $event = DB::table('audit_events')->where('sequence', $sequence)->sole();
    expect($event->vault_id)->toBe($vault->id)
        ->and($event->target_type)->toBe('entry')
        ->and($event->target_id)->toBe($target)
        ->and($event->source_channel)->toBe('web')
        ->and($event->actor_name_snapshot)->toBe($admin->name);

    $canonical = json_decode($event->canonical_event, true, 512, JSON_THROW_ON_ERROR);
    expect($canonical['version'])->toBe(2)
        ->and($canonical['vault_id'])->toBe($vault->id)
        ->and($canonical['sanitized_context'])->toBe(['field' => 'password', 'count' => 2]);

    $metadata = DB::table('audit_event_metadata')->where('sequence', $sequence)->sole();
    expect($metadata->ip_address)->toBe('203.0.113.7')
        ->and($metadata->user_agent)->toBe('SyntheticAgent/2.0');
});

test('malformed audit attributes are rejected or truncated without failing the write', function () {
    $sequence = app(AuditWriter::class)->record('test.invalid', null, 'success', [
        'vault_id' => 123,
        'target_id' => 'not-a-uuid',
        'sanitized_context' => 'not-an-array',
        'source_channel' => str_repeat('c', 60),
        'actor_name_snapshot' => str_repeat('n', 400),
        'ip_address' => ['array'],
        'user_agent' => 12345,
    ]);

    $event = DB::table('audit_events')->where('sequence', $sequence)->sole();
    expect($event->vault_id)->toBeNull()
        ->and($event->target_id)->toBeNull()
        ->and($event->sanitized_context)->toBeNull()
        ->and(mb_strlen((string) $event->source_channel))->toBe(20)
        ->and(mb_strlen((string) $event->actor_name_snapshot))->toBe(255);

    $metadata = DB::table('audit_event_metadata')->where('sequence', $sequence)->sole();
    expect($metadata->ip_address)->toBeNull()
        ->and($metadata->user_agent)->toBe('12345');
});

test('every event records request metadata by default', function () {
    $sequence = app(AuditWriter::class)->record('test.metadata');

    expect(DB::table('audit_event_metadata')->where('sequence', $sequence)->exists())->toBeTrue();
});

test('audit verification detects tampering of hash-covered metadata columns', function (string $column, string $value) {
    $audit = app(AuditWriter::class);
    $audit->record('test.event', null, 'success', ['vault_id' => (string) Str::uuid(), 'sanitized_context' => ['field' => 'password']]);

    DB::table('audit_events')->update([$column => $value]);

    expect($audit->verify())->toBeFalse();
})->with([
    ['vault_id', '00000000-0000-0000-0000-000000000000'],
    ['target_type', 'forged'],
    ['target_id', '00000000-0000-0000-0000-000000000000'],
    ['source_channel', 'forged'],
    ['sanitized_context', '{"forged":true}'],
    ['actor_name_snapshot', 'Forged Actor'],
    ['canonical_event', '{not valid json'],
]);

test('legacy version one events remain verifiable', function () {
    $time = now()->utc()->format('Y-m-d\TH:i:s.u\Z');
    $canonical = json_encode([
        'action' => 'legacy.event',
        'actor_id' => null,
        'installation_id' => config('vaultigen.installation_id'),
        'occurred_at' => $time,
        'outcome' => 'success',
        'sequence' => 1,
        'version' => 1,
    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    $previous = str_repeat('0', 64);
    $hash = hash('sha256', $previous."\n".$canonical);

    DB::table('audit_events')->insert([
        'sequence' => 1, 'actor_id' => null, 'action' => 'legacy.event', 'outcome' => 'success',
        'occurred_at' => $time, 'canonical_event' => $canonical, 'previous_hash' => $previous, 'event_hash' => $hash,
    ]);
    DB::table('audit_event_metadata')->insert(['sequence' => 1, 'created_at' => now()]);
    DB::table('audit_checkpoints')->insert([
        'sequence' => 1, 'event_hash' => $hash, 'key_id' => config('vaultigen.audit_key_id'),
        'mac' => hash_hmac('sha256', 'vaultigen-audit-v1|'.config('vaultigen.installation_id').'|1|'.$hash, base64_decode((string) config('vaultigen.audit_key'), true)),
    ]);
    DB::table('audit_chain_heads')->where('id', 1)->update(['sequence' => 1, 'event_hash' => $hash]);

    expect(app(AuditWriter::class)->verify())->toBeTrue();
});

test('the activity page filters by vault and source channel and shows redacted context', function () {
    $admin = User::factory()->superAdmin()->withTwoFactor()->create();
    $alice = User::factory()->withTwoFactor()->create();
    $vault = vaultigen_make_vault($admin, $alice, ['name' => 'Operations']);
    $this->actingAs($admin);

    $sequence = app(AuditWriter::class)->record('entry.secret_delivered', $admin->id, 'success', [
        'vault_id' => $vault->id,
        'target_type' => 'entry',
        'target_id' => (string) Str::uuid(),
        'source_channel' => 'web',
        'sanitized_context' => ['field' => 'password'],
        'ip_address' => '203.0.113.7',
        'user_agent' => 'SyntheticAgent/2.0',
    ]);
    app(AuditWriter::class)->record('installation.settings_changed', $admin->id);

    $page = Livewire::test(Activity::class);
    $page->set('tableFilters', ['vault_id' => ['value' => $vault->id]]);
    $actions = $page->instance()->getFilteredTableQuery()->pluck('action')->all();
    expect($actions)->toContain('entry.secret_delivered')->not->toContain('installation.settings_changed');

    $page->set('tableFilters', ['source_channel' => ['value' => 'web']]);
    expect($page->instance()->getFilteredTableQuery()->pluck('action')->all())->toContain('entry.secret_delivered');

    $page->mountTableAction('viewEvent', $sequence)->assertOk();
    expect($page->instance()->getMountedActions())->toHaveCount(1);
});

test('the activity page falls back to the actor snapshot and unknown channels and targets', function () {
    $admin = User::factory()->superAdmin()->withTwoFactor()->create();
    $actor = User::factory()->withTwoFactor()->create(['name' => 'Ada Lovelace']);
    $this->actingAs($admin);

    $sequence = app(AuditWriter::class)->record('legacy.target', $actor->id, 'success', [
        'target_type' => 'folder',
        'source_channel' => 'weird',
    ]);
    DB::table('audit_events')->where('sequence', $sequence)->update(['actor_id' => '00000000-0000-0000-0000-000000000000']);

    $page = Livewire::test(Activity::class);
    $page->assertSee('Ada Lovelace');

    $page->set('tableFilters', ['source_channel' => ['value' => 'weird']]);
    expect($page->instance()->getFilteredTableQuery()->pluck('action')->all())->toContain('legacy.target');

    $page->set('tableFilters', ['vault_id' => ['value' => '00000000-0000-0000-0000-000000000000']]);
    $page->mountTableAction('viewEvent', $sequence)->assertOk();
});

test('the activity page humanizes every supported action family', function () {
    $admin = User::factory()->superAdmin()->withTwoFactor()->create();
    $this->actingAs($admin);
    $audit = app(AuditWriter::class);
    foreach ([
        'attachment.deleted',
        'attachment.download_completed',
        'attachment.download_started',
        'attachment.uploaded',
        'attachment.validation_failed',
        'audit.pruned',
        'user.offboarded',
        'vault.converted',
        'vault.emergency_started',
        'vault.emergency_ended',
    ] as $action) {
        $audit->record($action, $admin->id);
    }

    Livewire::test(Activity::class)
        ->set('tableRecordsPerPage', 100)
        ->assertSee('Attachment deleted')
        ->assertSee('Emergency access started')
        ->assertSee('Emergency access ended')
        ->assertSee('Personal work vault converted to shared vault')
        ->assertSee('User offboarded');
});

test('the activity details handle a missing metadata row and empty context', function () {
    $admin = User::factory()->superAdmin()->withTwoFactor()->create();
    $this->actingAs($admin);
    $sequence = app(AuditWriter::class)->record('test.event');
    DB::table('audit_event_metadata')->where('sequence', $sequence)->delete();
    DB::table('audit_events')->where('sequence', $sequence)->update(['sanitized_context' => '"scalar"']);

    Livewire::test(Activity::class)->mountTableAction('viewEvent', $sequence)->assertOk();
});
