<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Auth\OAuth2;

use Defuse\Crypto\Key;
use InvalidArgumentException;
use League\OAuth2\Server\CryptKey;
use LogicException;

/**
 * The RSA key pair that signs and verifies access tokens, and the key that encrypts authorization
 * codes and refresh tokens: the files and the setting php xpsr oauth:keys writes.
 *
 * @internal Not covered by the backward-compatibility promise; see docs/VERSIONING.md.
 */
final class Keys
{
    public function __construct(private readonly OAuthOptions $options, private readonly string $root) {}

    public function privateKey(): CryptKey
    {
        return $this->key($this->options->privateKey, 'private_key');
    }

    public function publicKey(): CryptKey
    {
        return $this->key($this->options->publicKey, 'public_key');
    }

    public function encryptionKey(): Key
    {
        return $this->options->encryptionKey();
    }

    /** The private key file's full path. */
    public function privateKeyPath(): string
    {
        return self::path($this->root, $this->options->privateKey);
    }

    /** The public key file's full path. */
    public function publicKeyPath(): string
    {
        return self::path($this->root, $this->options->publicKey);
    }

    /** A path for messages: relative to the project root when it is inside it. */
    public function display(string $path): string
    {
        $root = rtrim($this->root, '/') . '/';

        return str_starts_with($path, $root) ? substr($path, strlen($root)) : $path;
    }

    private function key(string $path, string $option): CryptKey
    {
        $file = self::path($this->root, $path);
        if (!is_file($file) || !is_readable($file)) {
            throw new InvalidArgumentException("oauth.{$option}: the key file {$path} does not exist or cannot be read. Run php xpsr oauth:keys to make the keys.");
        }
        try {
            // The permission check is left to the deployment: the command writes the files as 0600.
            return new CryptKey($file, null, false);
        } catch (LogicException $exception) {
            throw new InvalidArgumentException("oauth.{$option}: the key file {$path} is not a PEM key: " . $exception->getMessage(), 0, $exception);
        }
    }

    private static function path(string $root, string $path): string
    {
        return str_starts_with($path, '/') ? $path : rtrim($root, '/') . '/' . $path;
    }
}
