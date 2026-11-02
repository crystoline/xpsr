<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Api\Tests;

use Crystoline\Xpsr\Api\ApiOptions;
use Crystoline\Xpsr\Api\Console\MakeResourceCommand;
use Crystoline\Xpsr\Api\Pagination\Pagination;
use Crystoline\Xpsr\Api\Tests\Fixtures\Entity;
use Crystoline\Xpsr\Api\Tests\Fixtures\Model;
use Crystoline\Xpsr\Api\Tests\Fixtures\Project;
use Crystoline\Xpsr\Api\Tests\Fixtures\Resource\InvoiceResource;
use Crystoline\Xpsr\Api\Tests\Fixtures\Resource\LineResource;
use Crystoline\Xpsr\Console\CommandRegistry;
use Crystoline\Xpsr\Routing\HandlerResolver;
use Crystoline\Xpsr\ServiceContainer\ContainerException;
use InvalidArgumentException;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/** The services, the query paginators on a real database, resources on entities and models, and make:resource. */
final class ApiServiceRegistryTest extends TestCase
{
    private Project $project;

    protected function setUp(): void
    {
        $this->project = new Project(['per_page' => 2]);
        $this->project->boot();
        $this->project->seed();
    }

    protected function tearDown(): void
    {
        $this->project->remove();
    }

    private function request(string $query = ''): ServerRequestInterface
    {
        $request = (new Psr17Factory())->createServerRequest('GET', 'https://api.test/invoices' . ($query === '' ? '' : "?{$query}"));
        parse_str($query, $params);

        return $request->withQueryParams($params);
    }

    public function testADbalQueryBuilderIsPaged(): void
    {
        $query = $this->project->db()->createQueryBuilder()->select('id', 'number')->from('api_invoices')->orderBy('id', 'DESC');
        $pagination = $this->project->application->get(Pagination::class);

        $page = $pagination->fromRequest($this->request())->paginate($query);
        self::assertSame([['id' => 3, 'number' => 'A3'], ['id' => 2, 'number' => 'A2']], $page->items, 'api.per_page is 2.');
        self::assertSame(3, $page->total);
        self::assertSame('https://api.test/invoices?page=2', $page->links()['next']);
        self::assertSame([['id' => 1, 'number' => 'A1']], $pagination->fromRequest($this->request('page=2'))->paginate($query)->items);
        self::assertSame('SELECT id, number FROM api_invoices ORDER BY id DESC', $query->getSQL(), 'The caller\'s query is not changed.');

        $grouped = $this->project->db()->createQueryBuilder()->select('invoice_id', 'SUM(amount) AS total')->from('api_lines')->groupBy('invoice_id')->orderBy('invoice_id');
        $page = $pagination->fromRequest($this->request('per_page=2'))->paginate($grouped, count: static fn(): int => 3);
        self::assertSame(3, $page->total, 'A GROUP BY query gets its count from the caller.');
        self::assertSame([1, 2], array_column($page->items, 'invoice_id'));
    }

    public function testADoctrineQueryCountsRootEntitiesNotJoinedRows(): void
    {
        $query = $this->project->em()->createQueryBuilder()
            ->select('i', 'l')->from(Entity\Invoice::class, 'i')->leftJoin('i.lines', 'l')->orderBy('i.id', 'ASC');
        $pagination = $this->project->application->get(Pagination::class);

        $page = $pagination->fromRequest($this->request())->paginate($query);
        self::assertSame(3, $page->total, 'Three invoices, though the join has six rows.');
        self::assertCount(2, $page->items);
        self::assertContainsOnlyInstancesOf(Entity\Invoice::class, $page->items);
        self::assertSame(['A1', 'A2'], array_map(static fn(Entity\Invoice $invoice): string => $invoice->number, $page->items));
        self::assertSame(['A3'], array_map(static fn(Entity\Invoice $invoice): string => $invoice->number, $pagination->fromRequest($this->request('page=2'))->paginate($query->getQuery())->items), 'A Query works as well as a QueryBuilder.');

        $simple = $pagination->fromRequest($this->request('page=2'))->simplePaginate($query);
        self::assertNull($simple->total);
        self::assertFalse($simple->hasMore);
    }

