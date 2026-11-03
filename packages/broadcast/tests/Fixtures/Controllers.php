<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Broadcast\Tests\Fixtures;

use Crystoline\Xpsr\Broadcast\BroadcastInterface;
use Crystoline\Xpsr\Broadcast\Subscriptions;
use Crystoline\Xpsr\Database\ConnectionManager;
use Crystoline\Xpsr\Http\Response\ResponseBuilder;
use Crystoline\Xpsr\HttpClient\Testing\FakeClient;
use Psr\Container\ContainerInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use RuntimeException;

/** The handlers of the fixture project's routes, using the helpers as an application would. */
final class Controllers
{
    public function __construct(
        private readonly ResponseBuilder $responses,
        private readonly ClientInterface $client,
        private readonly BroadcastInterface $broadcast,
        private readonly Subscriptions $subscriptions,
        private readonly EventDispatcherInterface $events,
        private readonly ContainerInterface $container,
    ) {}

    public function page(ServerRequestInterface $request): ResponseInterface
    {
        return view('page', ['order' => 42]);
    }

    public function publicPage(ServerRequestInterface $request): ResponseInterface
    {
        return view('public');
    }

    /** Publishes inside a transaction, which the "rollback" query parameter rolls back; reports the requests sent inside and after it. */
    public function pay(ServerRequestInterface $request): ResponseInterface
    {
        $id = (string) $request->getAttribute('id');
        $rollback = ($request->getQueryParams()['rollback'] ?? '') === '1';
        $connection = $this->container->get(ConnectionManager::class)->connection();
        $inside = null;
        try {
            $inside = $connection->transactional(function () use ($id, $rollback): int {
                broadcast("orders/{$id}", ['status' => 'paid'], private: true, type: 'order.paid');
                $sent = $this->sent();
                if ($rollback) {
                    throw new RuntimeException('Payment refused; rolled back.');
                }

                return $sent;
            });
        } catch (RuntimeException $e) {
            $inside = -1;
        }

        return $this->responses->json(['inside' => $inside, 'after' => $this->sent()]);
    }

    public function publishNow(ServerRequestInterface $request): ResponseInterface
    {
        $this->broadcast->publish('news', 'Hello', type: 'news');

        return $this->responses->json(['after' => $this->sent()]);
    }

    public function event(ServerRequestInterface $request): ResponseInterface
    {
        $this->events->dispatch(new OrderPaid(42, 7));

        return $this->responses->json(['after' => $this->sent()]);
    }

    public function logout(ServerRequestInterface $request): ResponseInterface
    {
        return $this->subscriptions->forget($this->responses->json(['ok' => true]));
    }

    private function sent(): int
    {
        return $this->client instanceof FakeClient ? count($this->client->requests()) : -1;
    }
}
