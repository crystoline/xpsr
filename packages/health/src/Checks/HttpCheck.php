<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Health\Checks;

use Crystoline\Xpsr\Health\CheckInterface;
use Crystoline\Xpsr\Health\Result;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Throwable;

/** Fetches each URL of health.http through the HTTP client; anything but a 2xx answer fails the check. */
final class HttpCheck implements CheckInterface
{
    /** @param array<string, string> $urls by name */
    public function __construct(
        private readonly ClientInterface $client,
        private readonly RequestFactoryInterface $requests,
        private readonly array $urls,
    ) {}

    public function name(): string
    {
        return 'http';
    }

    public function check(): Result
    {
        $details = [];
        $failing = [];
        foreach ($this->urls as $name => $url) {
            $start = hrtime(true);
            try {
                $status = $this->client->sendRequest($this->requests->createRequest('GET', $url))->getStatusCode();
            } catch (Throwable $exception) {
                $details[$name] = ['url' => $url, 'error' => $exception->getMessage()];
                $failing[] = sprintf('%s: %s', $name, $exception->getMessage());
                continue;
            }
            $details[$name] = ['url' => $url, 'status' => $status, 'milliseconds' => round((hrtime(true) - $start) / 1_000_000, 2)];
            if ($status < 200 || $status >= 300) {
                $failing[] = sprintf('%s answered %d', $name, $status);
            }
        }
        if ($failing !== []) {
            return Result::failing(ucfirst(implode('; ', $failing)), $details);
        }

        return Result::ok(count($details) === 1 ? 'The dependency answers' : sprintf('%d dependencies answer', count($details)), $details);
    }
}
