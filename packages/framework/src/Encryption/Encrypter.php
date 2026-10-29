<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Encryption;

use Crystoline\Xpsr\Configuration;
use InvalidArgumentException;
use JsonException;
use RuntimeException;
use SensitiveParameter;

/**
 * Encrypts and authenticates values with app.key (APP_KEY), in Laravel's format: data that a
 * Laravel application encrypted with the same key decrypts here, and the other way round.
 *
 *     $stored = $encrypter->encryptString($apiSecret);
 *     $apiSecret = $encrypter->decryptString($stored);
 *
 * CBC ciphers are authenticated with HMAC-SHA256 over the IV and ciphertext, GCM ciphers with
 * their tag, so changed data fails to decrypt rather than decrypting to something else.
 * Decryption tries app.key, then each of app.previous_keys (APP_PREVIOUS_KEYS), so a key can be
 * replaced while data encrypted with the old one is still read.
 */
final class Encrypter
{
    /** The supported ciphers and their key lengths in bytes. */
    public const CIPHERS = ['AES-128-CBC' => 16, 'AES-256-CBC' => 32, 'AES-128-GCM' => 16, 'AES-256-GCM' => 32];

    /** The cipher's OpenSSL name. */
    private readonly string $cipher;

    /** Whether the cipher authenticates itself (GCM), so no MAC is added. */
    private readonly bool $aead;

    /**
     * @param string $key the raw key: 16 bytes for AES-128, 32 for AES-256
     * @param list<string> $previousKeys raw keys that only decrypt, tried in order after $key
     */
    public function __construct(
        #[SensitiveParameter]
        private readonly string $key,
        string $cipher = 'AES-256-CBC',
        #[SensitiveParameter]
        private readonly array $previousKeys = [],
    ) {
        $name = strtoupper($cipher);
        $length = self::CIPHERS[$name] ?? throw new InvalidArgumentException(sprintf('Unknown cipher "%s". Use %s.', $cipher, implode(', ', array_keys(self::CIPHERS))));
        foreach ([$key, ...$previousKeys] as $candidate) {
            if (strlen($candidate) !== $length) {
                throw new InvalidArgumentException("An {$name} key is {$length} bytes; one of the keys given is " . strlen($candidate) . '.');
            }
        }
        $this->cipher = strtolower($name);
        $this->aead = str_ends_with($this->cipher, '-gcm');
    }

    /**
     * The encrypter for app.key, app.cipher, and app.previous_keys. Keys are raw, or "base64:"
     * followed by the key in base64, as php xpsr key:generate and Laravel write them.
     *
     * @throws MissingKeyException when app.key is not set
     * @throws InvalidArgumentException when a setting is invalid
     */
    public static function fromConfiguration(Configuration $config): self
    {
        $cipher = $config->get('app.cipher', 'AES-256-CBC');
        if (!is_string($cipher) || !isset(self::CIPHERS[strtoupper($cipher)])) {
            throw new InvalidArgumentException('app.cipher must be one of ' . implode(', ', array_keys(self::CIPHERS)) . '.');
        }
        $key = $config->get('app.key');
        if ($key === null || $key === '') {
            throw new MissingKeyException('No encryption key is set: APP_KEY is empty. Run php xpsr key:generate --write to make one.');
        }
        $previous = $config->get('app.previous_keys', []);
        if (!is_array($previous)) {
            throw new InvalidArgumentException('app.previous_keys must be a list of keys.');
        }
        $previousKeys = [];
        foreach (array_values($previous) as $index => $old) {
            $previousKeys[] = self::parseKey($old, "app.previous_keys[{$index}]", $cipher);
        }

        return new self(self::parseKey($key, 'app.key', $cipher), $cipher, $previousKeys);
    }

    /** A new random key for $cipher, as APP_KEY takes it: "base64:" and the key in base64. */
    public static function generateKey(string $cipher = 'AES-256-CBC'): string
    {
        $length = self::CIPHERS[strtoupper($cipher)] ?? throw new InvalidArgumentException(sprintf('Unknown cipher "%s". Use %s.', $cipher, implode(', ', array_keys(self::CIPHERS))));

        return 'base64:' . base64_encode(random_bytes($length));
    }

