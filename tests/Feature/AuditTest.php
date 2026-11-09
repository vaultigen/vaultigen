<?php

use App\Models\User;
use App\Services\AuditWriter;
use App\Services\InstallationSettings;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

test('audit checkpoints verify and event encoding is deterministic and secret free', function () {
    $this->freezeTime();
    $audit = app(AuditWriter::class);
    $audit->record('test.event');
    $event = DB::table('audit_events')->first();
    $expected = '{"action":"test.event","actor_id":null,"actor_name_snapshot":null,"installation_id":"550e8400-e29b-41d4-a716-446655440000","occurred_at":"'.now()->utc()->format('Y-m-d\TH:i:s.u\Z').'","outcome":"success","sanitized_context":null,"sequence":1,"source_channel":"console","target_id":null,"target_type":null,"vault_id":null,"version":2}';
    expect($event->canonical_event)->toBe($expected);
    expect($audit->verify())->toBeTrue();
});

test('audit verification detects database tampering', function (string $table, array $change) {
    $audit = app(AuditWriter::class);
    $audit->record('test.event');
    DB::table($table)->update($change);
    expect($audit->verify())->toBeFalse();
})->with([
    ['audit_events', ['action' => 'forged']],
    ['audit_events', ['previous_hash' => str_repeat('1', 64)]],
    ['audit_events', ['event_hash' => str_repeat('1', 64)]],
    ['audit_events', ['sequence' => 2]],
    ['audit_checkpoints', ['mac' => str_repeat('1', 64)]],
    ['audit_checkpoints', ['key_id' => 'unknown']],
    ['audit_chain_heads', ['sequence' => 99]],
]);

test('missing events or checkpoints cannot verify', function (string $table) {
    $audit = app(AuditWriter::class);
    $audit->record('test.event');
    DB::table($table)->delete();
    expect($audit->verify())->toBeFalse();
})->with(['audit_events', 'audit_checkpoints', 'audit_chain_heads']);

test('missing external keys fail closed before settings or account changes', function () {
    $admin = User::factory()->superAdmin()->withTwoFactor()->create();
    config(['vaultigen.audit_key' => null]);
    expect(fn () => app(InstallationSettings::class)->save($admin, [...app(InstallationSettings::class)->values(), 'display_name' => 'Unsafe']))->toThrow(RuntimeException::class, 'Installation security configuration');
    expect(DB::table('installation_settings')->value('display_name'))->toBe('Vaultigen');
    expect(fn () => $admin->update(['name' => 'Unsafe']))->toThrow(RuntimeException::class);
    expect($admin->fresh()->name)->not->toBe('Unsafe');
});

test('a failed audit insert rolls back its associated settings write and chain allocation', function () {
    $admin = User::factory()->superAdmin()->withTwoFactor()->create();
    $head = DB::table('audit_chain_heads')->value('sequence');
    DB::table('audit_checkpoints')->insert(['sequence' => $head + 1, 'event_hash' => 'conflict', 'key_id' => 'test', 'mac' => 'conflict']);
    expect(fn () => app(InstallationSettings::class)->save($admin, [...app(InstallationSettings::class)->values(), 'display_name' => 'Unsafe']))->toThrow(QueryException::class);
    expect(DB::table('installation_settings')->value('display_name'))->toBe('Vaultigen');
    expect(DB::table('audit_chain_heads')->value('sequence'))->toBe($head);
});

test('a missing audit chain head fails without recording an event', function () {
    DB::table('audit_chain_heads')->delete();
    expect(fn () => app(AuditWriter::class)->record('test'))->toThrow(RuntimeException::class, 'Audit storage is unavailable.');
    expect(DB::table('audit_events')->count())->toBe(0);
});

test('a changed installation identity fails closed after bootstrap binding', function () {
    DB::table('installation_settings')->where('id', 1)->update(['installation_id' => '550e8400-e29b-41d4-a716-446655440099']);
    expect(fn () => app(AuditWriter::class)->record('test'))->toThrow(RuntimeException::class, 'Installation security configuration');
    expect(DB::table('audit_events')->count())->toBe(0);
});
