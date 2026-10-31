<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Tests\Unit;

use Closure;
use Crystoline\Xpsr\Testing\TestResponse;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\Assert;
use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class TestResponseTest extends TestCase
{
    public function testItIsTheResponseAndReadsItsJson(): void
    {
        $response = self::json(201, ['data' => ['id' => 7, 'tags' => ['a', 'b']], 'ok' => true]);
        self::assertSame(201, $response->getStatusCode());
        self::assertSame(201, $response->status());
        self::assertSame('application/json', $response->getHeaderLine('Content-Type'));
        self::assertSame('{"data":{"id":7,"tags":["a","b"]},"ok":true}', $response->body());
        self::assertSame('{"data":{"id":7,"tags":["a","b"]},"ok":true}', (string) $response->getBody());
        self::assertSame(['data' => ['id' => 7, 'tags' => ['a', 'b']], 'ok' => true], $response->json());
        self::assertSame(7, $response->json('data.id'));
        self::assertSame('b', $response->json('data.tags.1'));
        self::assertTrue($response->json('ok'));
        self::assertInstanceOf(Response::class, $response->response());

        $changed = $response->withHeader('X-Test', 'yes')->withStatus(202);
        self::assertInstanceOf(TestResponse::class, $changed, 'Changed copies are test responses too.');
        self::assertSame('yes', $changed->getHeaderLine('X-Test'));
        self::assertSame(202, $changed->status());
        self::assertSame(201, $response->status(), 'The original is unchanged.');
    }

    public function testAssertionsPassAndChain(): void
    {
        self::json(201, ['data' => ['id' => 7, 'tags' => ['a', 'b'], 'nested' => ['deep' => null]], 'ok' => true])
            ->assertCreated()
            ->assertStatus(201)
            ->assertHeader('Content-Type')
            ->assertHeader('Content-Type', 'application/json')
            ->assertHeaderMissing('Location')
            ->assertJson(['ok' => true])
            ->assertJson(['data' => ['id' => 7, 'nested' => ['deep' => null]]])
            ->assertJson(['data' => ['tags' => [1 => 'b']]])
            ->assertJsonPath('data.id', 7)
            ->assertJsonPath('data.nested.deep', null)
            ->assertJsonPath('data.tags', static fn(array $tags): bool => count($tags) === 2)
            ->assertJsonCount(2, 'data.tags')
            ->assertJsonCount(2)
            ->assertJsonMissingPath('data.name')
            ->assertJsonMissingPath('data.id.more')
            ->assertExactJson(['data' => ['id' => 7, 'tags' => ['a', 'b'], 'nested' => ['deep' => null]], 'ok' => true])
            ->assertSee('"id":7')
            ->assertDontSee('name');
        self::json(200, ['a'])->assertOk();
        self::json(401, [])->assertUnauthorized();
        self::json(403, [])->assertForbidden();
        self::json(404, [])->assertNotFound();
        self::response(204)->assertNoContent();
        self::response(302, ['Location' => 'http://localhost/login?next=%2Fhome'])
            ->assertRedirect()
            ->assertRedirect('/login')
            ->assertRedirect('http://localhost/login?next=%2Fhome');
        self::response(200, [], 'Hello, Ada!')->assertSee('Ada')->assertDontSee('Bob');
        self::json(422, ['message' => 'The given data is invalid.', 'errors' => ['title' => ['This value should not be blank.'], 'amount' => ['Must be positive.', 'Too large.']]])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('title')
            ->assertJsonValidationErrors(['title', 'amount' => 'Too large'])
            ->assertJsonValidationErrors(['amount' => 'positive']);
    }

    /** @param Closure(): mixed $assertion */
    #[DataProvider('failures')]
    public function testAFailureSaysWhatDiffersAndShowsTheBody(Closure $assertion, string $message): void
    {
        try {
            $assertion();
        } catch (AssertionFailedError $exception) {
            self::assertStringContainsString($message, $exception->getMessage());

            return;
        }
        Assert::fail('The assertion passed.');
    }

    /** @return iterable<string, array{Closure(): mixed, string}> */
    public static function failures(): iterable
    {
        yield 'status, with the body' => [static fn() => self::json(500, ['message' => 'Boom'])->assertOk(), "Expected the status 200, got 500.\nStatus 500, body: {\"message\":\"Boom\"}"];
        yield 'status, empty body' => [static fn() => self::response(500)->assertOk(), 'Expected the status 200, got 500. The body is empty.'];
        yield 'a long body is cut' => [static fn() => self::response(500, [], str_repeat('x', 1200))->assertOk(), str_repeat('x', 1000) . '…'];
        yield 'no content' => [static fn() => self::response(204, [], 'x')->assertNoContent(), 'A 204 response has no body.'];
        yield 'not a redirect' => [static fn() => self::response(200)->assertRedirect(), 'Expected a redirect, got the status 200.'];
        yield 'redirect elsewhere' => [static fn() => self::response(302, ['Location' => '/home'])->assertRedirect('/login'), 'Expected a redirect to /login, got /home.'];
        yield 'redirect without location' => [static fn() => self::response(302)->assertRedirect(), 'The redirect has no Location header.'];
        yield 'header missing' => [static fn() => self::response(200)->assertHeader('X-Missing'), 'The response has no X-Missing header.'];
        yield 'header differs' => [static fn() => self::response(200, ['X-Test' => 'a'])->assertHeader('X-Test', 'b'), 'The X-Test header differs.'];
        yield 'header present' => [static fn() => self::response(200, ['X-Test' => 'a'])->assertHeaderMissing('X-Test'), 'The response has a X-Test header: a'];
        yield 'see' => [static fn() => self::response(200, [], 'Hello')->assertSee('Bye'), 'The body does not contain "Bye".'];
        yield 'dont see' => [static fn() => self::response(200, [], 'Hello')->assertDontSee('Hello'), 'The body contains "Hello".'];
        yield 'not json' => [static fn() => self::response(200, [], 'nope')->assertJson(['a' => 1]), 'The body is not JSON (Syntax error).'];
        yield 'json path missing' => [static fn() => self::json(200, ['a' => 1])->assertJsonPath('b', 1), 'The JSON body has nothing at "b".'];
        yield 'json path differs' => [static fn() => self::json(200, ['a' => 1])->assertJsonPath('a', 2), 'The value at "a" differs.'];
        yield 'json path check' => [static fn() => self::json(200, ['a' => 1])->assertJsonPath('a', static fn(int $value): bool => $value > 1), 'The value at "a" did not pass the check: 1'];
        yield 'json path present' => [static fn() => self::json(200, ['a' => 1])->assertJsonMissingPath('a'), 'The JSON body has a value at "a".'];
        yield 'subset key missing' => [static fn() => self::json(200, ['a' => 1])->assertJson(['x' => 1]), 'The JSON body has nothing at "x". It has: a.'];
        yield 'subset value differs' => [static fn() => self::json(200, ['a' => ['b' => 1, 'c' => 3]])->assertJson(['a' => ['b' => 2]]), 'The value at "a.b" differs.'];
        yield 'exact json' => [static fn() => self::json(200, ['a' => 1, 'b' => 2])->assertExactJson(['a' => 1]), 'The JSON body differs.'];
        yield 'count' => [static fn() => self::json(200, ['a', 'b'])->assertJsonCount(3), 'The JSON body does not have 3 items.'];
        yield 'count at a path' => [static fn() => self::json(200, ['items' => 'none'])->assertJsonCount(1, 'items'), 'The value at "items" is not an array.'];
        yield 'validation status' => [static fn() => self::json(200, ['errors' => []])->assertJsonValidationErrors('title'), 'Expected the status 422, got 200.'];
        yield 'validation errors missing' => [static fn() => self::json(422, ['message' => 'Invalid.'])->assertJsonValidationErrors('title'), 'The response has no "errors" object.'];
        yield 'validation field' => [static fn() => self::json(422, ['errors' => ['title' => ['Blank.']]])->assertJsonValidationErrors('amount'), 'There is no validation error for "amount". Fields with errors: title.'];
        yield 'validation message' => [static fn() => self::json(422, ['errors' => ['title' => ['Blank.', 'Short.']]])->assertJsonValidationErrors(['title' => 'required']), 'No validation error for "title" contains "required". Its messages: Blank. | Short.'];
    }

    /** @param array<string, string> $headers */
    private static function response(int $status, array $headers = [], string $body = ''): TestResponse
    {
        return new TestResponse(new Response($status, $headers, $body));
    }

    /** @param array<array-key, mixed> $data */
    private static function json(int $status, array $data): TestResponse
    {
        return self::response($status, ['Content-Type' => 'application/json'], json_encode($data, JSON_THROW_ON_ERROR));
    }
}
