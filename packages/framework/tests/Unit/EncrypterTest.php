<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Tests\Unit;

use Crystoline\Xpsr\Application;
use Crystoline\Xpsr\Configuration;
use Crystoline\Xpsr\Encryption\DecryptException;
use Crystoline\Xpsr\Encryption\Encrypter;
use Crystoline\Xpsr\Encryption\MissingKeyException;
use Crystoline\Xpsr\ServiceContainer\ContainerException;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use stdClass;

final class EncrypterTest extends TestCase
{
    /**
     * Made by Laravel 12's Illuminate\Encryption\Encrypter: encryptString('Ada Lovelace') and
     * encrypt(self::ARRAY), with the key shown.
     */
    private const LARAVEL = [
        'AES-128-CBC' => [
            'key' => 'base64:iuazhlanQ4Qcq4H6fM19mg==',
            'string' => 'eyJpdiI6InJmNEd2VFdOV3dIUkNEakFDVS9kR2c9PSIsInZhbHVlIjoiRnhVc2d4Q25NTjR3dXhnUzhaOExEQT09IiwibWFjIjoiYWJiYTUzOWNjYjJiNjczZDM3MjkxZjQ1NmMzYzcxODhkNmJjYzdjNmZkZGE3MmVhYTI3NGViYWYyZTExYjYyMyIsInRhZyI6IiJ9',
            'array' => 'eyJpdiI6IlRqVjdsQ3BNdXQ0U2NFQUROdmdQMHc9PSIsInZhbHVlIjoiV3dyRERyUUZZWlFxaUErOCtNZXZUOW5XaElIaDZFNUkzSVVYRlVabVpZS2REMWFibGtacmpFcUZtcUxNd0hzZ2NKNDA1UzBrWXJ6SHQrN2NpVkE4bTQwV0FDTVFDamlzbXZINmNMd1RBYk5CSmhRUkY2MzB4K3hKbFFWbEFTRlkiLCJtYWMiOiJhNWEwNjA1MDNjOWUxZjFkZGMwZGNjMTI0ZTE1ODU0MTEwOTYyNGE0Yjg2NWVmNTQ4NjI4MjU5ZjYzZjcxZWM5IiwidGFnIjoiIn0=',
        ],
        'AES-256-CBC' => [
            'key' => 'base64:iuazhlanQ4Qcq4H6fM19muEkOeK7bAWPvK81OfuXmEg=',
            'string' => 'eyJpdiI6ImN2dlFXcit1ckVLV3RBY003Ry9ES1E9PSIsInZhbHVlIjoiTFdQUmdTSHQ1cGl3QVROK3BSeEN4UT09IiwibWFjIjoiY2FiNzliMGU5NTA2M2MzODliMTc4Mjk1ZmU1NGVjYjg2YjFlMjJmMmIxYTdkY2M4OTdkNmMyODgzZGU1MjdkYiIsInRhZyI6IiJ9',
            'array' => 'eyJpdiI6ImJlMnA2bHc3ZUtYY1pQY3ZJMGNuRlE9PSIsInZhbHVlIjoic0JSSitSYWF3U0xvaFRxZGVOK0tEZ1VDNlUxcFNmcHlzTFJTTC92VXRYbTdWWTFTZi9QN2k0MmV1R3MvdzBydmhUZmI0Y0llcWkvM3RzaFVqSy94NE9BcEtVeVNNVmdQeVEzRjBCQnFsVWF2ejRncCtleUY0Tk14V0hWdUU3SHMiLCJtYWMiOiJiZjI0NDg4MWUyMzMzNmUwNTRjNmY0MjE1OWU5OGE0NTAxYmQ3NWM0YzNjZmYzNzMxOTI0MjViZTZlZjhlNTZiIiwidGFnIjoiIn0=',
        ],
        'AES-128-GCM' => [
            'key' => 'base64:iuazhlanQ4Qcq4H6fM19mg==',
            'string' => 'eyJpdiI6ImRUaXhkOTdMNHppa3VMWEYiLCJ2YWx1ZSI6IjJpc0hrTklPZHcwNEpmaUkiLCJtYWMiOiIiLCJ0YWciOiJuZm8rSFZJamUvOHBUellHSDZxSzRnPT0ifQ==',
            'array' => 'eyJpdiI6InhZcHdIYXVLc08wU3Vrd0giLCJ2YWx1ZSI6InNVY0VIRlBVRzQ2WnBhckN1eG9SZjJiUG5YVUd0UWVSVzE2bkdFK0hmbUV1eEFzSE10SnpVcm9mYVQ3VzFDcVVyd0V2VEo4Ti85Z04yMnY0UWVpWVJRb1gxa2ZYdUsvYzU1YmZHbTBuSnVnd2VpMHlVR1k0VWZSTUlqNnciLCJtYWMiOiIiLCJ0YWciOiJoSGJRd3N3MFdyWUtqRzl1QjgwM3ZRPT0ifQ==',
        ],
        'AES-256-GCM' => [
            'key' => 'base64:iuazhlanQ4Qcq4H6fM19muEkOeK7bAWPvK81OfuXmEg=',
            'string' => 'eyJpdiI6ImV6VzNhL05HZHE1MHdPWGYiLCJ2YWx1ZSI6IkdIOFVNa0VhK2VNa0dxRTgiLCJtYWMiOiIiLCJ0YWciOiJ6b0RZK1RyZ0FvaDNLeVg4aUtuZXlBPT0ifQ==',
            'array' => 'eyJpdiI6InJ0NlZWdjRQeEFlN1c3R3YiLCJ2YWx1ZSI6IjZ1aDkvVTdKbkIyVG5jN29QU3U2Rm1HaW9Qc2h6Tno3bDlFUHY3UXYyTzU3K0YzdmFJbDNtaVpkRXI4LzQrRktyTWg3ZStJR29ER0ZNdUVkeWpCdG9TT0lsTEhHTHA4MTF5KzJrdU81L2FMNGlpYjgvQ0J4WHA0ZHZBdWwiLCJtYWMiOiIiLCJ0YWciOiJCRGkyU05VQzUrMFVlV3NwNnNkanBRPT0ifQ==',
        ],
    ];

