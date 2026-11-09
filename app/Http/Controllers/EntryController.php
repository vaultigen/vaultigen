<?php

namespace App\Http\Controllers;

use App\Filament\Pages\EntryDetail;
use App\Filament\Pages\EntryForm;
use App\Models\User;
use App\Services\EntryPayload;
use App\Services\EntryVault;
use App\Services\VaultDirectory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class EntryController
{
    public function store(Request $request, string $vault): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        try {
            $entryId = app(EntryVault::class)->create($user, $vault, $this->input($request, $user, $vault));
        } catch (ValidationException $exception) {
            return $this->backToForm($request, $vault, null, $exception);
        }

        return redirect(EntryDetail::getUrl(['entry' => $entryId]));
    }

    public function update(Request $request, string $vault, string $entry): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        try {
            app(EntryVault::class)->update($user, $entry, $this->input($request, $user, $vault), (int) $request->input('lock_version', 0));
        } catch (ValidationException $exception) {
            return $this->backToForm($request, $vault, $entry, $exception);
        }

        return redirect(EntryDetail::getUrl(['entry' => $entry]));
    }

    public function reveal(Request $request, string $entry, string $field): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $value = app(EntryVault::class)->reveal($user, $entry, $field);

        return response()->json(['field' => $field, 'value' => $value], 200, [
            'Cache-Control' => 'no-store, private',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function revealCustom(Request $request, string $entry, string $index): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        if (! ctype_digit($index)) {
            abort(404);
        }

        $value = app(EntryVault::class)->revealCustom($user, $entry, (int) $index);

        return response()->json(['field' => 'custom_field', 'index' => (int) $index, 'value' => $value], 200, [
            'Cache-Control' => 'no-store, private',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function totp(Request $request, string $entry): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $code = app(EntryVault::class)->totpCode($user, $entry);

        return response()->json($code, 200, [
            'Cache-Control' => 'no-store, private',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /**
     * The request payload with tag input resolved to tag ids. Only tags are
     * translated; secret fields are passed through untouched.
     *
     * @return array<string, mixed>
     */
    private function input(Request $request, User $user, string $vault): array
    {
        $input = $request->all();
        if ($request->has('tags')) {
            $input['tags'] = app(VaultDirectory::class)->resolveTagInput($user, $vault, (array) $request->input('tags', []));
        }

        return $input;
    }

    private function backToForm(Request $request, string $vault, ?string $entry, ValidationException $exception): RedirectResponse
    {
        $parameters = ['vault' => $vault];
        if ($entry !== null) {
            $parameters['entry'] = $entry;
        }

        // Only non-secret values are flashed back; typed secrets are never placed
        // into session state.
        return redirect(EntryForm::getUrl($parameters))
            ->withErrors($exception->errors())
            ->withInput($request->only(EntryPayload::SAFE_INPUT_KEYS));
    }
}
