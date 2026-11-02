<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Api\Tests;

use Crystoline\Xpsr\Api\ApiOptions;
use Crystoline\Xpsr\Api\Pagination\ArrayPaginator;
use Crystoline\Xpsr\Api\Pagination\Page;
use Crystoline\Xpsr\Api\Pagination\Pagination;
use Crystoline\Xpsr\Api\Tests\Fixtures\Container;
use Crystoline\Xpsr\Api\Tests\Fixtures\Customer;
use Crystoline\Xpsr\Api\Tests\Fixtures\CustomerResource;
use InvalidArgumentException;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;

/** Reading the page from the request, and paging arrays; the query paginators have their own test. */
final class PaginationTest extends TestCase
{
    private Pagination $pagination;

    protected function setUp(): void
    {
        $this->pagination = new Pagination([new ArrayPaginator()]);
    }

    private function request(string $query = ''): ServerRequestInterface
    {
        $request = (new Psr17Factory())->createServerRequest('GET', 'https://api.test/payments' . ($query === '' ? '' : "?{$query}"));
        parse_str($query, $params);

        return $request->withQueryParams($params);
    }

    /** @return iterable<string, array{string, int, int}> */
    public static function requests(): iterable
    {
        yield 'defaults' => ['', 1, 15];
        yield 'page and size' => ['page=3&per_page=5', 3, 5];
        yield 'too many' => ['per_page=1000', 1, 100];
        yield 'not numbers' => ['page=abc&per_page=-1', 1, 15];
        yield 'zero' => ['page=0&per_page=0', 1, 15];
        yield 'decimal' => ['page=2.5&per_page=5.5', 1, 15];
    }

    #[DataProvider('requests')]
    public function testThePageComesFromTheRequest(string $query, int $page, int $perPage): void
    {
        $request = $this->pagination->fromRequest($this->request($query));

        self::assertSame($page, $request->page);
        self::assertSame($perPage, $request->perPage);
        self::assertSame(($page - 1) * $perPage, $request->offset());
    }

    public function testTheDefaultsComeFromTheOptionsOrTheCall(): void
    {
        $pagination = new Pagination([new ArrayPaginator()], new ApiOptions(perPage: 4, maxPerPage: 8));
        self::assertSame(4, $pagination->fromRequest($this->request())->perPage);
        self::assertSame(8, $pagination->fromRequest($this->request('per_page=50'))->perPage);
        self::assertSame(6, $pagination->fromRequest($this->request(), perPage: 6)->perPage);
        self::assertSame(20, $pagination->fromRequest($this->request('per_page=50'), max: 20)->perPage);
        self::assertSame(4, $pagination->page(2)->perPage);

        $this->expectException(InvalidArgumentException::class);
        $pagination->page(0);
    }

    public function testAPageWithTheTotal(): void
    {
        $page = $this->pagination->fromRequest($this->request('status=paid&page=2&per_page=10'))->paginate(range(1, 23));

        self::assertSame(range(11, 20), $page->items);
        self::assertSame(23, $page->total);
        self::assertSame(3, $page->lastPage());
        self::assertTrue($page->hasMore);
        self::assertSame(11, $page->from());
        self::assertSame(20, $page->to());
        self::assertCount(10, $page);
        self::assertSame(range(11, 20), iterator_to_array($page));
        self::assertSame([
            'first' => 'https://api.test/payments?status=paid&page=1&per_page=10',
            'last' => 'https://api.test/payments?status=paid&page=3&per_page=10',
            'prev' => 'https://api.test/payments?status=paid&page=1&per_page=10',
            'next' => 'https://api.test/payments?status=paid&page=3&per_page=10',
        ], $page->links(), 'Links keep the other parameters and replace page in place.');
        self::assertSame(
            ['current_page' => 2, 'from' => 11, 'to' => 20, 'per_page' => 10, 'path' => 'https://api.test/payments', 'total' => 23, 'last_page' => 3],
            $page->meta(),
        );

        $last = $this->pagination->fromRequest($this->request('page=3&per_page=10'))->paginate(range(1, 23));
        self::assertSame(range(21, 23), $last->items);
        self::assertFalse($last->hasMore);
        self::assertNull($last->links()['next']);

        $beyond = $this->pagination->fromRequest($this->request('page=9&per_page=10'))->paginate(range(1, 23));
        self::assertSame([], $beyond->items);
        self::assertNull($beyond->from());
        self::assertNull($beyond->to());
        self::assertSame(23, $beyond->total);
    }

    public function testASimplePageFetchesOneExtraItemInsteadOfCounting(): void
    {
        $request = $this->pagination->fromRequest($this->request('page=2&per_page=10'));

        $page = $request->simplePaginate(range(1, 23));
        self::assertSame(range(11, 20), $page->items, 'The extra item is not in the page.');
        self::assertNull($page->total);
        self::assertNull($page->lastPage());
        self::assertTrue($page->hasMore);
        self::assertNull($page->links()['last']);
        self::assertSame('https://api.test/payments?page=3&per_page=10', $page->links()['next']);
        self::assertSame(['current_page' => 2, 'from' => 11, 'to' => 20, 'per_page' => 10, 'path' => 'https://api.test/payments'], $page->meta());

        $last = $request->simplePaginate(range(1, 20));
        self::assertFalse($last->hasMore);
        self::assertNull($last->links()['next']);
    }

    public function testIterablesAndCustomCounts(): void
    {
        $generator = static function (): \Generator {
            yield from ['a', 'b', 'c'];
        };
        $page = $this->pagination->page(2, 2)->paginate($generator());
        self::assertSame(['c'], $page->items);
        self::assertSame(3, $page->total);
        self::assertSame('?page=1', $page->links()['first'], 'Without a request, links are relative.');
        self::assertSame('', $page->path());

        $counted = $this->pagination->page(1, 2)->paginate(['a', 'b', 'c'], count: static fn(): int => 10);
        self::assertSame(10, $counted->total);
        self::assertSame(5, $counted->lastPage());
    }

    public function testAQueryNobodySupportsIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Cannot paginate stdClass: no paginator supports it.');
        $this->pagination->page(1)->paginate(new \stdClass());
    }

    public function testAPageIsJsonAndAResponse(): void
    {
        $page = $this->pagination->fromRequest($this->request('per_page=2'))->paginate([['id' => 1], ['id' => 2], ['id' => 3]]);

        $json = json_decode((string) json_encode($page), true);
        self::assertSame([['id' => 1], ['id' => 2]], $json['data']);
        self::assertSame('https://api.test/payments?per_page=2&page=2', $json['links']['next']);
        self::assertSame(3, $json['meta']['total']);

        $request = $this->request('per_page=2');
        $response = $page->toResponse($request, new Container());
        self::assertSame('application/json', $response->getHeaderLine('Content-Type'));
        self::assertSame($json, json_decode((string) $response->getBody(), true));

        $resources = $this->pagination->fromRequest($request)->paginate([new CustomerResource(new Customer(1, 'Ada'))]);
        self::assertSame([['id' => 1, 'name' => 'Ada']], json_decode((string) $resources->toResponse($request, new Container())->getBody(), true)['data'], 'Resources among the items are rendered.');
        self::assertInstanceOf(Page::class, $resources);
    }
}
