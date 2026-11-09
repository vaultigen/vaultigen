<?php

namespace App\Services\Imports;

use App\Models\ImportRun;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use JsonException;
use RuntimeException;

/**
 * The source-agnostic half of staging: reading the upload, fingerprinting it,
 * and sealing the parsed representation onto a new run.
 *
 * A driver only parses. The plaintext upload is never written to the database,
 * and only its SHA-256 fingerprint and a sanitized filename are kept.
 */
final class ImportStager
{
    public function __construct(private readonly ImportSourceStore $store) {}

    /**
     * Read a validated upload into memory, bounded by the safe XML budget.
     */
    public function read(UploadedFile $file): string
    {
        if (! $file->isValid()) {
            throw ValidationException::withMessages(['file' => __('imports.errors.upload_failed')]);
        }
        if ((int) $file->getSize() > SafeXml::MAX_BYTES) {
            throw ValidationException::withMessages(['file' => __('imports.errors.too_large')]);
        }

        $contents = $file->get();
        if (! is_string($contents) || $contents === '') {
            throw ValidationException::withMessages(['file' => __('imports.errors.upload_failed')]);
        }

        return $contents;
    }

    /**
     * Create a run and seal its representation, leaving it `staged`.
     *
     * @param  array<string, mixed>  $ir
     *
     * @throws JsonException
     */
    public function stage(string $source, string $contents, UploadedFile $file, string $fallbackFilename, array $ir, User $actor): ImportRun
    {
        $run = ImportRun::query()->create([
            'requested_by_user_id' => $actor->id,
            'source_format' => $source,
            'source_fingerprint' => hash('sha256', $contents),
            'original_filename' => $this->sanitizeFilename($file->getClientOriginalName(), $fallbackFilename),
            'status' => ImportRun::STATUS_STAGING,
        ]);

        $sealed = $this->store->seal(
            (string) $run->id,
            json_encode($ir, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        );

        $run->forceFill([...$sealed, 'status' => ImportRun::STATUS_STAGED, 'updated_at' => now()])->save();

        return $run;
    }

    /**
     * Unseal and decode the staged representation exactly as it was stored.
     *
     * @return array<string, mixed>
     *
     * @throws RuntimeException
     */
    public function open(ImportRun $run): array
    {
        try {
            $decoded = json_decode($this->store->open($run), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new RuntimeException('The staged import is not valid JSON.');
        }

        if (! is_array($decoded)) {
            throw new RuntimeException('The staged import is malformed.');
        }

        return $decoded;
    }

    private function sanitizeFilename(string $name, string $fallback): string
    {
        $name = basename(str_replace('\\', '/', $name));
        $name = preg_replace('/[\x00-\x1F\x7F]/u', '', $name) ?? '';
        $name = trim($name);

        if ($name === '' || $name === '.' || $name === '..') {
            $name = $fallback;
        }

        return Str::limit($name, 255, '');
    }
}
