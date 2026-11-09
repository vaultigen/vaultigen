<?php

namespace App\Services;

use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use JsonException;
use RuntimeException;

/**
 * Validation, shape, and the deliberate plaintext projection for entry payloads.
 *
 * Every type keeps all of its values inside the encrypted envelope. Only the
 * title, entry type, sanitized login URL host, ownership, expiry, and
 * folder/tag placement are stored in the clear. Custom-field values are always
 * encrypted; the `concealed` flag only changes how the UI displays them.
 */
final class EntryPayload
{
    public const SCHEMA_VERSION = 1;

    public const TYPE_LOGIN = 'login';

    public const TYPE_NOTE = 'note';

    public const TYPE_SSH_KEY = 'ssh_key';

    public const TYPE_TOTP = 'totp';

    /** @var list<string> */
    public const TYPES = [self::TYPE_LOGIN, self::TYPE_NOTE, self::TYPE_SSH_KEY, self::TYPE_TOTP];

    /**
     * The localized, human-readable label of every payload type.
     *
     * @return array<string, string>
     */
    public static function typeLabels(): array
    {
        return [
            self::TYPE_LOGIN => (string) __('entries.types.login'),
            self::TYPE_NOTE => (string) __('entries.types.note'),
            self::TYPE_SSH_KEY => (string) __('entries.types.ssh_key'),
            self::TYPE_TOTP => (string) __('entries.types.totp'),
        ];
    }

    public const MAX_TITLE = 200;

    public const MAX_USERNAME = 255;

    public const MAX_PASSWORD = 4096;

    public const MAX_URL = 2048;

    public const MAX_NOTES = 20000;

    public const MAX_NOTE_BODY = 20000;

    public const MAX_SSH_PUBLIC_KEY = 16384;

    public const MAX_SSH_PRIVATE_KEY = 32768;

    public const MAX_SSH_PASSPHRASE = 4096;

    public const MAX_TOTP_ISSUER = 200;

    public const MAX_TOTP_ACCOUNT = 255;

    public const MAX_TOTP_SECRET = 512;

    public const MAX_CUSTOM_FIELDS = 50;

    public const MAX_CUSTOM_LABEL = 100;

    public const MAX_CUSTOM_VALUE = 4096;

    public const MAX_PLAINTEXT_BYTES = 65536;

    /** @var list<string> */
    public const TOTP_ALGORITHMS = ['SHA1', 'SHA256', 'SHA512'];

    /** @var list<int> */
    public const TOTP_DIGITS = [6, 8];

    /** @var list<int> */
    public const TOTP_PERIODS = [15, 30, 60];

    public const DEFAULT_TOTP_ALGORITHM = 'SHA1';

    public const DEFAULT_TOTP_DIGITS = 6;

    public const DEFAULT_TOTP_PERIOD = 30;

    /** @var list<string> */
    public const TOTP_CONFIG_FIELDS = ['algorithm', 'digits', 'period'];

    /**
     * The typed fields of each payload, in display order. These definitions are
     * the single source of truth for form labels, validation limits, reveal
     * field lists, and revision round trips.
     *
     * @var array<string, array<string, array{max: int, required?: bool, multiline?: bool}>>
     */
    public const FIELDS = [
        self::TYPE_LOGIN => [
            'username' => ['max' => self::MAX_USERNAME],
            'password' => ['max' => self::MAX_PASSWORD, 'required' => true],
            'url' => ['max' => self::MAX_URL],
            'notes' => ['max' => self::MAX_NOTES, 'multiline' => true],
            'totp_secret' => ['max' => self::MAX_TOTP_SECRET],
        ],
        self::TYPE_NOTE => [
            'body' => ['max' => self::MAX_NOTE_BODY, 'required' => true, 'multiline' => true],
        ],
        self::TYPE_SSH_KEY => [
            'public_key' => ['max' => self::MAX_SSH_PUBLIC_KEY, 'multiline' => true],
            'private_key' => ['max' => self::MAX_SSH_PRIVATE_KEY, 'multiline' => true],
            'passphrase' => ['max' => self::MAX_SSH_PASSPHRASE],
            'notes' => ['max' => self::MAX_NOTES, 'multiline' => true],
        ],
        self::TYPE_TOTP => [
            'issuer' => ['max' => self::MAX_TOTP_ISSUER],
            'account' => ['max' => self::MAX_TOTP_ACCOUNT],
            'secret' => ['max' => self::MAX_TOTP_SECRET, 'required' => true],
            'algorithm' => ['max' => 10],
            'digits' => ['max' => 2],
            'period' => ['max' => 3],
            'notes' => ['max' => self::MAX_NOTES, 'multiline' => true],
        ],
    ];