    public function testAnEloquentQueryIsPaged(): void
    {
        $pagination = $this->project->application->get(Pagination::class);

        $page = $pagination->fromRequest($this->request())->paginate(Model\Invoice::query()->with('lines')->orderBy('id'));
        self::assertSame(3, $page->total);
        self::assertContainsOnlyInstancesOf(Model\Invoice::class, $page->items);
        self::assertSame(['A1', 'A2'], array_map(static fn(Model\Invoice $invoice): string => $invoice->number, $page->items));

        $invoice = Model\Invoice::query()->findOrFail(1);
        $lines = $pagination->fromRequest($this->request('per_page=1'))->paginate($invoice->lines());
        self::assertSame(2, $lines->total, 'A relation is a query too.');
        self::assertSame([100], array_map(static fn(Model\Line $line): int => $line->amount, $lines->items));

        $base = $pagination->fromRequest($this->request())->simplePaginate($this->project->application->get(\Illuminate\Database\ConnectionInterface::class)->table('api_lines')->orderBy('id'));
        self::assertCount(2, $base->items);
        self::assertTrue($base->hasMore);
    }

    public function testResourcesShowOnlyLoadedRelationsOfModels(): void
    {
        $request = $this->request();

        $bare = (new InvoiceResource(Model\Invoice::query()->findOrFail(1)))->resolve($request);
        self::assertSame(['id' => 1, 'number' => 'A1'], $bare, 'Lines were not loaded, so they are not in the output and not queried.');

        $loaded = (new InvoiceResource(Model\Invoice::query()->with('lines')->findOrFail(1)))->resolve($request);
        self::assertSame(['id' => 1, 'number' => 'A1', 'lines' => [['id' => 1, 'amount' => 100], ['id' => 2, 'amount' => 150]]], $loaded);

        $line = (new LineResource(Model\Line::query()->with('invoice')->findOrFail(1)))->resolve($request);
        self::assertSame(['id' => 1, 'amount' => 100, 'invoice' => ['id' => 1, 'number' => 'A1']], $line);
    }

    public function testResourcesShowOnlyLoadedRelationsOfEntities(): void
    {
        $request = $this->request();
        $em = $this->project->em();

        $invoice = $em->find(Entity\Invoice::class, 1);
        self::assertInstanceOf(Entity\Invoice::class, $invoice);
        self::assertSame(['id' => 1, 'number' => 'A1'], (new InvoiceResource($invoice))->resolve($request), 'The lines collection has not been loaded.');
        $invoice->lines->count();
        self::assertSame(['id' => 1, 'number' => 'A1', 'lines' => [['id' => 1, 'amount' => 100], ['id' => 2, 'amount' => 150]]], (new InvoiceResource($invoice))->resolve($request), 'Once used, it is.');

        $em->clear();
        $line = $em->find(Entity\Line::class, 1);
        self::assertInstanceOf(Entity\Line::class, $line);
        self::assertSame(['id' => 1, 'amount' => 100], (new LineResource($line))->resolve($request), 'The invoice is an uninitialized lazy object.');
        self::assertSame('A1', $line->invoice->number);
        self::assertSame(['id' => 1, 'amount' => 100, 'invoice' => ['id' => 1, 'number' => 'A1']], (new LineResource($line))->resolve($request));

        $em->clear();
        $fetched = $em->createQueryBuilder()->select('l', 'i')->from(Entity\Line::class, 'l')->join('l.invoice', 'i')->where('l.id = 1')->getQuery()->getSingleResult();
        self::assertSame(['id' => 1, 'number' => 'A1'], (new LineResource($fetched))->resolve($request)['invoice'], 'A fetch-joined relation is loaded.');
    }

