<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Session;

use InvalidArgumentException;
use JsonException;
use LogicException;
use Psr\Http\Message\ServerRequestInterface;

/**
 * The session of one browser, for requests that passed through SessionMiddleware:
 *
 *     $session = Session::of($request);
 *     $session->set('cart', [12, 40]);
 *     $session->flash('status', 'Task saved.');   // readable in the next request only
 *
 * Values must be JSON data: null, booleans, numbers, strings, and arrays of these. Objects are
 * refused, so stored sessions never contain serialized PHP objects.
 *
 * After a login, call regenerate() so that an ID an attacker planted before the login is useless.
 * On logout, call invalidate().
 */
final class Session
{
    private const ID = '/\A[0-9a-f]{40}\z/';

    private string $id;

    /** @var list<string> keys flashed by the previous request, removed at the end of this one */
    private array $flashedBefore;

    /** @var list<string> keys flashed in this request, kept for the next */
    private array $flashedNow = [];

    /**
     * @internal Sessions are created by SessionManager.
     *
     * @param string|null $storedId the ID it was loaded with; null for a new session
     * @param array<string, mixed> $data
     * @param list<string> $flashed keys flashed by the previous request
     */
    public function __construct(
        private readonly ?string $storedId = null,
        private array $data = [],
        array $flashed = [],
        private ?string $token = null,
    ) {
        $this->id = $storedId ?? self::newId();
        $this->flashedBefore = array_values(array_intersect($flashed, array_keys($data)));
    }

    /** The session of a request; SessionMiddleware must have run for it. */
    public static function of(ServerRequestInterface $request): self
    {
        $session = $request->getAttribute(self::class);

        return $session instanceof self
            ? $session
            : throw new LogicException('This request has no session. Add ' . SessionMiddleware::class . ' to its route group or to app.middleware.');
    }

    /** A random session ID: 160 bits, as 40 hexadecimal characters. */
    public static function newId(): string
    {
        return bin2hex(random_bytes(20));
    }

    /** Whether a string has the form of a session ID; anything else from a cookie is ignored. */
    public static function isValidId(string $id): bool
    {
        return preg_match(self::ID, $id) === 1;
    }

    public function id(): string
    {
        return $this->id;
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->data);
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return array_key_exists($key, $this->data) ? $this->data[$key] : $default;
    }

    /** @return array<string, mixed> */
    public function all(): array
    {
        return $this->data;
    }

    /** Store a value until it is removed or the session ends. A flashed value set again is kept. */
    public function set(string $key, mixed $value): void
    {
        self::assertStorable($key, $value);
        $this->data[$key] = $value;
        $this->flashedBefore = array_values(array_diff($this->flashedBefore, [$key]));
    }

    /** Store a value for the next request only, such as a message to show after a redirect. */
    public function flash(string $key, mixed $value): void
    {
        $this->set($key, $value);
        if (!in_array($key, $this->flashedNow, true)) {
            $this->flashedNow[] = $key;
        }
    }

    public function remove(string $key): void
    {
        unset($this->data[$key]);
        $this->flashedBefore = array_values(array_diff($this->flashedBefore, [$key]));
        $this->flashedNow = array_values(array_diff($this->flashedNow, [$key]));
    }

    /** Remove every value. The ID and the CSRF token stay. */
    public function clear(): void
    {
        $this->data = $this->flashedBefore = $this->flashedNow = [];
    }

    /** Move the session to a new ID, keeping its values; the old ID stops working. Call after a login. */
    public function regenerate(): void
    {
        $this->id = self::newId();
    }

    /** Remove every value and the CSRF token, and move to a new ID. Call on logout. */
    public function invalidate(): void
    {
        $this->clear();
        $this->token = null;
        $this->regenerate();
    }

    /** The CSRF token of this session, created on first use. */
    public function token(): string
    {
        return $this->token ??= bin2hex(random_bytes(32));
    }

    /** Whether a submitted CSRF token is this session's, compared in constant time. */
    public function isValidToken(string $token): bool
    {
        return $this->token !== null && hash_equals($this->token, $token);
    }

    /** @internal The ID the session was loaded with; null for a new session. */
    public function storedId(): ?string
    {
        return $this->storedId;
    }

    /** @internal Whether there is nothing to store: no values and no token. */
    public function isEmpty(): bool
    {
        return $this->remaining() === [] && $this->token === null;
    }

    /** @internal What the store keeps: the values (without the previous request's flashes), flashed keys, and token. */
    public function payload(): string
    {
        return json_encode(
            ['data' => (object) $this->remaining(), 'flash' => $this->flashedNow, 'token' => $this->token],
            JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        );
    }

    /** @internal A session from a stored payload, or null when the payload is not one. */
    public static function fromPayload(string $id, string $payload): ?self
    {
        try {
            $decoded = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }
        if (!is_array($decoded) || !is_array($decoded['data'] ?? null) || !is_array($decoded['flash'] ?? null)) {
            return null;
        }
        $token = $decoded['token'] ?? null;
        $flash = array_values(array_filter($decoded['flash'], is_string(...)));

        return new self($id, $decoded['data'], $flash, is_string($token) ? $token : null);
    }

    /** @return array<string, mixed> */
    private function remaining(): array
    {
        return array_diff_key($this->data, array_flip(array_diff($this->flashedBefore, $this->flashedNow)));
    }

    private static function assertStorable(string $key, mixed $value): void
    {
        if ($key === '') {
            throw new InvalidArgumentException('A session key must not be empty.');
        }
        $check = static function (mixed $value) use (&$check, $key): void {
            if (is_array($value)) {
                array_walk($value, static fn(mixed $item) => $check($item));

                return;
            }
            if (is_float($value) && !is_finite($value)) {
                throw new InvalidArgumentException("The session value for \"{$key}\" contains INF or NAN, which cannot be stored.");
            }
            if ($value !== null && !is_scalar($value)) {
                throw new InvalidArgumentException("The session value for \"{$key}\" contains " . get_debug_type($value) . '. Sessions store JSON data only: null, booleans, numbers, strings, and arrays; store an ID instead of an object.');
            }
        };
        $check($value);
        try {
            json_encode($value, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new InvalidArgumentException("The session value for \"{$key}\" contains text that is not valid UTF-8.");
        }
    }
}
