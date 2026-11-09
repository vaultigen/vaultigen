<?php

namespace App\Http\Controllers\Imports;

use App\Filament\Pages\ImportReport;
use App\Filament\Pages\Imports;
use App\Filament\Pages\ImportWizard;
use App\Jobs\ExecuteImportRun;
use App\Models\ImportRun;
use App\Models\User;
use App\Services\Imports\ImportCredentialStore;
use App\Services\Imports\ImportRegistry;
use App\Services\Imports\ImportRunLifecycle;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * The two plain-POST steps of the import wizard plus cancellation.
 *
 * Each driver declares the credential fields it needs. Staging credentials
 * (such as a sysPass export password) exist only for the upload request.
 * Execution credentials (such as a sysPass master password) are read from the
 * execute request and sealed under the installation KEK for the queued job to
 * unwrap; a driver that needs none still gets an empty envelope. Neither is
 * ever written to the session, cache, queue payload, log, or a Livewire
 * snapshot.
 */
class ImportController
{
    public function upload(Request $request): RedirectResponse
    {
        Gate::authorize('manage-installation');
        /** @var User $user */
        $user = $request->user();

        $data = $request->validate([
            'source' => ['required', 'string'],
            'file' => ['required', 'file'],
        ]);

        $registry = app(ImportRegistry::class);
        abort_unless($registry->has($data['source']), 404);
        $driver = $registry->resolve($data['source']);

        try {
            $run = $driver->stage(
                $request->file('file'),
                $this->credentials($request, $driver->stagingCredentialFields()),
                $user,
            );
        } catch (ValidationException $exception) {
            return redirect(Imports::getUrl())
                ->withErrors($exception->errors())
                ->withInput($request->only('source'));
        }

        return redirect(ImportWizard::getUrl(['run' => $run->id]));
    }

    public function execute(Request $request, string $run): RedirectResponse
    {
        Gate::authorize('manage-installation');

        $record = ImportRun::query()->findOrFail($run);

        if ($record->isTerminal() || $record->isRunning()) {
            return redirect(ImportReport::getUrl(['run' => $record->id]));
        }

        $driver = app(ImportRegistry::class)->resolve((string) $record->source_format);
        app(ImportCredentialStore::class)->seal(
            $record,
            $this->credentials($request, $driver->executionCredentialFields()),
        );

        $record->forceFill([
            'status' => ImportRun::STATUS_QUEUED,
            'queued_at' => now(),
            'progress_total' => $this->progressTotal($record),
            'error_code' => null,
        ])->save();

        ExecuteImportRun::dispatch((string) $record->id);

        return redirect(ImportReport::getUrl(['run' => $record->id]));
    }

    public function cancel(Request $request, string $run): RedirectResponse
    {
        Gate::authorize('manage-installation');
        /** @var User $user */
        $user = $request->user();

        $record = ImportRun::query()->findOrFail($run);

        try {
            app(ImportRunLifecycle::class)->cancel($record, $user);
        } catch (ValidationException $exception) {
            return redirect(Imports::getUrl())->withErrors($exception->errors());
        }

        return redirect(Imports::getUrl());
    }

    /**
     * Exactly the declared credential fields, as strings.
     *
     * @param  list<string>  $fields
     * @return array<string, string>
     */
    private function credentials(Request $request, array $fields): array
    {
        $credentials = [];
        foreach ($fields as $field) {
            $value = $request->input($field);
            $credentials[$field] = is_string($value) ? $value : '';
        }

        return $credentials;
    }

    /**
     * How many entries the plan will attempt to write, for the progress bar.
     */
    private function progressTotal(ImportRun $run): int
    {
        $assignments = is_array($run->plan['assignments'] ?? null) ? $run->plan['assignments'] : [];

        $total = 0;
        foreach ($assignments as $assignment) {
            if (is_array($assignment) && ($assignment['include'] ?? true) !== false) {
                $total++;
            }
        }

        return $total;
    }
}
