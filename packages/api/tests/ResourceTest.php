<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Api\Tests;

use Crystoline\Xpsr\Api\ApiOptions;
use Crystoline\Xpsr\Api\Pagination\Page;
use Crystoline\Xpsr\Api\Resource;
use Crystoline\Xpsr\Api\ResourceCollection;
use Crystoline\Xpsr\Api\Tests\Fixtures\Container;
use Crystoline\Xpsr\Api\Tests\Fixtures\Customer;
use Crystoline\Xpsr\Api\Tests\Fixtures\CustomerResource;
use Crystoline\Xpsr\Api\Tests\Fixtures\Order;
use Crystoline\Xpsr\Api\Tests\Fixtures\OrderResource;
use LogicException;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Uri;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use ReflectionClass;

/** Resources and collections: fields, conditions, relations, envelopes, and responses. */
final class ResourceTest extends TestCase
{
    private function request(bool $admin = false): ServerRequestInterface
    {
        $request = (new Psr17Factory())->createServerRequest('GET', 'https://api.test/orders');

        return $admin ? $request->withAttribute('admin', true) : $request;
    }

    public function testFieldsConditionsMergesAndNesting(): void
    {
        $order = new Order(7, 'ORD-7', 1250, paid: true);
        $order->customer = new Customer(3, 'Ada');
        $order->related = [new Order(8, 'ORD-8', 10)];
        $order->related[0]->customer = null;
        $order->related[0]->related = [];

        self::assertSame([
            'id' => 7,
            'reference' => 'ORD-7',
            'amount' => 1250,
            'computed' => 'called',
            'paid' => true,
            'paid_at' => '2026-11-01',
            'customer' => ['id' => 3, 'name' => 'Ada'],
            'customer_name' => 'ADA',
            'related' => [['id' => 8, 'reference' => 'ORD-8', 'amount' => 10, 'computed' => 'called', 'customer' => null, 'customer_name' => null, 'related' => [], 'tags' => ['a', 'c']]],
            'tags' => ['a', 'c'],
        ], (new OrderResource($order))->resolve($this->request(admin: true)), 'Missing values are dropped, merges spread, lists reindexed, and nested resources rendered.');
    }

    public function testRelationsThatAreNotLoadedAreLeftOut(): void
    {
        $order = new Order(1, 'ORD-1', 100);
        $fields = (new OrderResource($order))->resolve($this->request());
        self::assertArrayNotHasKey('customer', $fields, 'An uninitialized property is not loaded.');
        self::assertArrayNotHasKey('customer_name', $fields);
        self::assertArrayNotHasKey('related', $fields, 'A collection of an unloaded relation is left out too.');
        self::assertArrayNotHasKey('amount', $fields, 'The request is not an admin.');
        self::assertArrayNotHasKey('note', $fields);

        $ghost = (new ReflectionClass(Customer::class))->newLazyGhost(static function (Customer $customer): void {
            $customer->__construct(5, 'Grace');
        });
        $order->customer = $ghost;
        self::assertArrayNotHasKey('customer', (new OrderResource($order))->resolve($this->request()), 'A lazy object that has not been initialized is not loaded.');
        self::assertSame('Grace', $ghost->name);
        self::assertSame(['id' => 5, 'name' => 'Grace'], (new OrderResource($order))->resolve($this->request())['customer']);
    }

    public function testFieldsOfArraysAndObjects(): void
    {
        $resource = new CustomerResource(['id' => 1, 'name' => 'Ada']);
        self::assertSame(['id' => 1, 'name' => 'Ada'], $resource->resolve($this->request()));
        self::assertTrue(isset($resource->name));
        self::assertFalse(isset($resource->email));
        self::assertNull($resource->email);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('wraps int, which has no field "id"');
        (new CustomerResource(1))->resolve($this->request());
    }

    public function testTheOutputIsWrappedUnlessToldOtherwise(): void
    {
        $resource = new CustomerResource(new Customer(1, 'Ada'));
        self::assertSame(['data' => ['id' => 1, 'name' => 'Ada']], $resource->output($this->request()));
        self::assertSame(['id' => 1, 'name' => 'Ada'], $resource->output($this->request(), null));
        self::assertSame(['customer' => ['id' => 1, 'name' => 'Ada'], 'meta' => ['version' => 2]], $resource->withMeta(['version' => 2])->output($this->request(), 'customer'));
    }

