<?php

use App\Filament\Pages\Activity;
use App\Models\AuditEvent;
use App\Models\User;
use App\Services\AuditWriter;
use Livewire\Livewire;

test('activity table shows actor names and humanized actions instead of raw uids', function () {
    $admin = User::factory()->superAdmin()->withTwoFactor()->create();
    $actor = User::factory()->withTwoFactor()->create(['name' => 'Ada Lovelace', 'email' => 'ada@example.test']);
    $this->actingAs($admin);
    $audit = app(AuditWriter::class);
    $audit->record('auth.login', $actor->id);
    $audit->record('installation.settings_changed');

    Livewire::test(Activity::class)
        ->assertSee('Ada Lovelace')
        ->assertSee('ada@example.test')
        ->assertSee('System / anonymous')
        ->assertSee('auth.login')
        ->assertSee('Installation settings changed')
        ->assertSee('verified');
});

test('activity humanizes vault lifecycle actions', function () {
    $admin = User::factory()->superAdmin()->withTwoFactor()->create();
    $this->actingAs($admin);
    $audit = app(AuditWriter::class);
    foreach (['vault.archived', 'vault.creation_denied', 'vault.custodian_changed', 'vault.purge_cancelled', 'vault.purge_initiated', 'vault.purged', 'vault.reactivated', 'vault.recovered'] as $action) {
        $audit->record($action, $admin->id);
    }

    Livewire::test(Activity::class)
        ->assertSee('Vault archived')
        ->assertSee('Vault purge cancelled')
        ->assertSee('Vault permanently deleted')
        ->assertSee('Vault creation denied')
        ->assertSee('Vault custodian changed')
        ->assertSee('Vault purge initiated')
        ->assertSee('Vault reactivated')
        ->assertSee('Vault recovered');
});

test('activity humanizes account security actions', function () {
    $admin = User::factory()->superAdmin()->withTwoFactor()->create();
    $this->actingAs($admin);
    $audit = app(AuditWriter::class);
    foreach (['auth.password_changed', 'auth.email_changed', 'auth.factor_added', 'auth.factor_removed'] as $action) {
        $audit->record($action, $admin->id);
    }

    Livewire::test(Activity::class)
        ->assertSee('Password changed')
        ->assertSee('Email address changed')
        ->assertSee('Second factor added')
        ->assertSee('Second factor removed');
});

test('activity table falls back to a raw uid when the acting user no longer exists', function () {
    $admin = User::factory()->superAdmin()->withTwoFactor()->create();
    $this->actingAs($admin);
    $missing = '00000000-0000-0000-0000-000000000000';
    app(AuditWriter::class)->record('auth.login', $missing);

    Livewire::test(Activity::class)
        ->assertSee('Unknown actor')
        ->assertSee($missing);
});

test('auditor activity is redacted and shows no user contact details', function () {
    $admin = User::factory()->superAdmin()->withTwoFactor()->create();
    $auditor = User::factory()->auditor()->withTwoFactor()->create();
    $this->actingAs($auditor);
    app(AuditWriter::class)->record('auth.login', $admin->id);

    Livewire::test(Activity::class)
        ->assertSee($admin->name)
        ->assertDontSee($admin->email);
});

test('activity table search matches actor name, email, and raw uid', function () {
    $admin = User::factory()->superAdmin()->withTwoFactor()->create();
    $actor = User::factory()->withTwoFactor()->create(['name' => 'Ada Lovelace', 'email' => 'ada@example.test']);
    $this->actingAs($admin);
    $audit = app(AuditWriter::class);
    $audit->record('auth.login', $actor->id);
    $audit->record('installation.settings_changed');

    $page = Livewire::test(Activity::class);

    $page->searchTable('Ada');
    $actions = $page->instance()->getFilteredTableQuery()->pluck('action')->all();
    expect($actions)->toContain('auth.login')->not->toContain('installation.settings_changed');

    $page->searchTable('ada@example.test');
    $actions = $page->instance()->getFilteredTableQuery()->pluck('action')->all();
    expect($actions)->toContain('auth.login')->not->toContain('installation.settings_changed');

    $page->searchTable((string) $actor->id);
    $actions = $page->instance()->getFilteredTableQuery()->pluck('action')->all();
    expect($actions)->toContain('auth.login')->not->toContain('installation.settings_changed');

    $page->searchTable('nobody@example.test')->assertSee('No activity recorded yet.');
    expect($page->instance()->getFilteredTableQuery()->count())->toBe(0);
});