    /**
     * Non-secret inputs that are safe to flash back to the form. TOTP
     * parameters are configuration, not secrets, so they are included to let a
     * failed submission repopulate.
     *
     * @var list<string>
     */
    public const SAFE_INPUT_KEYS = ['type', 'title', 'owner_user_id', 'expires_at', 'generated', 'folder_id', 'tags', 'new_folder_name', 'algorithm', 'digits', 'period'];

    public function __construct(private readonly TotpGenerator $totp) {}

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function validate(array $input, string $type = self::TYPE_LOGIN, bool $isCreate = true, bool $allowEmptySecrets = false): array
    {
        if (! in_array($type, self::TYPES, true)) {
            throw ValidationException::withMessages(['type' => __('entries.validation.choose_supported_type')]);
        }

        $rules = [
            'title' => ['required', 'string', 'max:'.self::MAX_TITLE],
            'owner_user_id' => ['required', 'uuid', 'exists:users,id'],
            'expires_at' => ['nullable', 'date'],
            'generated' => ['nullable', 'boolean'],
            'folder_id' => ['nullable', 'uuid'],
            'tags' => ['nullable', 'array'],
            'tags.*' => ['uuid'],
            'new_folder_name' => ['nullable', 'string', 'max:100'],
        ];

        foreach (self::FIELDS[$type] as $field => $definition) {
            $required = $isCreate && ($definition['required'] ?? false) && ! $allowEmptySecrets;
            $rules[$field] = [$required ? 'required' : 'nullable', 'string', 'max:'.$definition['max']];
        }

        if ($type === self::TYPE_TOTP) {
            $rules['algorithm'][] = Rule::in(self::TOTP_ALGORITHMS);
            $rules['digits'][] = Rule::in(array_map('strval', self::TOTP_DIGITS));
            $rules['period'][] = Rule::in(array_map('strval', self::TOTP_PERIODS));
        }

        $validated = Validator::make($input, $rules)->validate();

        $result = [
            'type' => $type,
            'title' => trim((string) $validated['title']),
            'owner_user_id' => (string) $validated['owner_user_id'],
            'expires_at' => isset($validated['expires_at']) ? (string) $validated['expires_at'] : null,
            'generated' => (bool) ($validated['generated'] ?? false),
            'folder_id' => $this->optionalString($validated, 'folder_id'),
            'tag_ids' => $this->normalizeTagIds($validated['tags'] ?? null),
            'new_folder_name' => $this->optionalString($validated, 'new_folder_name'),
            'custom_fields' => $this->normalizeCustomFields($input['custom_fields'] ?? null),
        ];

        foreach (array_keys(self::FIELDS[$type]) as $field) {
            $result[$field] = $this->optionalString($validated, $field);
        }

        $publicKey = $result['public_key'] ?? null;
        $privateKey = $result['private_key'] ?? null;
        if ($type === self::TYPE_SSH_KEY && $isCreate && ! $allowEmptySecrets
            && $publicKey === null && $privateKey === null) {
            throw ValidationException::withMessages(['private_key' => __('entries.validation.provide_key')]);
        }

        $loginSeed = $result['totp_secret'] ?? null;
        if ($type === self::TYPE_LOGIN && is_string($loginSeed)) {
            $this->assertTotpSecret($loginSeed, 'totp_secret');
        }

        $totpSecret = $result['secret'] ?? null;
        if ($type === self::TYPE_TOTP && is_string($totpSecret)) {
            $this->assertTotpSecret($totpSecret, 'secret');
        }

        return $result;
    }

