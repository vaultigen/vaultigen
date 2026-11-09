<?php

namespace App\Http\Controllers;

use App\Filament\Pages\EntryDetail;
use App\Models\User;
use App\Services\EntryAttachments;
use Filament\Notifications\Notification;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class AttachmentController
{
    public function store(Request $request, string $vault, string $entry): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $file = $request->file('attachment');

        if (! $file instanceof UploadedFile) {
            return $this->back($entry, ['attachment' => (string) __('attachments.validation.missing')]);
        }

        try {
            app(EntryAttachments::class)->upload($user, $entry, $file);
        } catch (ValidationException $exception) {
            return $this->back($entry, $exception->errors());
        }

        Notification::make()->success()->title((string) __('attachments.notifications.uploaded'))->send();

        return redirect(EntryDetail::getUrl(['entry' => $entry]));
    }

    public function download(Request $request, string $attachment): StreamedResponse
    {
        /** @var User $user */
        $user = $request->user();
        $attachments = app(EntryAttachments::class);

        $record = $attachments->authorizeDownload($user, $attachment);
        $metadata = $attachments->metadata($record);

        // Fail closed: the delivery event and reveal marker are committed before
        // a single byte leaves the server.
        $attachments->recordDownloadStart($user, $record);

        return response()->streamDownload(function () use ($attachments, $record, $user): void {
            $output = fopen('php://output', 'wb') ?: throw new RuntimeException('The attachment output stream is unavailable.');

            try {
                $attachments->streamTo($record, $output);
                $attachments->recordDownloadCompleted($user, $record);
            } catch (Throwable $exception) {
                $attachments->recordDownloadFailed($user, $record);

                throw $exception;
            } finally {
                fclose($output);
            }
        }, $this->headerFilename($metadata['filename']), [
            'Content-Type' => 'application/octet-stream',
            'Cache-Control' => 'no-store, private',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function destroy(Request $request, string $vault, string $entry, string $attachment): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        try {
            app(EntryAttachments::class)->delete($user, $attachment);
        } catch (ValidationException $exception) {
            return $this->back($entry, $exception->errors());
        }

        Notification::make()->success()->title((string) __('attachments.notifications.deleted'))->send();

        return redirect(EntryDetail::getUrl(['entry' => $entry]));
    }

    /**
     * @param  array<string, string|array<int, string>>  $errors
     */
    private function back(string $entry, array $errors): RedirectResponse
    {
        return redirect(EntryDetail::getUrl(['entry' => $entry]))->withErrors($errors);
    }

    private function headerFilename(string $filename): string
    {
        $filename = str_replace(['"', "\r", "\n", '\\', '/'], '', $filename);

        return $filename === '' ? 'attachment.bin' : $filename;
    }
}