test('activity table filters events by outcome', function () {
    $admin = User::factory()->superAdmin()->withTwoFactor()->create();
    $this->actingAs($admin);
    $audit = app(AuditWriter::class);
    $audit->record('auth.login', $admin->id);
    $audit->record('auth.reauthentication', $admin->id, 'denied');

    $page = Livewire::test(Activity::class);

    $page->set('tableFilters', ['outcome' => ['value' => 'denied']]);
    expect($page->instance()->getFilteredTableQuery()->pluck('action')->all())->toBe(['auth.reauthentication']);

    $page->set('tableFilters', ['outcome' => ['value' => 'success']]);
    $actions = $page->instance()->getFilteredTableQuery()->pluck('action')->all();
    expect($actions)->toContain('auth.login')->not->toContain('auth.reauthentication');
});

test('activity table filters events by actor including system events', function () {
    $admin = User::factory()->superAdmin()->withTwoFactor()->create();
    $actor = User::factory()->withTwoFactor()->create();
    $this->actingAs($admin);
    $audit = app(AuditWriter::class);
    $audit->record('auth.login', $actor->id);
    $audit->record('installation.settings_changed');

    $page = Livewire::test(Activity::class)->set('tableFilters', ['actor' => ['value' => 'system']]);
    expect($page->instance()->getFilteredTableQuery()->pluck('action')->all())->toBe(['installation.settings_changed']);

    $page->set('tableFilters', ['actor' => ['value' => $actor->id]]);
    $actions = $page->instance()->getFilteredTableQuery()->pluck('action')->all();
    expect($actions)->toContain('auth.login')->not->toContain('installation.settings_changed');
});

test('activity table filters events by time period', function () {
    $this->freezeTime();
    $admin = User::factory()->superAdmin()->withTwoFactor()->create();
    $this->actingAs($admin);
    $total = AuditEvent::query()->count() + 2;
    app(AuditWriter::class)->record('auth.login', $admin->id);

    $page = Livewire::test(Activity::class)->set('tableFilters', ['occurred_at' => [
        'occurred_from' => now()->toDateString(),
        'occurred_until' => now()->toDateString(),
    ]]);
    expect($page->instance()->getFilteredTableQuery()->count())->toBe($total);
    $page->assertSee('From '.now()->format('M j, Y'))->assertSee('Until '.now()->format('M j, Y'));

    $page->set('tableFilters', ['occurred_at' => [
        'occurred_from' => null,
        'occurred_until' => now()->subDay()->toDateString(),
    ]]);
    expect($page->instance()->getFilteredTableQuery()->count())->toBe(0);
});

test('activity table defaults to newest first and can be sorted', function () {
    $admin = User::factory()->superAdmin()->withTwoFactor()->create();
    $this->actingAs($admin);
    $audit = app(AuditWriter::class);
    $audit->record('auth.login', $admin->id);
    $audit->record('auth.logout', $admin->id);

    $page = Livewire::test(Activity::class);
    expect($page->instance()->getFilteredSortedTableQuery()->pluck('action')->all())
        ->toBe(['audit.viewed', 'auth.logout', 'auth.login', 'user.created']);

    $page->sortTable('action', 'asc');
    expect($page->instance()->getFilteredSortedTableQuery()->pluck('action')->all())
        ->toBe(['audit.viewed', 'auth.login', 'auth.logout', 'user.created']);
});

test('the audit event details modal mounts for the recorded event', function () {
    $admin = User::factory()->superAdmin()->withTwoFactor()->create();
    $this->actingAs($admin);
    $sequence = app(AuditWriter::class)->record('auth.login', $admin->id);

    $page = Livewire::test(Activity::class)->mountTableAction('viewEvent', $sequence);
    $mounted = $page->instance()->getMountedActions();

    expect($mounted)->toHaveCount(1)
        ->and($mounted[0]->getName())->toBe('viewEvent')
        ->and($page->instance()->mountedActionShouldOpenModal($mounted[0]))->toBeTrue()
        ->and($mounted[0]->getModalHeading())->toBe("Audit event #{$sequence}: auth.login")
        ->and($mounted[0]->getModalDescription())->toBe('Read-only record of the canonical, hash-chained event.');
});
