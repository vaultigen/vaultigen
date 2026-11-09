<?php

use App\Filament\Pages\Activity;
use App\Models\User;
use App\Services\AuditRetention;
use App\Services\AuditWriter;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

test('controlled pruning preserves a keyed checkpoint and a verifiable anchor', function () {
    $this->freezeTime();
    $audit = app(AuditWriter::class);
    for ($i = 0; $i < 3; $i++) {
        $audit->record(sprintf('old.event.%d', $i));
    }
    $this->travel(366)->days();
    $audit->record('new.event');

    $result = app(AuditRetention::class)->prune();

    expect($result['pruned'])->toBe(3)
        ->and($result['first_sequence'])->toBe(1)
        ->and($result['last_sequence'])->toBe(3)
        ->and($result['checkpoint_id'])->not->toBeNull()
        ->and($result['verified'])->toBeTrue()
        ->and(DB::table('audit_events')->count())->toBe(2)
        ->and(DB::table('audit_chain_heads')->value('anchor_sequence'))->toBe(3)
        ->and($audit->verify())->toBeTrue()
        ->and($audit->verifyDetail()['pruned'])->toBe(1)
        ->and($audit->verifyDetail()['anchor_sequence'])->toBe(3);

    $this->assertDatabaseHas('audit_prune_checkpoints', ['first_sequence' => 1, 'last_sequence' => 3, 'reason' => 'periodic']);
    $this->assertDatabaseHas('audit_events', ['action' => 'audit.pruned']);
});

test('pruning redacts ip and user-agent on their shorter schedule without touching events', function () {
    $this->freezeTime();
    app(AuditWriter::class)->record('with.ip', null, 'success', ['ip_address' => '203.0.113.9', 'user_agent' => 'SyntheticAgent/1.0']);
    $this->travel(40)->days();

    $result = app(AuditRetention::class)->prune();

    expect($result['pruned'])->toBe(0)
        ->and($result['redacted'])->toBe(1)
        ->and(DB::table('audit_event_metadata')->whereNotNull('sequence')->value('ip_address'))->toBeNull()
        ->and(DB::table('audit_event_metadata')->value('user_agent'))->toBeNull()
        ->and($result['verified'])->toBeTrue();
});

test('ip retention of zero keeps metadata for the life of the event', function () {
    DB::table('installation_settings')->where('id', 1)->update(['audit_ip_retention_days' => 0]);
    $this->freezeTime();
    app(AuditWriter::class)->record('with.ip', null, 'success', ['ip_address' => '203.0.113.9']);
    $this->travel(40)->days();

    $result = app(AuditRetention::class)->prune();

    expect($result['redacted'])->toBe(0)
        ->and(DB::table('audit_event_metadata')->value('ip_address'))->toBe('203.0.113.9');
});

test('pruning with no aged events is a no-op that still verifies', function () {
    $this->freezeTime();
    app(AuditWriter::class)->record('recent.event');

    $result = app(AuditRetention::class)->prune();

    expect($result['pruned'])->toBe(0)
        ->and($result['last_sequence'])->toBeNull()
        ->and($result['verified'])->toBeTrue();
});

test('a tampered prune checkpoint fails verification', function () {
    $this->freezeTime();
    app(AuditWriter::class)->record('old.event');
    $this->travel(366)->days();
    app(AuditWriter::class)->record('new.event');
    app(AuditRetention::class)->prune();

    DB::table('audit_prune_checkpoints')->update(['checkpoint_mac' => str_repeat('1', 64)]);

    expect(app(AuditWriter::class)->verify())->toBeFalse();
});

test('the prune command runs the retention workflow', function () {
    $this->freezeTime();
    app(AuditWriter::class)->record('old.event');
    $this->travel(366)->days();
    app(AuditWriter::class)->record('new.event');

    $this->artisan('vaultigen:prune-audit')
        ->expectsOutputToContain('Audit retention complete')
        ->assertSuccessful();
});

test('the audit page exposes the prune action only to installation managers', function () {
    $admin = User::factory()->superAdmin()->withTwoFactor()->create();
    $auditor = User::factory()->auditor()->withTwoFactor()->create();

    $this->actingAs($admin);
    Livewire::test(Activity::class)
        ->callAction('pruneAudit')
        ->assertNotified(__('activity.prune.done'));

    $this->actingAs($auditor);
    Livewire::test(Activity::class)->assertActionHidden('pruneAudit');
});
