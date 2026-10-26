<?php

declare(strict_types=1);

namespace Tests;

use Crystoline\Application;
use PHPUnit\Framework\TestCase as BaseTestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * Base class for application tests.
 *
 * Each test gets a fresh application booted from bootstrap/app.php. Requests go through the
 * full HTTP kernel (global middleware, routing, error rendering) without a web server, and
 * console commands run through the console kernel.
 */
abstract class TestCase extends BaseTestCase
{
    private ?Application $application = null;

    protected function application(): Application
    {
        return $this->application ??= require dirname(__DIR__) . '/bootstrap/app.php';
    }

    /**
     * Send a request through the HTTP kernel.
     *
     * @param array<string, string> $headers
     * @param array<array-key, mixed>|string|null $body an array is sent as JSON
     */
    protected function request(string $method, string $uri, array $headers = [], array|string|null $body = null): ResponseInterface
    {
        $application = $this->application();
        $request = $application->get(ServerRequestFactoryInterface::class)->createServerRequest($method, 'http://localhost' . $uri);
        if (is_array($body)) {
            $body = json_encode($body, JSON_THROW_ON_ERROR);
            $headers += ['Content-Type' => 'application/json'];
        }
        foreach ($headers as $name => $value) {
            $request = $request->withHeader($name, $value);
        }
        if ($body !== null) {
            $request = $request->withBody($application->get(StreamFactoryInterface::class)->createStream($body));
        }

        return $application->getHttpKernel()->handle($request);
    }

    /** @param array<string, string> $headers */
    protected function get(string $uri, array $headers = []): ResponseInterface
    {
        return $this->request('GET', $uri, $headers);
    }

    /** @param array<array-key, mixed> $data */
    protected function postJson(string $uri, array $data): ResponseInterface
    {
        return $this->request('POST', $uri, ['Accept' => 'application/json'], $data);
    }

    /**
     * Run a console command, for example console('app:greet', ['name' => 'Ada']).
     * Options use their full names: ['--json' => true].
     *
     * @param array<string, mixed> $input
     * @return array{int, string} exit status and output
     */
    protected function console(string $command, array $input = []): array
    {
        $arguments = new ArrayInput(['command' => $command, ...$input]);
        $arguments->setInteractive(false);
        $output = new BufferedOutput();
        $status = $this->application()->getConsoleKernel()->run($arguments, $output);

        return [$status, $output->fetch()];
    }
}
