<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Tests\Unit;

use Crystoline\Xpsr\Session\Session;
use InvalidArgumentException;
use LogicException;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use stdClass;

final class SessionTest extends TestCase
{
    public function testValuesCanBeSetReadAndRemoved(): void
    {
        $session = new Session();
        self::assertNull($session->storedId());
        self::assertTrue(Session::isValidId($session->id()));
        self::assertTrue($session->isEmpty());

        $session->set('cart', [12, 40]);
        $session->set('nothing', null);
        self::assertSame([12, 40], $session->get('cart'));
        self::assertTrue($session->has('nothing'));
        self::assertNull($session->get('nothing', 'default'), 'A stored null is not the default.');
        self::assertSame('default', $session->get('missing', 'default'));
        self::assertFalse($session->isEmpty());

        $session->remove('cart');
        self::assertSame(['nothing' => null], $session->all());
        $session->clear();
        self::assertSame([], $session->all());
    }

    public function testThePayloadRoundTrips(): void
    {
        $session = new Session();
        $values = ['name' => 'Zoë', 'ratio' => 1.0, 'ids' => [3, 1, 2], 'map' => ['7' => true], 'none' => null, 'slash' => 'a/b'];
        foreach ($values as $key => $value) {
            $session->set($key, $value);
        }
        $token = $session->token();
        $loaded = Session::fromPayload($session->id(), $session->payload());

        self::assertNotNull($loaded);
        self::assertSame($session->id(), $loaded->storedId());
        self::assertSame($values, $loaded->all());
        self::assertTrue($loaded->isValidToken($token));
        self::assertSame(1.0, $loaded->get('ratio'), 'Floats stay floats.');
    }

    public function testAnEmptySessionStoresAnEmptyObject(): void
    {
        $session = new Session();
        $session->token();
        self::assertSame('{"data":{},"flash":[],"token":"' . $session->token() . '"}', $session->payload());
    }

    /** @return iterable<string, array{string}> */
    public static function corruptPayloads(): iterable
    {
        yield 'not JSON' => ['{'];
        yield 'a string' => ['"text"'];
        yield 'no data' => ['{"flash":[]}'];
        yield 'data is not an object' => ['{"data":"x","flash":[]}'];
        yield 'flash is not a list' => ['{"data":{},"flash":"x"}'];
    }

    #[DataProvider('corruptPayloads')]
    public function testCorruptPayloadsAreNotSessions(string $payload): void
    {
        self::assertNull(Session::fromPayload(Session::newId(), $payload));
    }

    public function testAFlashedValueLastsForTheNextRequestOnly(): void
    {
        $first = new Session();
        $first->flash('status', 'Saved.');
        self::assertSame('Saved.', $first->get('status'), 'It is readable at once, too.');

        $second = Session::fromPayload($first->id(), $first->payload());
        self::assertNotNull($second);
        self::assertSame('Saved.', $second->get('status'));

        $third = Session::fromPayload($second->id(), $second->payload());
        self::assertNotNull($third);
        self::assertFalse($third->has('status'));
        self::assertTrue($third->isEmpty());
    }

    public function testAFlashedValueSetAgainIsKept(): void
    {
        $first = new Session();
        $first->flash('kept', 'a');
        $first->flash('reflashed', 'b');
        $second = Session::fromPayload($first->id(), $first->payload());
        self::assertNotNull($second);
        $second->set('kept', 'a');
        $second->flash('reflashed', 'b');

        $third = Session::fromPayload($second->id(), $second->payload());
        self::assertNotNull($third);
        self::assertSame(['kept' => 'a', 'reflashed' => 'b'], $third->all());
        $fourth = Session::fromPayload($third->id(), $third->payload());
        self::assertNotNull($fourth);
        self::assertSame(['kept' => 'a'], $fourth->all(), 'set() made it an ordinary value; the re-flash lasted one more request.');
    }

    public function testRegenerateKeepsValuesAndTheTokenUnderANewId(): void
    {
        $session = Session::fromPayload(Session::newId(), '{"data":{"user_id":7},"flash":[],"token":"abc"}');
        self::assertNotNull($session);
        $before = $session->id();
        $session->regenerate();

        self::assertNotSame($before, $session->id());
        self::assertSame($before, $session->storedId());
        self::assertSame(7, $session->get('user_id'));
        self::assertTrue($session->isValidToken('abc'));
    }

    public function testInvalidateClearsValuesAndToken(): void
    {
        $session = Session::fromPayload(Session::newId(), '{"data":{"user_id":7},"flash":["user_id"],"token":"abc"}');
        self::assertNotNull($session);
        $before = $session->id();
        $session->invalidate();

        self::assertNotSame($before, $session->id());
        self::assertSame([], $session->all());
        self::assertFalse($session->isValidToken('abc'));
        self::assertTrue($session->isEmpty());
    }

    public function testTokensAreRandomAndComparedExactly(): void
    {
        $session = new Session();
        self::assertFalse($session->isValidToken(''), 'A session without a token accepts nothing.');
        $token = $session->token();
        self::assertMatchesRegularExpression('/\A[0-9a-f]{64}\z/', $token);
        self::assertSame($token, $session->token());
        self::assertNotSame($token, (new Session())->token());
        self::assertTrue($session->isValidToken($token));
        self::assertFalse($session->isValidToken(strtoupper($token)));
        self::assertFalse($session->isValidToken(''));
    }

    /** @return iterable<string, array{mixed, string}> */
    public static function unstorable(): iterable
    {
        yield 'an object' => [new stdClass(), 'stdClass'];
        yield 'an object in an array' => [['user' => ['profile' => new stdClass()]], 'stdClass'];
        yield 'a closure' => [static fn() => 1, 'Closure'];
        yield 'INF' => [INF, 'INF or NAN'];
        yield 'invalid UTF-8' => ["\xB1\x31", 'UTF-8'];
    }

    #[DataProvider('unstorable')]
    public function testOnlyJsonDataCanBeStored(mixed $value, string $message): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message);
        (new Session())->set('value', $value);
    }

    public function testIdsHaveOneForm(): void
    {
        self::assertTrue(Session::isValidId(str_repeat('a1', 20)));
        foreach (['', str_repeat('a', 39), str_repeat('A', 40), '../../../../etc/passwd', str_repeat('a', 40) . "\n"] as $id) {
            self::assertFalse(Session::isValidId($id), $id);
        }
    }

    public function testARequestWithoutTheMiddlewareHasNoSession(): void
    {
        $session = new Session();
        self::assertSame($session, Session::of((new ServerRequest('GET', '/'))->withAttribute(Session::class, $session)));

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('SessionMiddleware');
        Session::of(new ServerRequest('GET', '/'));
    }
}
