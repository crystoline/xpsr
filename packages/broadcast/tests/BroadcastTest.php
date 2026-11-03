<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Broadcast\Tests;

use Crystoline\Xpsr\Broadcast\BroadcastInterface;
use Crystoline\Xpsr\Broadcast\Console\PublishCommand;
use Crystoline\Xpsr\Broadcast\HubException;
use Crystoline\Xpsr\Broadcast\PublishUpdate;
use Crystoline\Xpsr\Broadcast\Testing\FakeBroadcast;
use Crystoline\Xpsr\Broadcast\Tests\Fixtures\OrderPaid;
use Crystoline\Xpsr\Broadcast\Tests\Fixtures\Project;
use Crystoline\Xpsr\Broadcast\Update;
use Crystoline\Xpsr\Queue\JobInterface;
use Crystoline\Xpsr\Queue\QueueInterface;
use Crystoline\Xpsr\Queue\Testing\FakeQueue;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Symfony\Component\Console\Tester\CommandTester;

final class BroadcastTest extends TestCase
{
    /** @var list<Project> */
    private array $projects = [];

    protected function tearDown(): void
    {
        foreach ($this->projects as $project) {
            $project->remove();
        }
    }

    public function testAnUpdateInATransactionLeavesWhenItCommitsAndNeverWhenItRollsBack(): void
    {
        foreach ([true, false] as $queue) {
            $project = $this->project(queue: $queue);
            $project->hubAccepts();

            $paid = $this->json($project->request('POST', '/pay/42'));
            self::assertSame(['inside' => 0, 'after' => 1], $paid, $queue ? 'Through the sync queue, the job runs after the commit.' : 'Without the queue, the after-commit callback sends it.');
            $request = $project->client->lastRequest();
            self::assertSame(Project::HUB, (string) $request->getUri());
            self::assertSame('topic=orders%2F42&data=%7B%22status%22%3A%22paid%22%7D&private=on&type=order.paid', (string) $request->getBody());

            $refused = $this->json($project->request('POST', '/pay/43?rollback=1'));
            self::assertSame(['inside' => -1, 'after' => 1], $refused, 'A rolled back transaction publishes nothing.');
            self::assertSame(0, $project->client->remaining(), 'Every queued hub answer was used by the one update.');
        }
    }

    public function testOutsideATransactionAnUpdateLeavesAtOnce(): void
    {
        $project = $this->project(queue: false, database: false);
        $project->hubAccepts();
        self::assertSame(['after' => 1], $this->json($project->request('POST', '/publish')));
        self::assertSame('topic=news&data=Hello&type=news', (string) $project->client->lastRequest()->getBody());
    }

    public function testWithAQueueTransportTheUpdateIsAJob(): void
    {
        $project = $this->project(['queue' => 'broadcasts']);
        $project->application->getContainer()->instance(QueueInterface::class, $queue = new FakeQueue());
        $this->json($project->request('POST', '/publish'));
        $project->client->assertNothingRequested();
        $queue->assertDispatched(PublishUpdate::class, static fn(JobInterface $job): bool => $job instanceof PublishUpdate && $job->update->topics === ['news'] && $job->update->data === 'Hello');
        self::assertCount(1, $queue->all());
        self::assertSame('broadcasts', $queue->all()[0]['queue'], 'Sent to the transport broadcast.queue names.');
    }

    public function testADispatchedBroadcastableEventIsPublished(): void
    {
        $project = $this->project();
        $project->hubAccepts();
        self::assertSame(['after' => 1], $this->json($project->request('POST', '/event')));
        self::assertSame('topic=orders%2F42&topic=users%2F7%2Forders&data=%7B%22order%22%3A42%2C%22status%22%3A%22paid%22%7D&private=on&type=order.paid', (string) $project->client->lastRequest()->getBody());
    }

