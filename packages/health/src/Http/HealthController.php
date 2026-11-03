<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Health\Http;

use Crystoline\Xpsr\Health\Exposure;
use Crystoline\Xpsr\Health\Health;
use Crystoline\Xpsr\Http\Response\ResponseBuilder;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * The two endpoints, added in a route file:
 *
 *     $routes->get('/health', [HealthController::class, 'live']);
 *     $routes->get('/health/ready', [HealthController::class, 'ready']);
 *
 * live answers 200 as soon as the application boots: the process is up. ready runs the checks
 * and answers 200, or 503 when one fails, with the report as JSON; a warning does not change
 * the status code. Responses are never cached by proxies.
 */
final class HealthController
{
    public function __construct(
        private readonly Health $health,
        private readonly Exposure $exposure,
        private readonly ResponseBuilder $responses,
    ) {}

    public function live(ServerRequestInterface $request): ResponseInterface
    {
        return $this->responses->json(['status' => 'ok'])->withHeader('Cache-Control', 'no-store');
    }

    public function ready(ServerRequestInterface $request): ResponseInterface
    {
        $report = $this->health->check();

        return $this->responses
            ->json($report->toArray($this->exposure->detailsFor($request)), $report->isFailing() ? 503 : 200)
            ->withHeader('Cache-Control', 'no-store');
    }
}
