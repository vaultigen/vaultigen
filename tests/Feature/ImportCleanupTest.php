<?php

use App\Models\ImportRun;
use App\Models\User;
use App\Services\Imports\ImportRetention;
use App\Services\Imports\ImportSourceStore;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

beforeEach(function (): void {
    vaultigen_fake_attachment_disk();
});

/**
 * @param  array<string, mixed>|null  $plan
 */
function import_cleanup_staged_run(User $actor, ?array $plan = null): ImportRun
{
    $run = ImportRun::factory()->create([
        'requested_by_user_id' => $actor->id,
        'status' => ImportRun::STATUS_STAGING,
        'plan' => $plan,
    ]);
    $sealed = app(ImportSourceStore::class)->seal($run->id, json_encode(vaultigen_syspass_ir(), JSON_THROW_ON_ERROR));
    $run->forceFill([...$sealed, 'status' => ImportRun::STATUS_STAGED, 'plan' => $plan])->save();

    return $run->refresh();
}

function import_cleanup_age(ImportRun $run, int $days): void
{
    DB::table('import_runs')->where('id', $run->id)->update(['updated_at' => now()->subDays($days)]);
}

test('import cleanup cancels old uploads and deletes old runs', function () {
    $admin = User::factory()->superAdmin()->withTwoFactor()->create();

    $oldStaged = import_cleanup_staged_run($admin);
    import_cleanup_age($oldStaged, 60);
    $path = (string) $oldStaged->random_storage_path;

    $freshStaged = import_cleanup_staged_run($admin);

    $oldCompleted = ImportRun::factory()->create([
        'requested_by_user_id' => $admin->id,
        'status' => ImportRun::STATUS_COMPLETED,
    ]);
    import_cleanup_age($oldCompleted, 60);

    $freshCancelled = ImportRun::factory()->create([
        'requested_by_user_id' => $admin->id,
        'status' => ImportRun::STATUS_CANCELLED,
    ]);

    $result = app(ImportRetention::class)->purgeDue();

    expect($result['staged'])->toBe(1)
        ->and($result['deleted'])->toBe(1)
        ->and($oldStaged->refresh()->status)->toBe(ImportRun::STATUS_CANCELLED)
        ->and($oldStaged->random_storage_path)->toBeNull()
        ->and($freshStaged->refresh()->status)->toBe(ImportRun::STATUS_STAGED)
        ->and($freshStaged->random_storage_path)->not->toBeNull();

    Storage::disk('attachments')->assertMissing($path);

    $this->assertDatabaseMissing('import_runs', ['id' => $oldCompleted->id]);
    $this->assertDatabaseHas('import_runs', ['id' => $freshCancelled->id]);
});

test('import cleanup trims oversized failure lists', function () {
    $admin = User::factory()->superAdmin()->withTwoFactor()->create();
    $failures = array_fill(0, 600, ['source_id' => '1', 'title' => 'Example', 'error_code' => 'unknown']);
    $run = ImportRun::factory()->create([
        'requested_by_user_id' => $admin->id,
        'status' => ImportRun::STATUS_COMPLETED,
        'failures' => $failures,
        'failure_count' => 600,
    ]);

    $result = app(ImportRetention::class)->purgeDue();

    expect($result['trimmed'])->toBe(1)
        ->and(count($run->refresh()->failures))->toBe(500)
        ->and($run->failure_count)->toBe(600);
});

test('the cleanup command reports its work and skips a concurrent run', function () {
    $admin = User::factory()->superAdmin()->withTwoFactor()->create();
    $staged = import_cleanup_staged_run($admin);
    import_cleanup_age($staged, 60);

    $this->artisan('vaultigen:cleanup-imports')
        ->expectsOutputToContain('cancelled 1 abandoned uploads')
        ->assertSuccessful();

    $lock = Cache::lock('vaultigen-cleanup-imports', 300);
    expect($lock->acquire())->toBeTrue();

    $this->artisan('vaultigen:cleanup-imports')
        ->expectsOutputToContain('Another import cleanup run is already in progress.')
        ->assertSuccessful();

    $lock->release();
});

test('deleting an old failed run removes the object it still references', function () {
    $admin = User::factory()->superAdmin()->withTwoFactor()->create();
    $run = import_cleanup_staged_run($admin);
    $path = (string) $run->random_storage_path;
    $run->forceFill(['status' => ImportRun::STATUS_FAILED, 'error_code' => 'queue_failed'])->save();
    import_cleanup_age($run, 60);

    $result = app(ImportRetention::class)->purgeDue();

    expect($result['deleted'])->toBe(1);
    Storage::disk('attachments')->assertMissing($path);
    $this->assertDatabaseMissing('import_runs', ['id' => $run->id]);
});
