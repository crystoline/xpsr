<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Testing;

use Closure;
use JsonException;
use PHPUnit\Framework\Assert;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;

/**
 * A response from the HTTP kernel in a test, with assertions on its status, headers, and body.
 * It is the PSR-7 response as well, so getStatusCode(), getHeaderLine(), and getBody() work as
 * before. Each assertion returns the response, so they chain:
 *
 *     $this->postJson('/payments', [...])
 *         ->assertCreated()
 *         ->assertJsonPath('status', 'pending')
 *         ->assertJson(['currency' => 'NGN']);
 *
 * A failed assertion shows the status and the start of the body.
 */
final class TestResponse implements ResponseInterface
{
    private const PATH_MISSING = "\0missing";

    public function __construct(private readonly ResponseInterface $response) {}

    /** The PSR-7 response itself. */
    public function response(): ResponseInterface
    {
        return $this->response;
    }

    public function status(): int
    {
        return $this->response->getStatusCode();
    }

    public function body(): string
    {
        return (string) $this->response->getBody();
    }

    /**
     * The JSON body, decoded with objects as arrays; or the value at a path in it, such as
     * "data.0.id". Fails the test when the body is not JSON, or has nothing at the path.
     */
    public function json(?string $path = null): mixed
    {
        $json = $this->decoded();
        if ($path === null) {
            return $json;
        }
        $value = self::at($json, $path);
        Assert::assertNotSame(self::PATH_MISSING, $value, "The JSON body has nothing at \"{$path}\"." . $this->excerpt());

        return $value;
    }

    public function assertStatus(int $status): self
    {
        Assert::assertSame($status, $this->status(), "Expected the status {$status}, got {$this->status()}." . $this->excerpt());

        return $this;
    }

    public function assertOk(): self
    {
        return $this->assertStatus(200);
    }

    public function assertCreated(): self
    {
        return $this->assertStatus(201);
    }

    public function assertNoContent(): self
    {
        $this->assertStatus(204);
        Assert::assertSame('', $this->body(), 'A 204 response has no body.');

        return $this;
    }

    public function assertNotFound(): self
    {
        return $this->assertStatus(404);
    }

    public function assertUnauthorized(): self
    {
        return $this->assertStatus(401);
    }

    public function assertForbidden(): self
    {
        return $this->assertStatus(403);
    }

    /** 422: the input failed validation. */
    public function assertUnprocessable(): self
    {
        return $this->assertStatus(422);
    }

    /** A redirect (3xx), to $to when given: a full URL, or a path such as "/login". */
    public function assertRedirect(?string $to = null): self
    {
        $status = $this->status();
        Assert::assertTrue($status >= 300 && $status < 400, "Expected a redirect, got the status {$status}." . $this->excerpt());
        $location = $this->response->getHeaderLine('Location');
        Assert::assertNotSame('', $location, 'The redirect has no Location header.');
        if ($to !== null) {
            Assert::assertTrue($location === $to || (str_starts_with($to, '/') && (string) parse_url($location, PHP_URL_PATH) === $to), "Expected a redirect to {$to}, got {$location}.");
        }

        return $this;
    }

    /** The header is present, with this value when given (the values of a repeated header joined by ", "). */
    public function assertHeader(string $name, ?string $value = null): self
    {
        Assert::assertTrue($this->response->hasHeader($name), "The response has no {$name} header." . $this->excerpt());
        if ($value !== null) {
            Assert::assertSame($value, $this->response->getHeaderLine($name), "The {$name} header differs.");
        }

        return $this;
    }

    public function assertHeaderMissing(string $name): self
    {
        Assert::assertFalse($this->response->hasHeader($name), "The response has a {$name} header: " . $this->response->getHeaderLine($name));

        return $this;
    }

    /** The body contains this text, for HTML and plain text responses. */
    public function assertSee(string $text): self
    {
        Assert::assertStringContainsString($text, $this->body(), "The body does not contain \"{$text}\"." . $this->excerpt());

        return $this;
    }

    public function assertDontSee(string $text): self
    {
        Assert::assertStringNotContainsString($text, $this->body(), "The body contains \"{$text}\".");

        return $this;
    }

    /**
     * The JSON body has each of these keys with these values; nested arrays are checked the same
     * way, so other keys in the body, at any level, are allowed.
     *
     * @param array<array-key, mixed> $subset
     */
    public function assertJson(array $subset): self
    {
        $json = $this->decoded();
        Assert::assertIsArray($json, 'The JSON body is not an array or object.' . $this->excerpt());
        self::assertSubset($subset, $json, '');

        return $this;
    }

    /** @param array<array-key, mixed> $expected */
    public function assertExactJson(array $expected): self
    {
        Assert::assertSame($expected, $this->decoded(), 'The JSON body differs.' . $this->excerpt());

        return $this;
    }

    /**
     * The value at a path in the JSON body, such as "data.0.id", is $expected; or, with a
     * closure, the closure returns true for it.
     */
    public function assertJsonPath(string $path, mixed $expected): self
    {
        $value = $this->json($path);
        if ($expected instanceof Closure) {
            Assert::assertTrue((bool) $expected($value), "The value at \"{$path}\" did not pass the check: " . self::export($value));
        } else {
            Assert::assertSame($expected, $value, "The value at \"{$path}\" differs." . $this->excerpt());
        }

        return $this;
    }

