<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Orm\Eloquent;

use Closure;
use Crystoline\Xpsr\Encryption\DecryptException;
use Crystoline\Xpsr\Encryption\Encrypter;
use Illuminate\Contracts\Encryption\Encrypter as EncrypterContract;
use Illuminate\Contracts\Encryption\StringEncrypter;
use InvalidArgumentException;
use SensitiveParameter;
use Stringable;

/**
 * XPSR's encrypter behind Eloquent's encrypted casts. Values are stored in Laravel's format with
 * app.key, so columns that a Laravel application encrypted read as they are. The encrypter is
 * resolved when a cast first needs it, so an application without APP_KEY starts.
 *
 * @internal
 */
final class EloquentEncrypter implements EncrypterContract, StringEncrypter
{
    private ?Encrypter $encrypter = null;

    /** @param Closure(): Encrypter $resolve */
    public function __construct(private readonly Closure $resolve) {}

    public function encrypt(#[SensitiveParameter] $value, $serialize = true): string
    {
        return $serialize ? $this->encrypter()->encrypt($value) : $this->encrypter()->encryptString(self::text($value));
    }

    public function decrypt($payload, $unserialize = true): mixed
    {
        $payload = self::payload($payload);

        return $unserialize ? $this->encrypter()->decrypt($payload) : $this->encrypter()->decryptString($payload);
    }

    public function encryptString(#[SensitiveParameter] $value): string
    {
        return $this->encrypter()->encryptString(self::text($value));
    }

    public function decryptString($payload): string
    {
        return $this->encrypter()->decryptString(self::payload($payload));
    }

    public function getKey(): string
    {
        return $this->encrypter()->key();
    }

    /** @return list<string> */
    public function getAllKeys(): array
    {
        return [$this->encrypter()->key(), ...$this->encrypter()->previousKeys()];
    }

    /** @return list<string> */
    public function getPreviousKeys(): array
    {
        return $this->encrypter()->previousKeys();
    }

    private function encrypter(): Encrypter
    {
        return $this->encrypter ??= ($this->resolve)();
    }

    /** An attribute as text: Eloquent hands over whatever was assigned to an encrypted column. */
    private static function text(mixed $value): string
    {
        if (is_string($value)) {
            return $value;
        }
        if (is_scalar($value) || $value instanceof Stringable) {
            return (string) $value;
        }

        throw new InvalidArgumentException('Only strings, numbers, and booleans can be encrypted as a string; got ' . get_debug_type($value) . '.');
    }

    private static function payload(mixed $payload): string
    {
        return is_string($payload) ? $payload : throw new DecryptException('The payload is invalid: it is ' . get_debug_type($payload) . ', not a string.');
    }
}
