<?php

namespace App\Services\Operations;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The single write-quiescence window used by backups.
 *
 * A backup needs the database snapshot and the referenced encrypted objects to
 * describe the same moment. Before it snapshots anything it opens this window,
 * which the HTTP write middleware and the scheduled commands consult. The window
 * is a singleton row, so the conditional update that opens it serializes two
 * concurrent backups on every supported database.
 */
final class MaintenanceWindow
{
    public function active(): bool
    {
        return (bool) DB::table('maintenance_windows')->where('id', 1)->value('active');
    }

    /**
     * The open window, or null when the installation is writable.
     *
     * @return array{operation: string|null, operation_id: string|null, reason: string|null, started_at: Carbon|null}|null
     */
    public function current(): ?array
    {
        $row = DB::table('maintenance_windows')->where('id', 1)->first();
        if ($row === null || ! (bool) $row->active) {
            return null;
        }

        return [
            'operation' => is_string($row->operation) ? $row->operation : null,
            'operation_id' => is_string($row->operation_id) ? $row->operation_id : null,
            'reason' => is_string($row->reason) ? $row->reason : null,
            'started_at' => $row->started_at === null ? null : Carbon::parse((string) $row->started_at),
        ];
    }

    /**
     * Open the window. Fails closed if another window is already open.
     *
     * @throws MaintenanceActiveException
     */
    public function begin(string $operation, ?string $operationId, string $reason): void
    {
        $updated = DB::table('maintenance_windows')
            ->where('id', 1)
            ->where('active', false)
            ->update([
                'active' => true,
                'operation' => $operation,
                'operation_id' => $operationId,
                'reason' => $reason,
                'started_at' => now(),
                'ended_at' => null,
            ]);

        if ($updated !== 1) {
            throw new MaintenanceActiveException($this->current()['operation'] ?? null);
        }
    }

    /**
     * Close the window. Idempotent, so a `finally` can always call it.
     */
    public function end(): void
    {
        DB::table('maintenance_windows')->where('id', 1)->update([
            'active' => false,
            'operation' => null,
            'operation_id' => null,
            'reason' => null,
            'ended_at' => now(),
        ]);
    }

    /**
     * Close the window only when the given operation still owns it.
     */
    public function endOwned(string $operation, string $operationId): void
    {
        DB::table('maintenance_windows')->where('id', 1)->where('operation', $operation)
            ->where('operation_id', $operationId)->update([
                'active' => false, 'operation' => null, 'operation_id' => null,
                'reason' => null, 'ended_at' => now(),
            ]);
    }

    /**
     * @throws MaintenanceActiveException
     */
    public function assertWritable(): void
    {
        if ($this->active()) {
            throw new MaintenanceActiveException($this->current()['operation'] ?? null);
        }
    }
}