    private const ARRAY = ['card' => '4111 1111 1111 1111', 'attempts' => 3, 'active' => true, 'note' => null];

    /** Laravel's encrypt(new ArrayObject(['card' => '4111'])) under the AES-256-CBC key. */
    private const LARAVEL_OBJECT = 'eyJpdiI6IktqVVdQTlFEREFVV24zaUgwVGtFK1E9PSIsInZhbHVlIjoiMWtwbkUyVGNYS01rZlJGMzMzK21DNFRHc2t3SjR3aDVOclY5MWZ0WFh4bHhPQTlRK3lvc2g0dWRQQmNRNUpsejM0emlyNCtRSER3RmhOUjdrRUVXWGdMWWw1UnVwVDZwUkIwZm1GUXg3YVE9IiwibWFjIjoiMGY0NmQ2YWI5ZjUwYmNlYzc3ZjUwODAwNjE5ZDAyNzc4ODU5MTgzODZhOGQ5NGI0OWYyNWJhZDdlMmEwY2IwZSIsInRhZyI6IiJ9';

    /** @return iterable<string, array{string}> */
    public static function ciphers(): iterable
    {
        foreach (array_keys(Encrypter::CIPHERS) as $cipher) {
            yield $cipher => [$cipher];
        }
    }

    #[DataProvider('ciphers')]
    public function testReadsWhatLaravelEncrypted(string $cipher): void
    {
        $encrypter = $this->encrypter(self::LARAVEL[$cipher]['key'], $cipher);
        self::assertSame('Ada Lovelace', $encrypter->decryptString(self::LARAVEL[$cipher]['string']));
        self::assertSame(self::ARRAY, $encrypter->decrypt(self::LARAVEL[$cipher]['array']));
    }

    #[DataProvider('ciphers')]
    public function testValuesComeBackAsTheyWereEncrypted(string $cipher): void
    {
        $encrypter = $this->encrypter(Encrypter::generateKey($cipher), $cipher);
        foreach (['', 'Ada Lovelace', "multi\nline \u{1F512}", str_repeat('x', 10_000)] as $text) {
            self::assertSame($text, $encrypter->decryptString($encrypter->encryptString($text)));
        }
        foreach ([self::ARRAY, [], 0, 1.5, false, true, null, '', 'text', ['nested' => ['list' => [1, 2, 3]]]] as $value) {
            self::assertSame($value, $encrypter->decrypt($encrypter->encrypt($value)));
        }
    }

