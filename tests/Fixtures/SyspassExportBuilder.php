<?php

namespace Tests\Fixtures;

use App\Services\Imports\Syspass\ExportDecryptor;
use RuntimeException;

/**
 * Builds a small, synthetic encrypted sysPass XML export for tests.
 *
 * This is the encrypt direction of the defuse/php-encryption v2 format used by
 * sysPass; production code only ever decrypts. It mirrors
 * {@see ExportDecryptor} so fixtures can be
 * generated with a known export and master password.
 */
final class SyspassExportBuilder
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

    public function __construct(
        private readonly string $exportPassword = 'export-password',
        private readonly string $masterPassword = 'master-password',
    ) {}

    /**
     * Wrap pre-built fragment XML strings in one encrypted export document.
     *
     * @param  list<string>  $fragments
     * @param  array<string, string>  $meta
     */
    public function build(array $fragments, array $meta = [], bool $withHash = true): string
    {
        $data = '';
        foreach ($fragments as $fragment) {
            $key = random_bytes(self::KEY_SIZE);
            $data .= sprintf(
                '  <Data key="%s">%s</Data>'.PHP_EOL,
                bin2hex($this->protectKey($key, $this->exportPassword)),
                base64_encode(bin2hex($this->encryptBinary($fragment, $key, false))),
            );
        }

        $hash = $withHash ? ' hash="'.password_hash($this->exportPassword, PASSWORD_BCRYPT, ['cost' => 4]).'"' : '';
        $metaXml = '';
        foreach ($meta as $name => $value) {
            $metaXml .= sprintf('    <%s>%s</%s>'.PHP_EOL, $name, htmlspecialchars($value, ENT_XML1), $name);
        }
        if ($metaXml !== '') {
            $metaXml = '  <Meta>'.PHP_EOL.$metaXml.'  </Meta>'.PHP_EOL;
        }

        return '<?xml version="1.0" encoding="UTF-8"?>'.PHP_EOL
            .'<Root>'.PHP_EOL
            .$metaXml
            .'  <Encrypted'.$hash.'>'.PHP_EOL
            .$data
            .'  </Encrypted>'.PHP_EOL
            .'</Root>'.PHP_EOL;
    }

    /**
     * A fresh random account key.
     */
    public function newAccountKey(): string
    {
        return random_bytes(self::KEY_SIZE);
    }

    /**
     * The hex `<pass>` value for an account.
     */
    public function encryptedPass(string $plaintext, string $accountKey): string
    {
        return bin2hex($this->encryptBinary($plaintext, $accountKey, false));
    }

    /**
     * The hex `<key>` value: an account key protected by a password.
     */
    public function protectedAccountKey(string $accountKey, ?string $password = null): string
    {
        return bin2hex($this->protectKey($accountKey, $password ?? $this->masterPassword));
    }

    /**
     * Wrap pre-built Data nodes (key hex + base64 payload) in an export.
     *
     * @param  list<array{key: string, payload: string}>  $nodes
     */
    public function raw(array $nodes, bool $withHash = true): string
    {
        $data = '';
        foreach ($nodes as $node) {
            $data .= sprintf('  <Data key="%s">%s</Data>'.PHP_EOL, $node['key'], $node['payload']);
        }

        $hash = $withHash ? ' hash="'.password_hash($this->exportPassword, PASSWORD_BCRYPT, ['cost' => 4]).'"' : '';

        return '<?xml version="1.0" encoding="UTF-8"?>'.PHP_EOL
            .'<Root>'.PHP_EOL
            .'  <Meta/>'.PHP_EOL
            .'  <Encrypted'.$hash.'>'.PHP_EOL
            .$data
            .'  </Encrypted>'.PHP_EOL
            .'</Root>'.PHP_EOL;
    }

    public function exportPassword(): string
    {
        return $this->exportPassword;
    }

    public function masterPassword(): string
    {
        return $this->masterPassword;
    }

    /**
     * An `<Account>` fragment with one encrypted password.
     */
    public function account(
        string $id,
        string $name,
        ?string $clientId,
        ?string $categoryId,
        string $login,
        string $url,
        string $notes,
        string $password,
        array $tagIds = [],
    ): string {
        $accountKey = $this->newAccountKey();

        return $this->accountRaw(
            $id,
            $name,
            $clientId,
            $categoryId,
            $login,
            $url,
            $notes,
            $this->encryptedPass($password, $accountKey),
            $this->protectedAccountKey($accountKey),
            $tagIds,
        );
    }

    /**
     * An `<Account>` fragment with explicit raw `<pass>` and `<key>` values.
     *
     * @param  list<string>  $tagIds
     */
    public function accountRaw(
        string $id,
        string $name,
        ?string $clientId,
        ?string $categoryId,
        string $login,
        string $url,
        string $notes,
        string $pass,
        string $key,
        array $tagIds = [],
    ): string {
        $tags = '';
        foreach ($tagIds as $tagId) {
            $tags .= sprintf('        <tag id="%s"/>'.PHP_EOL, htmlspecialchars($tagId, ENT_XML1));
        }
        if ($tags !== '') {
            $tags = '      <tags>'.PHP_EOL.$tags.'      </tags>'.PHP_EOL;
        }

        return '    <Account id="'.$id.'">'.PHP_EOL
            .'      <name>'.htmlspecialchars($name, ENT_XML1).'</name>'.PHP_EOL
            .'      <clientId>'.htmlspecialchars((string) $clientId, ENT_XML1).'</clientId>'.PHP_EOL
            .'      <categoryId>'.htmlspecialchars((string) $categoryId, ENT_XML1).'</categoryId>'.PHP_EOL
            .'      <login>'.htmlspecialchars($login, ENT_XML1).'</login>'.PHP_EOL
            .'      <url>'.htmlspecialchars($url, ENT_XML1).'</url>'.PHP_EOL
            .'      <notes>'.htmlspecialchars($notes, ENT_XML1).'</notes>'.PHP_EOL
            .'      <pass>'.$pass.'</pass>'.PHP_EOL
            .'      <key>'.$key.'</key>'.PHP_EOL
            .$tags
            .'    </Account>'.PHP_EOL;
    }

    /**
     * @param  array<int|string, string>  $names
     */
    public function simpleFragment(string $tag, string $childTag, array $names): string
    {
        $xml = '  <'.$tag.'>'.PHP_EOL;
        foreach ($names as $id => $name) {
            $xml .= '    <'.$childTag.' id="'.$id.'">'.PHP_EOL
                .'      <name>'.htmlspecialchars($name, ENT_XML1).'</name>'.PHP_EOL
                .'      <description/>'.PHP_EOL
                .'    </'.$childTag.'>'.PHP_EOL;
        }

        return $xml.'  </'.$tag.'>'.PHP_EOL;
    }

    private function protectKey(string $key, string $password): string
    {
        if (strlen($key) !== self::KEY_SIZE) {
            throw new RuntimeException('The key must be 32 bytes.');
        }

        $raw = self::KEY_VERSION.$key;
        $inner = bin2hex($raw.hash(self::HASH_ALGO, $raw, true));
        $encrypted = $this->encryptBinary($inner, hash(self::HASH_ALGO, $password, true), true);

        return self::PASSWORD_KEY_VERSION.$encrypted.hash(self::HASH_ALGO, self::PASSWORD_KEY_VERSION.$encrypted, true);
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function deriveKeys(string $secret, string $salt, bool $fromPassword): array
    {
        if ($fromPassword) {
            $prekey = hash_pbkdf2(self::HASH_ALGO, hash(self::HASH_ALGO, $secret, true), $salt, self::PBKDF2_ITERATIONS, self::KEY_SIZE, true);
        } else {
            $prekey = $secret;
        }

        return [
            hash_hkdf(self::HASH_ALGO, $prekey, self::KEY_SIZE, self::AUTHENTICATION_INFO, $salt),
            hash_hkdf(self::HASH_ALGO, $prekey, self::KEY_SIZE, self::ENCRYPTION_INFO, $salt),
        ];
    }

    private function encryptBinary(string $plaintext, string $secret, bool $fromPassword): string
    {
        $salt = random_bytes(self::SALT_SIZE);
        $iv = random_bytes(self::BLOCK_SIZE);

        [$authKey, $encryptionKey] = $this->deriveKeys($secret, $salt, $fromPassword);
        $ciphertext = openssl_encrypt($plaintext, self::CIPHER, $encryptionKey, OPENSSL_RAW_DATA, $iv);
        if ($ciphertext === false) {
            throw new RuntimeException('The fixture could not be encrypted.');
        }

        $payload = self::DEFUSE_VERSION.$salt.$iv.$ciphertext;

        return $payload.hash_hmac(self::HASH_ALGO, $payload, $authKey, true);
    }
}
