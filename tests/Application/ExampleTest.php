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
        $this->get('/hello')
            ->assertOk()
            ->assertHeader('Content-Type', 'application/json')
            ->assertExactJson(['helloooooooo']);
    }

    public function testUnknownRoutesReturnJsonErrorsToApiClients(): void
    {
        $this->getJson('/does-not-exist')
            ->assertNotFound()
            ->assertJsonPath('message', 'Not Found');
    }

    public function testMalformedJsonIsRejected(): void
    {
        $response = $this->request('POST', '/hello', ['Content-Type' => 'application/json', 'Accept' => 'application/json'], '{"broken"');

        $response->assertStatus(400);
    }

    public function testGreetCommand(): void
    {
        [$status, $output] = $this->console('app:greet', ['name' => 'Ada']);

        self::assertSame(0, $status);
        self::assertStringContainsString('Hello, Ada!', $output);
    }
}
