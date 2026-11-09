<?php

use App\Models\User;
use App\Services\AuditWriter;
use Illuminate\Support\Facades\DB;

it('attaches ambient context to recorded events and keeps the chain valid', function () {
    $user = User::factory()->withTwoFactor()->create();
    $writer = app(AuditWriter::class);

    $writer->withContext(['import_run_id' => 'run-1'], function () use ($writer, $user): void {
        $writer->record('import.started', $user->id);
    });

    $event = DB::table('audit_events')->orderByDesc('sequence')->firstOrFail();
    $context = json_decode((string) $event->sanitized_context, true, flags: JSON_THROW_ON_ERROR);

    expect($context)->toBe(['import_run_id' => 'run-1'])
        ->and($writer->verify())->toBeTrue();
});

it('merges nested ambient contexts', function () {
    $user = User::factory()->withTwoFactor()->create();
    $writer = app(AuditWriter::class);

    $writer->withContext(['outer' => 1], function () use ($writer, $user): void {
        $writer->withContext(['inner' => 2], function () use ($writer, $user): void {
            $writer->record('import.completed', $user->id);
        });
    });

    $event = DB::table('audit_events')->orderByDesc('sequence')->firstOrFail();
    $context = json_decode((string) $event->sanitized_context, true, flags: JSON_THROW_ON_ERROR);

    expect($context)->toBe(['outer' => 1, 'inner' => 2]);
});

it('restores the previous context when the callback throws', function () {
    $user = User::factory()->withTwoFactor()->create();
    $writer = app(AuditWriter::class);

    try {
        $writer->withContext(['import_run_id' => 'run-1'], function (): void {
            throw new RuntimeException('boom');
        });
    } catch (RuntimeException) {
        // Expected.
    }

    $writer->record('import.failed', $user->id);

    $event = DB::table('audit_events')->orderByDesc('sequence')->firstOrFail();

    expect($event->sanitized_context)->toBeNull();
});