    public function assertJsonMissingPath(string $path): self
    {
        Assert::assertSame(self::PATH_MISSING, self::at($this->decoded(), $path), "The JSON body has a value at \"{$path}\"." . $this->excerpt());

        return $this;
    }

    /** The JSON body, or the array at a path in it, has this many items. */
    public function assertJsonCount(int $count, ?string $path = null): self
    {
        $value = $this->json($path);
        Assert::assertIsArray($value, ($path === null ? 'The JSON body' : "The value at \"{$path}\"") . ' is not an array.' . $this->excerpt());
        Assert::assertCount($count, $value, ($path === null ? 'The JSON body' : "The value at \"{$path}\"") . " does not have {$count} items." . $this->excerpt());

        return $this;
    }

    /**
     * A 422 whose "errors" name each of these fields: ['title', 'amount'], or, to check the
     * message as well, ['amount' => 'should be positive'] (a part of the message is enough).
     *
     * @param string|array<array-key, string> $errors
     */
    public function assertJsonValidationErrors(string|array $errors): self
    {
        $this->assertStatus(422);
        $actual = self::at($this->decoded(), 'errors');
        Assert::assertIsArray($actual, 'The response has no "errors" object.' . $this->excerpt());
        foreach ((array) $errors as $field => $message) {
            if (is_int($field)) {
                [$field, $message] = [$message, null];
            }
            Assert::assertArrayHasKey($field, $actual, "There is no validation error for \"{$field}\". Fields with errors: " . implode(', ', array_map(strval(...), array_keys($actual))) . '.');
            if ($message !== null) {
                $messages = is_array($actual[$field]) ? array_map(strval(...), $actual[$field]) : [(string) $actual[$field]];
                Assert::assertTrue(
                    array_any($messages, static fn(string $actualMessage): bool => str_contains($actualMessage, $message)),
                    "No validation error for \"{$field}\" contains \"{$message}\". Its messages: " . implode(' | ', $messages),
                );
            }
        }

        return $this;
    }

    public function getProtocolVersion(): string
    {
        return $this->response->getProtocolVersion();
    }

    public function withProtocolVersion(string $version): static
    {
        return new self($this->response->withProtocolVersion($version));
    }

    public function getHeaders(): array
    {
        return $this->response->getHeaders();
    }

    public function hasHeader(string $name): bool
    {
        return $this->response->hasHeader($name);
    }

    public function getHeader(string $name): array
    {
        return $this->response->getHeader($name);
    }

    public function getHeaderLine(string $name): string
    {
        return $this->response->getHeaderLine($name);
    }

    public function withHeader(string $name, $value): static
    {
        return new self($this->response->withHeader($name, $value));
    }

    public function withAddedHeader(string $name, $value): static
    {
        return new self($this->response->withAddedHeader($name, $value));
    }

    public function withoutHeader(string $name): static
    {
        return new self($this->response->withoutHeader($name));
    }

    public function getBody(): StreamInterface
    {
        return $this->response->getBody();
    }

    public function withBody(StreamInterface $body): static
    {
        return new self($this->response->withBody($body));
    }

    public function getStatusCode(): int
    {
        return $this->response->getStatusCode();
    }

    public function withStatus(int $code, string $reasonPhrase = ''): static
    {
        return new self($this->response->withStatus($code, $reasonPhrase));
    }

    public function getReasonPhrase(): string
    {
        return $this->response->getReasonPhrase();
    }

    private function decoded(): mixed
    {
        $body = $this->body();
        try {
            return json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            Assert::fail('The body is not JSON (' . $exception->getMessage() . ').' . $this->excerpt());
        }
    }

    /** The value at a dot path, or PATH_MISSING. */
    private static function at(mixed $value, string $path): mixed
    {
        foreach (explode('.', $path) as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return self::PATH_MISSING;
            }
            $value = $value[$segment];
        }

        return $value;
    }

    /**
     * @param array<array-key, mixed> $subset
     * @param array<array-key, mixed> $actual
     */
    private static function assertSubset(array $subset, array $actual, string $path): void
    {
        foreach ($subset as $key => $expected) {
            $at = $path === '' ? (string) $key : "{$path}.{$key}";
            Assert::assertArrayHasKey($key, $actual, "The JSON body has nothing at \"{$at}\". It has: " . implode(', ', array_map(strval(...), array_keys($actual))) . '.');
            if (is_array($expected) && is_array($actual[$key])) {
                self::assertSubset($expected, $actual[$key], $at);
            } else {
                Assert::assertSame($expected, $actual[$key], "The value at \"{$at}\" differs.");
            }
        }
    }

    private function excerpt(): string
    {
        $body = $this->body();
        if ($body === '') {
            return ' The body is empty.';
        }

        return "\nStatus {$this->status()}, body: " . (strlen($body) > 1000 ? substr($body, 0, 1000) . '…' : $body);
    }

    private static function export(mixed $value): string
    {
        return is_scalar($value) || $value === null ? var_export($value, true) : (string) json_encode($value);
    }
}