    #[DataProvider('ciphers')]
    public function testPayloadsHaveLaravelsShape(string $cipher): void
    {
        $encrypter = $this->encrypter(Encrypter::generateKey($cipher), $cipher);
        $first = $encrypter->encryptString('Ada Lovelace');
        $payload = json_decode((string) base64_decode($first, true), true);

        self::assertIsArray($payload);
        self::assertSame(['iv', 'value', 'mac', 'tag'], array_keys($payload));
        $gcm = str_ends_with($cipher, 'GCM');
        self::assertSame($gcm ? 12 : 16, strlen((string) base64_decode($payload['iv'], true)));
        if ($gcm) {
            self::assertSame('', $payload['mac'], 'GCM authenticates with its tag.');
            self::assertSame(16, strlen((string) base64_decode($payload['tag'], true)));
        } else {
            self::assertMatchesRegularExpression('/\A[0-9a-f]{64}\z/', $payload['mac'], 'HMAC-SHA256 in hex.');
            self::assertSame('', $payload['tag']);
        }
        self::assertNotSame($first, $encrypter->encryptString('Ada Lovelace'), 'Every encryption has a new IV.');
    }

    #[DataProvider('ciphers')]
    public function testChangedDataIsRefused(string $cipher): void
    {
        $encrypter = $this->encrypter(self::LARAVEL[$cipher]['key'], $cipher);
        $payload = json_decode((string) base64_decode(self::LARAVEL[$cipher]['string'], true), true);
        self::assertIsArray($payload);
        // Flips the first character of the ciphertext, which stays valid base64.
        $payload['value'] = ($payload['value'][0] === 'A' ? 'B' : 'A') . substr($payload['value'], 1);

        $this->expectException(DecryptException::class);
        $this->expectExceptionMessage(str_ends_with($cipher, 'GCM') ? 'Could not decrypt the data: it was changed' : 'The MAC is invalid: the data was changed');
        $encrypter->decryptString(base64_encode((string) json_encode($payload)));
    }

    #[DataProvider('ciphers')]
    public function testAnotherKeyCannotDecrypt(string $cipher): void
    {
        $this->expectException(DecryptException::class);
        $this->expectExceptionMessage('encrypted with a key that is not app.key or in app.previous_keys');
        $this->encrypter(Encrypter::generateKey($cipher), $cipher)->decryptString(self::LARAVEL[$cipher]['string']);
    }

    public function testPreviousKeysDecryptButNewDataUsesTheCurrentKey(): void
    {
        $oldKey = self::LARAVEL['AES-256-CBC']['key'];
        $newKey = Encrypter::generateKey();
        $config = new Configuration();
        $config->set('app', ['key' => $newKey, 'previous_keys' => [Encrypter::generateKey(), $oldKey]]);
        $encrypter = Encrypter::fromConfiguration($config);

        self::assertSame('Ada Lovelace', $encrypter->decryptString(self::LARAVEL['AES-256-CBC']['string']), 'Data under an old key still reads.');
        $fresh = $encrypter->encryptString('Grace Hopper');
        self::assertSame('Grace Hopper', $this->encrypter($newKey)->decryptString($fresh));
        $this->expectException(DecryptException::class);
        $this->encrypter($oldKey)->decryptString($fresh);
    }

    public function testPayloadsFromLaravelBeforeNineHaveNoTag(): void
    {
        $payload = json_decode((string) base64_decode(self::LARAVEL['AES-256-CBC']['string'], true), true);
        self::assertIsArray($payload);
        unset($payload['tag']);

        $encrypter = $this->encrypter(self::LARAVEL['AES-256-CBC']['key']);
        self::assertSame('Ada Lovelace', $encrypter->decryptString(base64_encode((string) json_encode($payload))));
    }

