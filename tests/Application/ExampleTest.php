<?php

declare(strict_types=1);

namespace Tests\Application;

use Tests\TestCase;

/**
 * Examples of application tests. Copy this pattern for your own routes and commands.
 */
final class ExampleTest extends TestCase
{
    public function testHelloRouteReturnsJson(): void
    {
        $response = $this->get('/hello');

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('application/json', $response->getHeaderLine('Content-Type'));
        self::assertSame(['helloooooooo'], json_decode((string) $response->getBody(), true));
    }

    public function testUnknownRoutesReturnJsonErrorsToApiClients(): void
    {
        $response = $this->request('GET', '/does-not-exist', ['Accept' => 'application/json']);

        self::assertSame(404, $response->getStatusCode());
        self::assertSame(['message' => 'Not Found'], json_decode((string) $response->getBody(), true));
    }

    public function testMalformedJsonIsRejected(): void
    {
        $response = $this->request('POST', '/hello', ['Content-Type' => 'application/json', 'Accept' => 'application/json'], '{"broken"');

        self::assertSame(400, $response->getStatusCode());
    }

    public function testGreetCommand(): void
    {
        [$status, $output] = $this->console('app:greet', ['name' => 'Ada']);

        self::assertSame(0, $status);
        self::assertStringContainsString('Hello, Ada!', $output);
    }
}
