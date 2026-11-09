<?php

namespace App\Services\Imports\Syspass;

use App\Services\Imports\SafeXml;
use DOMDocument;
use DOMElement;
use RuntimeException;

/**
 * Decrypts a sysPass encrypted XML export.
 *
 * sysPass encrypts each exported node (Categories, Clients, Tags, Accounts)
 * with defuse/php-encryption v2. Every `<Data>` element carries its own random
 * key wrapped by a password-protected key derived from the export password.
 * Account passwords carry a second layer: each `<Account>` holds a `<key>`
 * protected by the installation master password and a `<pass>` encrypted with
 * that account key.
 *
 * This is a dependency-free implementation of that format. The encrypt
 * direction lives in test support only.
 */
final class ExportDecryptor
{
    private const DEFUSE_VERSION = "\xDE\xF5\x02\x00";

    private const KEY_VERSION = "\xDE\xF0\x00\x00";

    private const PASSWORD_KEY_VERSION = "\xDE\xF1\x00\x00";

    private const CIPHER = 'aes-256-ctr';

    private const HASH_ALGO = 'sha256';

    private const SALT_SIZE = 32;

    private const BLOCK_SIZE = 16;

    private const MAC_SIZE = 32;

    private const KEY_SIZE = 32;

    private const PBKDF2_ITERATIONS = 100000;

    private const ENCRYPTION_INFO = 'DefusePHP|V2|KeyForEncryption';

    private const AUTHENTICATION_INFO = 'DefusePHP|V2|KeyForAuthentication';