    /** @return iterable<string, array{string, string}> */
    public static function malformedPayloads(): iterable
    {
        $cbc = json_decode((string) base64_decode(self::LARAVEL['AES-256-CBC']['string'], true), true);
        $gcm = json_decode((string) base64_decode(self::LARAVEL['AES-256-GCM']['string'], true), true);
        assert(is_array($cbc) && is_array($gcm));
        $encode = static fn(mixed $data): string => base64_encode((string) json_encode($data));

        yield 'not base64' => ['AES-256-CBC', 'not a payload!', 'The payload is invalid: it is not base64-encoded JSON.'];
        yield 'not JSON' => ['AES-256-CBC', base64_encode('{"iv":'), 'The payload is invalid: it is not base64-encoded JSON.'];
        yield 'JSON, not an object' => ['AES-256-CBC', $encode('text'), 'The payload is invalid: it is not base64-encoded JSON.'];
        yield 'no value' => ['AES-256-CBC', $encode(['iv' => $cbc['iv'], 'mac' => $cbc['mac']]), 'The payload is invalid: it has no "value".'];
        yield 'value not a string' => ['AES-256-CBC', $encode(['value' => 1] + $cbc), 'The payload is invalid: it has no "value".'];
        yield 'short IV' => ['AES-256-CBC', $encode(['iv' => base64_encode('short')] + $cbc), 'The payload is invalid: its IV or tag is malformed.'];
        yield 'GCM payload, CBC cipher' => ['AES-256-CBC', self::LARAVEL['AES-256-GCM']['string'], 'The payload is invalid: its IV or tag is malformed.'];
        yield 'tag on a CBC payload' => ['AES-256-CBC', $encode(['tag' => base64_encode(str_repeat('t', 16))] + $cbc), 'The payload is invalid: its tag does not suit AES-256-CBC.'];
        yield 'short GCM tag' => ['AES-256-GCM', $encode(['tag' => base64_encode('short')] + $gcm), 'The payload is invalid: its tag does not suit AES-256-GCM.'];
        yield 'no GCM tag' => ['AES-256-GCM', $encode(['iv' => $gcm['iv'], 'value' => $gcm['value'], 'mac' => '']), 'The payload is invalid: its tag does not suit AES-256-GCM.'];
    }

    #[DataProvider('malformedPayloads')]
    public function testMalformedPayloadsAreRefused(string $cipher, string $payload, string $message): void
    {
        $this->expectException(DecryptException::class);
        $this->expectExceptionMessage($message);
        $this->encrypter(self::LARAVEL[$cipher]['key'], $cipher)->decryptString($payload);
    }

    public function testSerializedObjectsAreRefusedRatherThanRestored(): void
    {
        $encrypter = $this->encrypter(self::LARAVEL['AES-256-CBC']['key']);
        try {
            $encrypter->decrypt(self::LARAVEL_OBJECT);
            self::fail('A serialized object was restored.');
        } catch (DecryptException $exception) {
            self::assertStringContainsString('The data holds a serialized PHP object, which decrypt() does not restore.', $exception->getMessage());
        }
        // The same data is readable as the serialized text, for code that must deal with it.
        self::assertStringStartsWith('O:11:"ArrayObject"', $encrypter->decryptString(self::LARAVEL_OBJECT));
    }

    public function testObjectsAreNotEncrypted(): void
    {
        $encrypter = $this->encrypter(Encrypter::generateKey());
        foreach ([new stdClass(), ['deep' => [new stdClass()]]] as $value) {
            try {
                $encrypter->encrypt($value);
                self::fail('An object was encrypted.');
            } catch (InvalidArgumentException $exception) {
                self::assertStringContainsString('Encode objects first, for example as JSON with encryptString().', $exception->getMessage());
            }
        }
    }

    public function testDecryptNeedsSerializedData(): void
    {
        $encrypter = $this->encrypter(Encrypter::generateKey());
        $this->expectException(DecryptException::class);
        $this->expectExceptionMessage('The data is not a serialized value. Use decryptString() for data from encryptString().');
        $encrypter->decrypt($encrypter->encryptString('plain text'));
    }