    /**
     * Build a new payload, or overlay provided fields onto an existing one.
     * A blank field means "keep the current value"; it never clears it.
     *
     * @param  array<string, mixed>|null  $existing
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    public function compose(?array $existing, array $validated): array
    {
        $type = is_string($validated['type'] ?? null) ? (string) $validated['type'] : self::TYPE_LOGIN;
        if (! in_array($type, self::TYPES, true)) {
            throw new RuntimeException('The entry type is not supported.');
        }

        $fields = is_array($existing['fields'] ?? null) ? $existing['fields'] : [];
        foreach (array_keys(self::FIELDS[$type]) as $field) {
            if (($validated[$field] ?? null) !== null) {
                $fields[$field] = $validated[$field];
            }
        }

        if ($type === self::TYPE_TOTP) {
            $fields['algorithm'] ??= self::DEFAULT_TOTP_ALGORITHM;
            $fields['digits'] ??= self::DEFAULT_TOTP_DIGITS;
            $fields['period'] ??= self::DEFAULT_TOTP_PERIOD;
        }

        $document = [
            'schema_version' => self::SCHEMA_VERSION,
            'type' => $type,
            'fields' => $this->normalizeFields($type, $fields),
            'custom_fields' => $this->composeCustomFields($existing, $validated),
        ];

        if ($type === self::TYPE_LOGIN) {
            $document['metadata'] = [
                'generated_password' => (bool) ($validated['generated'] ?? false)
                    || (bool) ($existing['metadata']['generated_password'] ?? false),
            ];
        }

        return $document;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  list<string>  $tagIds
     * @return array{title: string, normalized_title: string, url_host: string|null, type: string, owner_user_id: string, expires_at: string|null, folder_id: string|null, tag_ids: list<string>}
     */
    public function projection(array $payload, string $title, string $ownerUserId, ?string $expiresAt, ?string $folderId = null, array $tagIds = []): array
    {
        $title = trim($title);
        $type = is_string($payload['type'] ?? null) ? (string) $payload['type'] : self::TYPE_LOGIN;

        return [
            'title' => $title,
            'normalized_title' => Str::lower($title),
            'url_host' => $type === self::TYPE_LOGIN ? $this->host($this->optionalString($payload['fields'] ?? [], 'url')) : null,
            'type' => $type,
            'owner_user_id' => $ownerUserId,
            'expires_at' => $expiresAt,
            'folder_id' => $folderId,
            'tag_ids' => $tagIds,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function encode(array $payload): string
    {
        $json = $this->encodeDocument($payload);

        if (strlen($json) > self::MAX_PLAINTEXT_BYTES) {
            throw ValidationException::withMessages(['entry' => __('entries.validation.entry_too_large')]);
        }

        return $json;
    }

    /**
     * @param  array<string, mixed>  $document
     */
    public function encodeDocument(array $document): string
    {
        try {
            return json_encode($document, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        } catch (JsonException) {
            throw new RuntimeException('The entry document could not be encoded.');
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function decode(string $json): array
    {
        $decoded = $this->decodeDocument($json);
        $this->assertSupported($decoded);

        return $decoded;
    }

    /**
     * @return array<string, mixed>
     */
    public function decodeDocument(string $json): array
    {
        try {
            $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new RuntimeException('The entry document could not be decoded.');
        }

        if (! is_array($decoded)) {
            throw new RuntimeException('The entry document is malformed.');
        }

        return $decoded;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function assertSupported(array $payload): void
    {
        if (($payload['schema_version'] ?? null) !== self::SCHEMA_VERSION
            || ! in_array($payload['type'] ?? null, self::TYPES, true)) {
            throw new RuntimeException('The entry payload version or type is not supported.');
        }
    }

    /**
     * The revealable fields of a type, in display order. TOTP configuration
     * (algorithm, digits, period) is shown as status, not delivered as a secret.
     *
     * @return list<string>
     */
    public function revealableFields(string $type): array
    {
        return array_values(array_diff(array_keys(self::FIELDS[$type] ?? []), self::TOTP_CONFIG_FIELDS));
    }

    /**
     * @return array<string, array{label: string, max: int, required?: bool, multiline?: bool}>
     */
    public function fields(string $type): array
    {
        $fields = [];

        foreach (self::FIELDS[$type] ?? [] as $field => $definition) {
            $fields[$field] = [
                ...$definition,
                'label' => (string) __('entries.fields.'.$type.'.'.$field),
            ];
        }

        return $fields;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function isRevealable(array $payload, string $field): bool
    {
        $type = is_string($payload['type'] ?? null) ? (string) $payload['type'] : '';

        return in_array($field, $this->revealableFields($type), true);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function field(array $payload, string $field): string
    {
        if (! $this->isRevealable($payload, $field)) {
            throw new RuntimeException('That entry field cannot be delivered.');
        }

        return $this->optionalString($payload['fields'] ?? [], $field) ?? '';
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function hasCustomField(array $payload, int $index): bool
    {
        $fields = $payload['custom_fields'] ?? null;

        return is_array($fields) && array_key_exists($index, $fields) && is_array($fields[$index]);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function customField(array $payload, int $index): string
    {
        if (! $this->hasCustomField($payload, $index)) {
            throw new RuntimeException('That custom field does not exist.');
        }

        $value = $payload['custom_fields'][$index]['value'] ?? null;

        return is_string($value) ? $value : '';
    }

    /**
     * The authenticator seed of a login's optional seed or a TOTP entry.
     *
     * @param  array<string, mixed>  $payload
     */
    public function totpSeed(array $payload): ?string
    {
        $type = $payload['type'] ?? null;
        $key = match ($type) {
            self::TYPE_TOTP => 'secret',
            self::TYPE_LOGIN => 'totp_secret',
            default => null,
        };

        return $key === null ? null : $this->optionalString($payload['fields'] ?? [], $key);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{algorithm: string, digits: int, period: int}
     */
    public function totpParameters(array $payload): array
    {
        if (($payload['type'] ?? null) !== self::TYPE_TOTP) {
            return [
                'algorithm' => self::DEFAULT_TOTP_ALGORITHM,
                'digits' => self::DEFAULT_TOTP_DIGITS,
                'period' => self::DEFAULT_TOTP_PERIOD,
            ];
        }

        $fields = is_array($payload['fields'] ?? null) ? $payload['fields'] : [];
        $algorithm = is_string($fields['algorithm'] ?? null) ? $fields['algorithm'] : null;
        $digits = is_numeric($fields['digits'] ?? null) ? (int) $fields['digits'] : null;
        $period = is_numeric($fields['period'] ?? null) ? (int) $fields['period'] : null;

        return [
            'algorithm' => in_array($algorithm, self::TOTP_ALGORITHMS, true) ? $algorithm : self::DEFAULT_TOTP_ALGORITHM,
            'digits' => in_array($digits, self::TOTP_DIGITS, true) ? $digits : self::DEFAULT_TOTP_DIGITS,
            'period' => in_array($period, self::TOTP_PERIODS, true) ? $period : self::DEFAULT_TOTP_PERIOD,
        ];
    }

    /**
     * The sanitized, plaintext URL host projection. Only the host is exposed;
     * the complete URL stays encrypted.
     */
    public function host(?string $url): ?string
    {
        if ($url === null || trim($url) === '') {
            return null;
        }

        $candidate = trim($url);
        if (preg_match('#^[a-z][a-z0-9+.\-]*://#i', $candidate) !== 1) {
            $candidate = 'https://'.$candidate;
        }

        $host = parse_url($candidate, PHP_URL_HOST);
        if (! is_string($host)) {
            return null;
        }

        $host = Str::lower(rtrim($host, '.'));

        return $host === '' ? null : $host;
    }

    private function assertTotpSecret(string $secret, string $field): void
    {
        if (! $this->totp->isValidSecret($secret)) {
            throw ValidationException::withMessages([$field => __('entries.validation.invalid_totp_secret')]);
        }
    }

    /**
     * @param  array<string, mixed>  $fields
     * @return array<string, mixed>
     */
    private function normalizeFields(string $type, array $fields): array
    {
        $normalized = [];
        foreach (array_keys(self::FIELDS[$type]) as $field) {
            $raw = $fields[$field] ?? null;
            if ($type === self::TYPE_TOTP && in_array($field, self::TOTP_CONFIG_FIELDS, true)) {
                $normalized[$field] = $field === 'algorithm'
                    ? (is_string($raw) && $raw !== '' ? $raw : null)
                    : (is_numeric($raw) ? (int) $raw : null);

                continue;
            }

            $normalized[$field] = is_string($raw) && $raw !== '' ? $raw : null;
        }

        return $normalized;
    }

    /**
     * @param  array<string, mixed>|null  $existing
     * @param  array<string, mixed>  $validated
     * @return list<array{label: string, value: string, concealed: bool}>
     */
    private function composeCustomFields(?array $existing, array $validated): array
    {
        $existingFields = is_array($existing['custom_fields'] ?? null) ? array_values($existing['custom_fields']) : [];
        $rows = is_array($validated['custom_fields'] ?? null) ? $validated['custom_fields'] : [];

        $composed = [];
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }

            $key = $row['key'] ?? null;
            $label = (string) ($row['label'] ?? '');
            $value = $row['value'] ?? null;

            if (is_int($key) && array_key_exists($key, $existingFields) && is_array($existingFields[$key])) {
                $base = $existingFields[$key];
                if ($value === null) {
                    $value = $base['value'] ?? null;
                }
                if ($label === '') {
                    $label = (string) ($base['label'] ?? '');
                }
            }

            $composed[] = [
                'label' => $label,
                'value' => $value === null ? '' : (string) $value,
                'concealed' => (bool) ($row['concealed'] ?? false),
            ];
        }

        return $composed;
    }

    /**
     * @return list<array{key: int|null, label: string, value: string|null, concealed: bool}>
     */
    private function normalizeCustomFields(mixed $raw): array
    {
        $rows = is_array($raw) ? $raw : [];
        if (count($rows) > self::MAX_CUSTOM_FIELDS) {
            throw ValidationException::withMessages(['custom_fields' => __('entries.validation.too_many_custom_fields', ['max' => self::MAX_CUSTOM_FIELDS])]);
        }

        $normalized = [];
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }

            $key = $row['key'] ?? null;
            $label = trim((string) ($row['label'] ?? ''));
            $value = array_key_exists('value', $row) ? (string) $row['value'] : '';

            if ($label === '' && trim($value) === '') {
                continue;
            }
            if (mb_strlen($label) > self::MAX_CUSTOM_LABEL) {
                throw ValidationException::withMessages(['custom_fields' => __('entries.validation.custom_label_too_long', ['max' => self::MAX_CUSTOM_LABEL])]);
            }
            if (mb_strlen($value) > self::MAX_CUSTOM_VALUE) {
                throw ValidationException::withMessages(['custom_fields' => __('entries.validation.custom_value_too_long', ['max' => self::MAX_CUSTOM_VALUE])]);
            }

            $normalized[] = [
                'key' => is_numeric($key) ? (int) $key : null,
                'label' => $label,
                'value' => $value === '' ? null : $value,
                'concealed' => filter_var($row['concealed'] ?? false, FILTER_VALIDATE_BOOLEAN),
            ];
        }

        return $normalized;
    }

    /**
     * @return list<string>
     */
    private function normalizeTagIds(mixed $rawTags): array
    {
        $tags = is_array($rawTags) ? $rawTags : [];
        $tagIds = [];
        foreach ($tags as $id) {
            if (is_string($id) && $id !== '') {
                $tagIds[] = $id;
            }
        }

        return $tagIds;
    }

    /**
     * @param  array<string, mixed>  $values
     */
    private function optionalString(array $values, string $key): ?string
    {
        $value = $values[$key] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }
}