    public function testARouteHandlerReturnsAResourceAndTheFrameworkSendsIt(): void
    {
        $container = $this->project->application->getContainer();
        $pagination = $this->project->application->get(Pagination::class);
        $request = $this->request('page=2');
        $handler = static fn(ServerRequestInterface $request): mixed => InvoiceResource::collection(
            $pagination->fromRequest($request)->paginate(Model\Invoice::query()->with('lines')->orderBy('id')),
        )->withMeta(['currency' => 'NGN']);

        $response = (new HandlerResolver($container))->handle($handler, $request);
        self::assertSame(200, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true);
        self::assertSame([['id' => 3, 'number' => 'A3', 'lines' => [['id' => 5, 'amount' => 300], ['id' => 6, 'amount' => 350]]]], $body['data']);
        self::assertSame('https://api.test/invoices?page=1', $body['links']['prev']);
        self::assertNull($body['links']['next']);
        self::assertSame(['current_page' => 2, 'from' => 3, 'to' => 3, 'per_page' => 2, 'path' => 'https://api.test/invoices', 'total' => 3, 'last_page' => 2, 'currency' => 'NGN'], $body['meta']);

        $created = (new HandlerResolver($container))->handle(static fn(): mixed => new InvoiceResource(Model\Invoice::query()->findOrFail(1))->withStatus(201), $request);
        self::assertSame(201, $created->getStatusCode());
        self::assertSame('{"data":{"id":1,"number":"A1"}}', (string) $created->getBody());

        $page = (new HandlerResolver($container))->handle(static fn(ServerRequestInterface $request): mixed => $pagination->fromRequest($request)->paginate(['x', 'y', 'z']), $request);
        self::assertSame(['z'], json_decode((string) $page->getBody(), true)['data'], 'A page can be returned as it is.');
    }

    /** @return iterable<string, array{array<string, mixed>, string}> */
    public static function invalidOptions(): iterable
    {
        yield 'unknown key' => [['wrapper' => 'data'], 'Unknown option api.wrapper. Known options: wrap, per_page, max_per_page.'];
        yield 'empty wrap' => [['wrap' => ''], 'api.wrap must be the key for the response body'];
        yield 'per_page' => [['per_page' => '10'], 'api.per_page must be a whole number of items, one or more.'];
        yield 'max below default' => [['per_page' => 50, 'max_per_page' => 20], 'api.max_per_page (20) must be at least api.per_page (50).'];
    }

    /** @param array<string, mixed> $config */
    #[DataProvider('invalidOptions')]
    public function testTheConfigurationIsChecked(array $config, string $message): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message);
        ApiOptions::fromArray($config);
    }

    public function testTheOptionsComeFromTheConfiguration(): void
    {
        $options = $this->project->application->get(ApiOptions::class);
        self::assertSame(2, $options->perPage);
        self::assertSame('data', $options->wrap);
        self::assertSame($options, $this->project->application->get(ApiOptions::class), 'Shared.');

        $broken = new Project(['wrap' => 7]);
        try {
            $broken->boot()->get(ApiOptions::class);
            self::fail('The options were built.');
        } catch (ContainerException $exception) {
            self::assertInstanceOf(InvalidArgumentException::class, $exception->getPrevious());
        } finally {
            $broken->remove();
        }
    }

    public function testMakeResourceWritesAResourceClass(): void
    {
        $application = $this->project->application;
        self::assertArrayHasKey('make:resource', $application->get(CommandRegistry::class)->all());
        $tester = new CommandTester($application->get(MakeResourceCommand::class));

        self::assertSame(Command::SUCCESS, $tester->execute(['name' => 'PaymentResource']));
        $file = $this->project->root . '/app/Http/Resource/PaymentResource.php';
        self::assertFileExists($file);
        $code = (string) file_get_contents($file);
        self::assertStringContainsString('namespace App\Http\Resource;', $code);
        self::assertStringContainsString('final class PaymentResource extends Resource', $code);
        self::assertStringContainsString("'id' => \$this->resource->id,", $code);
        self::assertStringContainsString('return new \App\Http\Resource\PaymentResource($payment);', $tester->getDisplay());
    }
}