    public function testKeysAreGeneratedForEachCipher(): void
    {
        foreach (Encrypter::CIPHERS as $cipher => $length) {
            $key = Encrypter::generateKey($cipher);
            self::assertStringStartsWith('base64:', $key);
            self::assertSame($length, strlen((string) base64_decode(substr($key, 7), true)));
            self::assertNotSame($key, Encrypter::generateKey($cipher));
        }
        $this->expectException(InvalidArgumentException::class);
        Encrypter::generateKey('DES');
    }

    public function testRawKeysWork(): void
    {
        $config = new Configuration();
        $config->set('app', ['key' => str_repeat('k', 32)]);
        $encrypter = Encrypter::fromConfiguration($config);
        self::assertSame(str_repeat('k', 32), $encrypter->key());
        self::assertSame('Ada', $encrypter->decryptString($encrypter->encryptString('Ada')));
    }

    /** @return iterable<string, array{array<string, mixed>, string}> */
    public static function invalidConfiguration(): iterable
    {
        $key = 'base64:' . base64_encode(str_repeat('k', 16));
        yield 'short key' => [['key' => $key], 'app.key must be 32 bytes for AES-256-CBC; it is 16. Run php xpsr key:generate for a new key.'];
        yield 'raw key too long' => [['key' => str_repeat('k', 33)], 'app.key must be 32 bytes for AES-256-CBC; it is 33.'];
        yield 'not base64' => [['key' => 'base64:%%%'], 'app.key starts with "base64:", but the rest is not base64.'];
        yield 'not a string' => [['key' => 42], 'app.key must be a string'];
        yield 'unknown cipher' => [['key' => $key, 'cipher' => 'DES-CBC'], 'app.cipher must be one of AES-128-CBC, AES-256-CBC, AES-128-GCM, AES-256-GCM.'];
        yield 'previous keys not a list' => [['key' => Encrypter::generateKey(), 'previous_keys' => 'old'], 'app.previous_keys must be a list of keys.'];
        yield 'short previous key' => [['key' => Encrypter::generateKey(), 'previous_keys' => [Encrypter::generateKey(), $key]], 'app.previous_keys[1] must be 32 bytes for AES-256-CBC; it is 16.'];
    }

    /** @param array<string, mixed> $app */
    #[DataProvider('invalidConfiguration')]
    public function testInvalidConfigurationNamesTheSetting(array $app, string $message): void
    {
        $config = new Configuration();
        $config->set('app', $app);
        try {
            Encrypter::fromConfiguration($config);
            self::fail('An encrypter was made from invalid settings.');
        } catch (InvalidArgumentException $exception) {
            self::assertStringContainsString($message, $exception->getMessage());
            $key = $app['key'] ?? null;
            if (is_string($key) && strlen($key) > 8) {
                self::assertStringNotContainsString($key, $exception->getMessage(), 'Keys are never shown.');
            }
        }
    }

    public function testAnApplicationWithoutAKeyStartsAndSaysHowToMakeOne(): void
    {
        $root = sys_get_temp_dir() . '/xpsr-encrypter-' . bin2hex(random_bytes(6));
        mkdir($root);
        try {
            $application = new Application($root);
            $application->getConfig()->set('app.key', '');
            $application->boot();
            try {
                $application->get(Encrypter::class);
                self::fail('An encrypter was made without a key.');
            } catch (ContainerException $exception) {
                self::assertInstanceOf(MissingKeyException::class, $exception->getPrevious());
                self::assertStringContainsString('No encryption key is set: APP_KEY is empty. Run php xpsr key:generate --write to make one.', $exception->getMessage());
            }

            $fresh = new Application($root);
            $fresh->getConfig()->set('app.key', Encrypter::generateKey());
            $encrypter = $fresh->get(Encrypter::class);
            self::assertInstanceOf(Encrypter::class, $encrypter);
            self::assertSame($encrypter, $fresh->get(Encrypter::class), 'Shared.');
        } finally {
            rmdir($root);
        }
    }

    private function encrypter(string $key, string $cipher = 'AES-256-CBC'): Encrypter
    {
        $config = new Configuration();
        $config->set('app', ['key' => $key, 'cipher' => $cipher]);

        return Encrypter::fromConfiguration($config);
    }
}
