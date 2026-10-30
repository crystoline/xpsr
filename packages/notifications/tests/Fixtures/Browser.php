<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Notifications\Tests\Fixtures;

use OpenSSLAsymmetricKey;
use RuntimeException;

/**
 * A browser's side of web push: its subscription keys, and what it does with a push. It decrypts
 * an aes128gcm body (RFC 8291 and RFC 8188) and checks the VAPID signature (RFC 8292), so tests
 * see what a real browser and push service would.
 */
final class Browser
{
    private const SPKI_P256 = '3059301306072a8648ce3d020106082a8648ce3d030107034200';

    public readonly string $p256dh;
    public readonly string $auth;
    private readonly OpenSSLAsymmetricKey $key;
    private readonly string $public;
    private readonly string $secret;

    public function __construct(public readonly string $endpoint)
    {
        $key = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
        $details = $key === false ? false : openssl_pkey_get_details($key);
        if ($key === false || !is_array($details)) {
            throw new RuntimeException('Could not make a P-256 key.');
        }
        $this->key = $key;
        $this->public = "\x04" . str_pad($details['ec']['x'], 32, "\0", STR_PAD_LEFT) . str_pad($details['ec']['y'], 32, "\0", STR_PAD_LEFT);
        $this->secret = random_bytes(16);
        $this->p256dh = self::encode($this->public);
        $this->auth = self::encode($this->secret);
    }

    /** @return array{endpoint: string, keys: array{p256dh: string, auth: string}} as subscription.toJSON() gives it */
    public function subscription(): array
    {
        return ['endpoint' => $this->endpoint, 'keys' => ['p256dh' => $this->p256dh, 'auth' => $this->auth]];
    }

    /** The plaintext of an aes128gcm push body. */
    public function decrypt(string $body): string
    {
        $salt = substr($body, 0, 16);
        $length = ord($body[20]);
        $server = substr($body, 21, $length);
        $ciphertext = substr($body, 21 + $length);
        $shared = openssl_pkey_derive(self::pem($server), $this->key);
        if ($shared === false) {
            throw new RuntimeException('ECDH failed.');
        }
        $ikm = hash_hmac('sha256', "WebPush: info\0" . $this->public . $server . "\x01", hash_hmac('sha256', $shared, $this->secret, true), true);
        $prk = hash_hmac('sha256', $ikm, $salt, true);
        $cek = substr(hash_hmac('sha256', "Content-Encoding: aes128gcm\0\x01", $prk, true), 0, 16);
        $nonce = substr(hash_hmac('sha256', "Content-Encoding: nonce\0\x01", $prk, true), 0, 12);
        $plain = openssl_decrypt(substr($ciphertext, 0, -16), 'aes-128-gcm', $cek, OPENSSL_RAW_DATA, $nonce, substr($ciphertext, -16));
        if ($plain === false) {
            throw new RuntimeException('The push body did not decrypt.');
        }
        // The last record ends with the delimiter 2, then padding zeros.
        $plain = rtrim($plain, "\0");
        if (!str_ends_with($plain, "\x02")) {
            throw new RuntimeException('The push body has no final-record delimiter.');
        }

        return substr($plain, 0, -1);
    }

    /**
     * Checks an "Authorization: vapid t=<JWT>, k=<key>" header against the server's public key,
     * as a push service does, and returns the JWT's claims.
     *
     * @return array<string, mixed>
     */
    public static function vapid(string $authorization, string $publicKey): array
    {
        if (!preg_match('/\Avapid t=([^,]+), k=(\S+)\z/', $authorization, $match)) {
            throw new RuntimeException("Not a VAPID header: {$authorization}");
        }
        if ($match[2] !== $publicKey) {
            throw new RuntimeException('The VAPID header has another public key.');
        }
        [$header, $claims, $signature] = explode('.', $match[1]);
        $raw = self::decode($signature);
        if (strlen($raw) !== 64) {
            throw new RuntimeException('An ES256 signature has 64 bytes.');
        }
        $der = self::derInteger(substr($raw, 0, 32)) . self::derInteger(substr($raw, 32));
        $der = "\x30" . chr(strlen($der)) . $der;
        if (openssl_verify("{$header}.{$claims}", $der, self::pem(self::decode($publicKey)), OPENSSL_ALGO_SHA256) !== 1) {
            throw new RuntimeException('The VAPID signature does not verify.');
        }
        $decoded = json_decode(self::decode($claims), true, flags: JSON_THROW_ON_ERROR);
        $type = json_decode(self::decode($header), true, flags: JSON_THROW_ON_ERROR);
        if (!is_array($decoded) || !is_array($type) || ($type['alg'] ?? null) !== 'ES256') {
            throw new RuntimeException('Not an ES256 JWT.');
        }

        return $decoded;
    }

    private static function pem(string $point): string
    {
        $der = (string) hex2bin(self::SPKI_P256) . $point;

        return "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($der), 64, "\n") . "-----END PUBLIC KEY-----\n";
    }

    private static function derInteger(string $bytes): string
    {
        $bytes = ltrim($bytes, "\0");
        if ($bytes === '' || ord($bytes[0]) > 0x7F) {
            $bytes = "\0" . $bytes;
        }

        return "\x02" . chr(strlen($bytes)) . $bytes;
    }

    private static function encode(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }

    private static function decode(string $value): string
    {
        return (string) base64_decode(strtr($value, '-_', '+/'), true);
    }
}