    public function encryptString(#[SensitiveParameter] string $value): string
    {
        $iv = random_bytes($this->ivLength());
        $tag = '';
        $encrypted = $this->aead
            ? openssl_encrypt($value, $this->cipher, $this->key, 0, $iv, $tag)
            : openssl_encrypt($value, $this->cipher, $this->key, 0, $iv);
        if ($encrypted === false) {
            throw new RuntimeException('Could not encrypt the data: ' . (openssl_error_string() ?: 'OpenSSL gave no reason') . '.');
        }
        $iv = base64_encode($iv);
        $payload = ['iv' => $iv, 'value' => $encrypted, 'mac' => $this->aead ? '' : self::mac($iv, $encrypted, $this->key), 'tag' => base64_encode($tag)];

        return base64_encode(json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }

    /** @throws DecryptException when the payload is malformed, changed, or under another key */
    public function decryptString(string $payload): string
    {
        $data = $this->payload($payload);
        $iv = (string) base64_decode($data['iv'], true);
        $tag = base64_decode($data['tag'], true);
        if ($tag === false || ($this->aead ? strlen($tag) !== 16 : $tag !== '')) {
            throw new DecryptException('The payload is invalid: its tag does not suit ' . strtoupper($this->cipher) . '.');
        }
        $authentic = $this->aead;
        foreach ([$this->key, ...$this->previousKeys] as $key) {
            if (!$this->aead) {
                if (!hash_equals(self::mac($data['iv'], $data['value'], $key), $data['mac'])) {
                    continue;
                }
                $authentic = true;
            }
            $decrypted = $this->aead
                ? openssl_decrypt($data['value'], $this->cipher, $key, 0, $iv, $tag)
                : openssl_decrypt($data['value'], $this->cipher, $key, 0, $iv);
            if ($decrypted !== false) {
                return $decrypted;
            }
        }

        throw new DecryptException($authentic
            ? 'Could not decrypt the data: it was changed, or encrypted with a key that is not app.key or in app.previous_keys.'
            : 'The MAC is invalid: the data was changed, or encrypted with a key that is not app.key or in app.previous_keys.');
    }

    /**
     * Encrypts a string, number, boolean, null, or an array of these, serialized as Laravel's
     * encrypt() does. Encode objects first, for example as JSON with encryptString().
     */
    public function encrypt(#[SensitiveParameter] mixed $value): string
    {
        if (!self::isPlain($value)) {
            throw new InvalidArgumentException('encrypt() takes strings, numbers, booleans, null, and arrays of these. Encode objects first, for example as JSON with encryptString().');
        }

        return $this->encryptString(serialize($value));
    }

    /**
     * Decrypts what encrypt(), or Laravel's encrypt(), made. Serialized objects are refused
     * rather than restored: restoring them would let anyone who learns the key run code on the
     * server through the application's classes.
     *
     * @throws DecryptException
     */
    public function decrypt(string $payload): mixed
    {
        $serialized = $this->decryptString($payload);
        $failed = false;
        set_error_handler(static function () use (&$failed): bool {
            $failed = true;

            return true;
        });
        try {
            $value = unserialize($serialized, ['allowed_classes' => false]);
        } finally {
            restore_error_handler();
        }
        if ($failed || ($value === false && $serialized !== serialize(false))) {
            throw new DecryptException('The data is not a serialized value. Use decryptString() for data from encryptString().');
        }
        if (!self::isPlain($value)) {
            throw new DecryptException('The data holds a serialized PHP object, which decrypt() does not restore. It reads strings, numbers, booleans, null, and arrays of these.');
        }

        return $value;
    }

    /** The raw key that encrypts, for libraries that take it, such as Eloquent's encrypter. */
    public function key(): string
    {
        return $this->key;
    }

    /**
     * The raw keys that only decrypt.
     *
     * @return list<string>
     */
    public function previousKeys(): array
    {
        return $this->previousKeys;
    }

    private static function parseKey(mixed $value, string $setting, string $cipher): string
    {
        $length = self::CIPHERS[strtoupper($cipher)];
        if (!is_string($value)) {
            throw new InvalidArgumentException("{$setting} must be a string: \"base64:\" and {$length} random bytes in base64.");
        }
        if (str_starts_with($value, 'base64:')) {
            $decoded = base64_decode(substr($value, 7), true);
            if ($decoded === false) {
                throw new InvalidArgumentException("{$setting} starts with \"base64:\", but the rest is not base64.");
            }
            $value = $decoded;
        }
        if (strlen($value) !== $length) {
            throw new InvalidArgumentException(sprintf('%s must be %d bytes for %s; it is %d. Run php xpsr key:generate for a new key.', $setting, $length, strtoupper($cipher), strlen($value)));
        }

        return $value;
    }

    /** @return array{iv: string, value: string, mac: string, tag: string} */
    private function payload(string $payload): array
    {
        $json = base64_decode($payload, true);
        try {
            $data = $json === false ? null : json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            $data = null;
        }
        if (!is_array($data)) {
            throw new DecryptException('The payload is invalid: it is not base64-encoded JSON.');
        }
        foreach (['iv', 'value', 'mac'] as $field) {
            if (!isset($data[$field]) || !is_string($data[$field])) {
                throw new DecryptException("The payload is invalid: it has no \"{$field}\".");
            }
        }
        // Payloads from Laravel before 9.0 have no tag.
        $tag = $data['tag'] ?? '';
        $iv = base64_decode($data['iv'], true);
        if (!is_string($tag) || $iv === false || strlen($iv) !== $this->ivLength()) {
            throw new DecryptException('The payload is invalid: its IV or tag is malformed.');
        }

        return ['iv' => $data['iv'], 'value' => $data['value'], 'mac' => $data['mac'], 'tag' => $tag];
    }

    private function ivLength(): int
    {
        return (int) openssl_cipher_iv_length($this->cipher);
    }

    private static function mac(string $iv, string $value, string $key): string
    {
        return hash_hmac('sha256', $iv . $value, $key);
    }

    /** Whether $value is a scalar, null, or an array of these, at any depth. */
    private static function isPlain(mixed $value): bool
    {
        if (is_array($value)) {
            foreach ($value as $item) {
                if (!self::isPlain($item)) {
                    return false;
                }
            }

            return true;
        }

        // Objects, including those unserialize() leaves as __PHP_Incomplete_Class, are not.
        return $value === null || is_scalar($value);
    }
}
