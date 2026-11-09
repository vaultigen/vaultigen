<?php

namespace App\Jobs;

use App\Models\KeyRotationRun;
use App\Services\Operations\KeyRotationService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Runs one installation-rewrap or vault-key rotation outside the request.
 *
 * The payload carries only the run id. The service is restartable, so a job that
 * dies can be resumed by dispatching it again with the same id.
 */
class RunKeyRotation implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    /** @var int */
    public $tries = 1;

    /** @var int */
    public $timeout;

    public function __construct(public readonly string $rotationRunId)
    {
        $this->timeout = max(300, (int) config('vaultigen.rotation_job_timeout', 3600));
    }

    public function uniqueId(): string
    {
        return $this->rotationRunId;
    }

    public function handle(KeyRotationService $service): void
    {
        $run = KeyRotationRun::query()->find($this->rotationRunId);
        if ($run === null || $run->status === KeyRotationRun::STATUS_COMPLETED) {
            return;
        }

        $service->run($run);
    }
}
