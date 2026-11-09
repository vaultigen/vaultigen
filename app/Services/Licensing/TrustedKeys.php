<?php

namespace App\Services\Licensing;

/**
 * The Ed25519 public keys whose signatures make a license valid.
 *
 * The production list is hard-coded on purpose: no environment, env variable,
 * config value or setting may add or change a key, because any such switch
 * would let anyone sign their own licenses without modifying the code. Tests
 * substitute their own instance through the container.
 */
final class TrustedKeys
{
    /** @var array<string, string> kid => base64 public key */
    private const PRODUCTION = [
        '2026-1' => 'QsTLwLQmLP36Y7CE0sC83BKQkkCgUy2eFCSiYXRxMgs=',
    ];

    /**
     * @param  array<string, string>  $keys  kid => base64 public key
     */
    public function __construct(private readonly array $keys = self::PRODUCTION) {}

    /**
     * The raw public key for a key id, or null when the key id is not trusted.
     *
     * @return non-empty-string|null
     */
    public function publicKey(string $kid): ?string
    {
        $key = base64_decode($this->keys[$kid] ?? '', true);

        return is_string($key) && $key !== '' && strlen($key) === SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES ? $key : null;
    }
}