    public function testTheFakeKeepsUpdatesAndAsserts(): void
    {
        $project = $this->project();
        $project->application->getContainer()->instance(BroadcastInterface::class, $fake = new FakeBroadcast());
        $project->request('POST', '/pay/42');
        $project->request('POST', '/event');
        $project->client->assertNothingRequested();

        $update = $fake->assertPublished('orders/42');
        self::assertSame(['status' => 'paid'], $update->json());
        $fake->assertPublished('orders/42', static fn(Update $update): bool => $update->private, 2);
        $fake->assertPublished('users/7/orders', static fn(Update $update): bool => $update->type === 'order.paid');
        $fake->assertNotPublished('orders/43');
        $fake->assertNotPublished('orders/42', static fn(Update $update): bool => !$update->private);
        self::assertCount(2, $fake->published());
        self::assertCount(1, $fake->published('users/7/orders'));
        self::assertSame((new OrderPaid(42, 7))->toUpdate()->data, $fake->published('users/7/orders')[0]->data);
        try {
            $fake->assertPublished('orders/42', null, 1);
            self::fail('Two were published.');
        } catch (AssertionFailedError $e) {
            self::assertStringContainsString('2 update(s) to "orders/42" were published, not 1. Published: orders/42; orders/42, users/7/orders.', $e->getMessage());
        }
        try {
            $fake->assertNothingPublished();
            self::fail('Updates were published.');
        } catch (AssertionFailedError $e) {
            self::assertStringContainsString('Updates were published: orders/42; orders/42, users/7/orders.', $e->getMessage());
        }
        $fake->clear();
        $fake->assertNothingPublished();
        try {
            $fake->assertPublished('orders/42');
            self::fail('Nothing now.');
        } catch (AssertionFailedError $e) {
            self::assertStringContainsString('No update to "orders/42" was published. Published: nothing.', $e->getMessage());
        }
        $fake->publish('news', 'Hello', type: 'news');
        self::assertSame('Hello', $fake->assertPublished('news')->data);
    }

    public function testTheNullDriverDropsUpdates(): void
    {
        $project = $this->project(['driver' => 'null']);
        self::assertSame(['after' => 0], $this->json($project->request('POST', '/publish')));
        $project->client->assertNothingRequested();
    }

    public function testAHubThatRefusesAnUpdateFailsTheRequest(): void
    {
        $project = $this->project(queue: false, database: false);
        $factory = new Psr17Factory();
        $project->client->queue($factory->createResponse(500)->withBody($factory->createStream('boom')));
        $this->expectException(HubException::class);
        $this->expectExceptionMessage('answered 500 to the update for news: boom');
        $project->application->get(BroadcastInterface::class)->publish('news', 'Hello');
    }

    public function testTheCommandPublishesOneUpdateNow(): void
    {
        $project = $this->project();
        $project->hubAccepts();
        $tester = new CommandTester($project->application->get(PublishCommand::class));
        self::assertSame(0, $tester->execute(['topic' => 'orders/42', 'data' => '{"status":"paid"}', '--private' => true, '--type' => 'order.paid', '--id' => 'u1']));
        self::assertStringContainsString('Published to orders/42 (id urn:uuid:', $tester->getDisplay());
        self::assertSame('topic=orders%2F42&data=%7B%22status%22%3A%22paid%22%7D&private=on&id=u1&type=order.paid', (string) $project->client->lastRequest()->getBody());

        $factory = new Psr17Factory();
        $project->client->queue($factory->createResponse(401)->withBody($factory->createStream('Unauthorized')));
        self::assertSame(1, $tester->execute(['topic' => 'orders/42']));
        self::assertStringContainsString('answered 401 to the update for orders/42: Unauthorized', $tester->getDisplay());
        self::assertSame('topic=orders%2F42&data=%7B%7D', (string) $project->client->lastRequest()->getBody(), 'Empty JSON by default.');

        self::assertSame(1, $tester->execute(['topic' => "a\nb"]));
        self::assertStringContainsString('A topic must be a non-empty string', $tester->getDisplay());
    }

    /** @param array<string, mixed> $broadcast */
    private function project(array $broadcast = [], bool $queue = true, bool $database = true): Project
    {
        $project = new Project($broadcast, queue: $queue, database: $database);
        $project->boot();
        $this->projects[] = $project;

        return $project;
    }

    /** @return array<string, mixed> */
    private function json(ResponseInterface $response): array
    {
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $data = json_decode((string) $response->getBody(), true);
        self::assertIsArray($data);

        return $data;
    }
}
