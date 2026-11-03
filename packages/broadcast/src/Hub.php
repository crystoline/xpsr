<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Broadcast;

use InvalidArgumentException;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;

/**
 * The Mercure hub: publishes updates to it over HTTP with the publisher token, and gives the URL
 * browsers subscribe at.
 */
final class Hub implements PublisherInterface
{
    public function __construct(
        private readonly BroadcastOptions $options,
        private readonly Tokens $tokens,
        private readonly ClientInterface $http,
        private readonly RequestFactoryInterface $requests,
        private readonly StreamFactoryInterface $streams,
    ) {}

    public function publish(Update $update): ?string
    {
        $url = $this->options->hubUrl();
        $request = $this->requests->createRequest('POST', $url)
            ->withHeader('Authorization', 'Bearer ' . $this->tokens->publisher())
            ->withHeader('Content-Type', 'application/x-www-form-urlencoded')
            ->withHeader('Accept', 'text/plain')
            ->withBody($this->streams->createStream(self::form($update)));
        try {
            $response = $this->http->sendRequest($request);
        } catch (ClientExceptionInterface $e) {
            throw new HubException(sprintf('The Mercure hub at %s could not be reached: %s', $url, $e->getMessage()), 0, $e);
        }
        $status = $response->getStatusCode();
        $body = trim((string) $response->getBody());
        if ($status < 200 || $status >= 300) {
            throw new HubException(sprintf(
                'The Mercure hub at %s answered %d to the update for %s%s',
                $url,
                $status,
                implode(', ', $update->topics),
                $body === '' ? '.' : ': ' . substr($body, 0, 200),
            ));
        }

        return $body === '' ? null : $body;
    }

    /**
     * The URL browsers subscribe at, with the topics as query parameters: new EventSource(url).
     *
     * @param string|array<array-key, mixed> $topics a topic, or a list of them
     * @param string|null $lastEventId the last update a reconnecting client saw, to receive the ones it missed
     */
    public function url(string|array $topics = [], ?string $lastEventId = null): string
    {
        $url = $this->options->publicHubUrl();
        $query = [];
        foreach (is_string($topics) ? [$topics] : $topics as $topic) {
            if (!is_string($topic) || $topic === '') {
                throw new InvalidArgumentException('A topic must be a non-empty string, such as "orders/42".');
            }
            $query[] = 'topic=' . rawurlencode($topic);
        }
        if ($lastEventId !== null) {
            $query[] = 'lastEventID=' . rawurlencode($lastEventId);
        }
        if ($query === []) {
            return $url;
        }

        return $url . (str_contains($url, '?') ? '&' : '?') . implode('&', $query);
    }

    /** The form body the hub takes: the topics repeated, the data, and the other fields when set. */
    public static function form(Update $update): string
    {
        $fields = [];
        foreach ($update->topics as $topic) {
            $fields[] = 'topic=' . rawurlencode($topic);
        }
        $fields[] = 'data=' . rawurlencode($update->data);
        if ($update->private) {
            $fields[] = 'private=on';
        }
        if ($update->id !== null) {
            $fields[] = 'id=' . rawurlencode($update->id);
        }
        if ($update->type !== null) {
            $fields[] = 'type=' . rawurlencode($update->type);
        }
        if ($update->retry !== null) {
            $fields[] = 'retry=' . $update->retry;
        }

        return implode('&', $fields);
    }
}
