<?php

namespace App\Services\Imports\Keepass;

/**
 * Decodes KeePass attachment data with strict base64 and bounded gunzip.
 *
 * Compressed binaries are inflated in small input chunks, and decompression
 * stops as soon as the output passes the limit, so a gzip bomb is never held
 * in memory in full.
 */
final class KeepassBinaryDecoder
{
    public const INVALID_BASE64 = 'invalid_base64';

    public const DECOMPRESSION_FAILED = 'decompression_failed';

    public const TOO_LARGE = 'too_large';

    public const MISSING_REFERENCE = 'missing_reference';

    /**
     * Bytes of compressed input per inflate step. Deflate expands at most
     * about 1032:1, which bounds one step's output to a few MB.
     */
    private const INPUT_CHUNK = 4096;

    /**
     * @return array{bytes: string}|array{error: string}
     */
    public function decode(#[\SensitiveParameter] string $base64, bool $compressed, int $maxBytes): array
    {
        $raw = base64_decode($base64, true);
        if (! is_string($raw)) {
            return ['error' => self::INVALID_BASE64];
        }

        if (! $compressed) {
            return strlen($raw) > $maxBytes ? ['error' => self::TOO_LARGE] : ['bytes' => $raw];
        }

        return $this->gunzip($raw, $maxBytes);
    }

    /**
     * @return array{bytes: string}|array{error: string}
     */
    private function gunzip(#[\SensitiveParameter] string $data, int $maxBytes): array
    {
        $length = strlen($data);
        $context = $length > 0 ? inflate_init(ZLIB_ENCODING_GZIP) : false;
        if ($context === false) {
            return ['error' => self::DECOMPRESSION_FAILED];
        }

        $output = '';
        $offset = 0;
        while ($offset < $length) {
            $chunk = substr($data, $offset, self::INPUT_CHUNK);
            $offset += strlen($chunk);

            $inflated = @inflate_add($context, $chunk, $offset >= $length ? ZLIB_FINISH : ZLIB_SYNC_FLUSH);
            if ($inflated === false) {
                return ['error' => self::DECOMPRESSION_FAILED];
            }

            $output .= $inflated;
            if (strlen($output) > $maxBytes) {
                return ['error' => self::TOO_LARGE];
            }

            if (inflate_get_status($context) === ZLIB_STREAM_END) {
                return ['bytes' => $output];
            }
        }

        return ['error' => self::DECOMPRESSION_FAILED];
    }
}