    public function testAResourceBecomesAResponseWithTheConfiguredEnvelope(): void
    {
        $resource = new CustomerResource(new Customer(1, 'Ada'))->withStatus(201)->withHeaders(['Location' => '/customers/1']);

        $response = $resource->toResponse($this->request(), new Container());
        self::assertSame(201, $response->getStatusCode());
        self::assertSame('application/json', $response->getHeaderLine('Content-Type'));
        self::assertSame('/customers/1', $response->getHeaderLine('Location'));
        self::assertSame('{"data":{"id":1,"name":"Ada"}}', (string) $response->getBody(), 'Without options, the envelope is "data".');

        $container = new Container([ApiOptions::class => new ApiOptions(wrap: null)]);
        self::assertSame('{"id":1,"name":"Ada"}', (string) $resource->toResponse($this->request(), $container)->getBody(), 'api.wrap null sends the fields bare.');
        self::assertSame('{"customer":{"id":1,"name":"Ada"}}', (string) $resource->wrap('customer')->toResponse($this->request(), $container)->getBody(), 'wrap() wins over the configuration.');
    }

    public function testACollectionIsAListOfResources(): void
    {
        $customers = [new Customer(1, 'Ada'), new Customer(2, 'Grace')];
        $collection = CustomerResource::collection($customers);

        self::assertSame(['data' => [['id' => 1, 'name' => 'Ada'], ['id' => 2, 'name' => 'Grace']]], $collection->output($this->request()));
        self::assertSame([['id' => 1, 'name' => 'Ada'], ['id' => 2, 'name' => 'Grace']], $collection->output($this->request(), null), 'Without an envelope, the list itself.');
        self::assertSame(
            ['data' => [['id' => 1, 'name' => 'Ada'], ['id' => 2, 'name' => 'Grace']], 'meta' => ['count' => 2]],
            CustomerResource::collection(new \ArrayIterator($customers))->withMeta(['count' => 2])->output($this->request(), null),
            'Meta needs the envelope, so the list goes under "data" even without one.',
        );
        self::assertSame('{"data":[]}', (string) CustomerResource::collection([])->toResponse($this->request(), new Container())->getBody());
    }

    public function testACollectionSubclassCanShapeTheWholeBody(): void
    {
        $collection = new class ([new Customer(1, 'Ada')]) extends ResourceCollection {
            protected ?string $collects = CustomerResource::class;

            public function toArray(ServerRequestInterface $request): array
            {
                return ['customers' => $this->items(), 'count' => count($this->items())];
            }
        };

        self::assertSame(['customers' => [['id' => 1, 'name' => 'Ada']], 'count' => 1], $collection->output($this->request()), 'An associative array is the body, not wrapped.');
    }

    public function testACollectionFromAPageAddsLinksAndMeta(): void
    {
        $page = new Page([new Customer(3, 'Ada'), new Customer(4, 'Grace')], 2, 2, 5, true, new Uri('https://api.test/customers?page=2&sort=name'));

        $output = CustomerResource::collection($page)->withMeta(['sort' => 'name'])->output($this->request(), null);
        self::assertSame([['id' => 3, 'name' => 'Ada'], ['id' => 4, 'name' => 'Grace']], $output['data'], 'A page is always wrapped.');
        self::assertSame([
            'first' => 'https://api.test/customers?page=1&sort=name',
            'last' => 'https://api.test/customers?page=3&sort=name',
            'prev' => 'https://api.test/customers?page=1&sort=name',
            'next' => 'https://api.test/customers?page=3&sort=name',
        ], $output['links']);
        self::assertSame(
            ['current_page' => 2, 'from' => 3, 'to' => 4, 'per_page' => 2, 'path' => 'https://api.test/customers', 'total' => 5, 'last_page' => 3, 'sort' => 'name'],
            $output['meta'],
            'withMeta() joins the pagination meta.',
        );
        self::assertSame(['items', 'links', 'meta'], array_keys(CustomerResource::collection($page)->output($this->request(), 'items')), 'The envelope key applies to a page too.');
    }

    public function testAResourceSeesTheRequest(): void
    {
        $resource = new class (new Customer(1, 'Ada')) extends Resource {
            public function toArray(ServerRequestInterface $request): array
            {
                return ['name' => $this->resource->name, 'url' => (string) $request->getUri()];
            }
        };
        self::assertSame(['name' => 'Ada', 'url' => 'https://api.test/orders'], $resource->resolve($this->request()));
        self::assertSame(['data' => null], new CustomerResource(null)->output($this->request()), 'A resource of null is null.');
    }
}
