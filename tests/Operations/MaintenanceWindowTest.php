<?php

use App\Services\Operations\MaintenanceActiveException;
use App\Services\Operations\MaintenanceWindow;
use Illuminate\Support\Str;

it('opens a single window and refuses a second', function (): void {
    $window = app(MaintenanceWindow::class);

    expect($window->active())->toBeFalse()
        ->and($window->current())->toBeNull();

    $operationId = (string) Str::uuid();
    $window->begin('backup', $operationId, 'Database backup');

    expect($window->active())->toBeTrue()
        ->and($window->current()['operation'])->toBe('backup')
        ->and($window->current()['operation_id'])->toBe($operationId)
        ->and($window->current()['reason'])->toBe('Database backup')
        ->and($window->current()['started_at'])->not->toBeNull();

    expect(fn () => $window->begin('rotation', null, 'again'))->toThrow(MaintenanceActiveException::class);
    expect(fn () => $window->assertWritable())->toThrow(MaintenanceActiveException::class);

    $window->end();
    $window->assertWritable();

    expect($window->active())->toBeFalse()
        ->and($window->current())->toBeNull();
});