    /**
     * Decrypt every `<Data>` fragment and rebuild one plaintext XML document
     * (Meta plus the decrypted fragments). Account passwords remain encrypted.
     */
    public function decryptExport(string $xml, #[\SensitiveParameter] string $exportPassword): string
    {
        $document = SafeXml::load($xml);
        $encrypted = $document->getElementsByTagName('Encrypted')->item(0);

        if (! $encrypted instanceof DOMElement) {
            throw new RuntimeException('The export is not an encrypted sysPass export.');
        }

        $this->assertPassword($encrypted->getAttribute('hash'), $exportPassword);

        $dataNodes = $encrypted->getElementsByTagName('Data');
        if ($dataNodes->length === 0) {
            throw new RuntimeException('The export contains no data nodes.');
        }

        $fragments = [];
        foreach ($dataNodes as $node) {
            try {
                $rawKey = $this->unlockProtectedKey($node->getAttribute('key'), $exportPassword);
                $fragments[] = $this->decryptData(trim($node->textContent), $rawKey);
            } catch (RuntimeException) {
                throw new RuntimeException('The export could not be decrypted. Check the export password.');
            }
        }

        $result = new DOMDocument('1.0', 'UTF-8');
        $root = $result->appendChild($result->createElement('Root'));

        foreach ($document->getElementsByTagName('Meta') as $meta) {
            $root->appendChild($result->importNode($meta, true));

            break;
        }

        foreach ($fragments as $fragment) {
            $decoded = SafeXml::load($fragment);
            if ($decoded->documentElement instanceof DOMElement) {
                $root->appendChild($result->importNode($decoded->documentElement, true));
            }
        }

        return $result->saveXML() ?: throw new RuntimeException('The export could not be read.');
    }

    /**
     * Whether an account key is protected and therefore needs the master password.
     */
    public function isEncryptedAccountKey(string $securedKey): bool
    {
        return str_starts_with(trim($securedKey), 'def10000');
    }

    /**
     * Whether an account password value is encrypted rather than plaintext.
     */
    public function isEncryptedPass(string $cipherHex): bool
    {
        return str_starts_with(trim($cipherHex), 'def50200');
    }

    /**
     * Decrypt one account password using its password-protected account key.
     */
    public function decryptAccountPassword(string $securedKey, string $cipherHex, #[\SensitiveParameter] string $masterPassword): string
    {
        $rawKey = $this->unlockProtectedKey(trim($securedKey), $masterPassword);
        $binary = @hex2bin(trim($cipherHex));

        if ($binary === false) {
            throw new RuntimeException('The account password is not valid hex.');
        }

        return $this->decryptBinary($binary, $rawKey, false);
    }

    private function assertPassword(string $hash, #[\SensitiveParameter] string $password): void
    {
        if ($hash !== '' && ! password_verify($password, $hash)) {
            throw new RuntimeException('The export could not be decrypted. Check the export password.');
        }
    }

    /**
     * @return array{0: string, 1: string} [authentication key, encryption key]
     */
    private function deriveKeys(string $secret, string $salt, bool $fromPassword): array
    {
        if ($fromPassword) {
            $prehash = hash(self::HASH_ALGO, $secret, true);
            $prekey = hash_pbkdf2(self::HASH_ALGO, $prehash, $salt, self::PBKDF2_ITERATIONS, self::KEY_SIZE, true);
        } else {
            $prekey = $secret;
        }

        return [
            hash_hkdf(self::HASH_ALGO, $prekey, self::KEY_SIZE, self::AUTHENTICATION_INFO, $salt),
            hash_hkdf(self::HASH_ALGO, $prekey, self::KEY_SIZE, self::ENCRYPTION_INFO, $salt),
        ];
    }

    private function decryptBinary(string $ciphertext, string $secret, bool $fromPassword): string
    {
        if (strlen($ciphertext) < 4 + self::SALT_SIZE + self::BLOCK_SIZE + self::MAC_SIZE + 1) {
            throw new RuntimeException('The ciphertext is too short.');
        }
        if (substr($ciphertext, 0, 4) !== self::DEFUSE_VERSION) {
            throw new RuntimeException('The ciphertext header is not supported.');
        }

        $salt = substr($ciphertext, 4, self::SALT_SIZE);
        $iv = substr($ciphertext, 4 + self::SALT_SIZE, self::BLOCK_SIZE);
        $hmac = substr($ciphertext, -self::MAC_SIZE);
        $encrypted = substr($ciphertext, 4 + self::SALT_SIZE + self::BLOCK_SIZE, -self::MAC_SIZE);

        [$authKey, $encryptionKey] = $this->deriveKeys($secret, $salt, $fromPassword);

        if (! hash_equals(hash_hmac(self::HASH_ALGO, substr($ciphertext, 0, -self::MAC_SIZE), $authKey, true), $hmac)) {
            throw new RuntimeException('The ciphertext failed its integrity check.');
        }

        return (string) openssl_decrypt($encrypted, self::CIPHER, $encryptionKey, OPENSSL_RAW_DATA, $iv);
    }

    private function unwrapChecksummed(string $asciiHex, string $expectedHeader, string $label): string
    {
        $binary = @hex2bin(trim($asciiHex));
        if ($binary === false || strlen($binary) < 4 + self::MAC_SIZE) {
            throw new RuntimeException($label.' is invalid.');
        }
        if (substr($binary, 0, 4) !== $expectedHeader) {
            throw new RuntimeException($label.' has an unexpected header.');
        }

        $checksum = substr($binary, -self::MAC_SIZE);
        if (! hash_equals(hash(self::HASH_ALGO, substr($binary, 0, -self::MAC_SIZE), true), $checksum)) {
            throw new RuntimeException($label.' failed its checksum.');
        }

        return substr($binary, 4, -self::MAC_SIZE);
    }

    private function unlockProtectedKey(string $asciiHex, #[\SensitiveParameter] string $password): string
    {
        $encryptedKey = $this->unwrapChecksummed($asciiHex, self::PASSWORD_KEY_VERSION, 'The password-protected key');
        $secret = hash(self::HASH_ALGO, $password, true);
        $innerKeyAscii = $this->decryptBinary($encryptedKey, $secret, true);

        return $this->unwrapChecksummed($innerKeyAscii, self::KEY_VERSION, 'The account key');
    }

    private function decryptData(string $base64Ciphertext, string $rawKey): string
    {
        $hex = base64_decode($base64Ciphertext, true);
        if ($hex === false) {
            throw new RuntimeException('The data payload is not valid base64.');
        }

        $binary = @hex2bin(trim($hex));
        if ($binary === false) {
            throw new RuntimeException('The data payload is not valid hex.');
        }

        return $this->decryptBinary($binary, $rawKey, false);
    }
}
