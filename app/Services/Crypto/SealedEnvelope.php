<?php

namespace App\Services\Crypto;

/**
 * A base64url envelope plus its encoded nonce for indexed storage columns.
 */
final class SealedEnvelope
{
    public function __construct(
        public readonly string $envelope,
        public readonly string $nonce,
    ) {}
}
