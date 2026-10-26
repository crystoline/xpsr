<?php

declare(strict_types=1);

namespace Crystoline\Tests\Unit;

use Crystoline\Exception\ExceptionHandler;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use RuntimeException;
use Nyholm\Psr7\Factory\Psr17Factory as ResponseFactory;
use Nyholm\Psr7\Factory\Psr17Factory as ServerRequestFactory;
use Nyholm\Psr7\Factory\Psr17Factory as StreamFactory;
use Symfony\Component\HttpKernel\Exception\HttpException;

final class ExceptionHandlerTest extends TestCase
{
    public static function productionAcceptHeaders(): array
    {
        return [
            'html' => ['text/html', 'text/html'],
            'json' => ['application/json', 'application/json'],
            'multiple ranges' => ['application/json;q=0.9, text/plain;q=0.5', 'application/json'],
            'excluded json' => ['application/json;q=0, text/html', 'text/html'],
        ];
    }

    #[DataProvider('productionAcceptHeaders')]
    public function testProductionResponsesHideDetailsAndReportErrors(string $accept, string $type): void
    {
        $exception = new RuntimeException('private database password');
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('error')->with($exception->getMessage(), ['exception' => $exception]);
        $handler = new ExceptionHandler(new ResponseFactory(), new StreamFactory(), $logger);
        $request = (new ServerRequestFactory())->createServerRequest('GET', 'http://localhost/')->withHeader('Accept', $accept);
        $response = $handler->render($request, $exception);
        self::assertSame(500, $response->getStatusCode());
        self::assertStringContainsString($type, $response->getHeaderLine('Content-Type'));
        self::assertStringNotContainsString('private database password', (string) $response->getBody());
        self::assertStringNotContainsString(__FILE__, (string) $response->getBody());
    }

    public function testDebugJsonIncludesMessageAndHttpErrorsRetainHeaders(): void
    {
        $handler = new ExceptionHandler(new ResponseFactory(), new StreamFactory(), new NullLogger(), true);
        $request = (new ServerRequestFactory())->createServerRequest('GET', 'http://localhost/')->withHeader('Accept', 'application/json');
        $response = $handler->render($request, new HttpException(429, 'Try later', null, ['Retry-After' => '60']));
        self::assertSame(429, $response->getStatusCode());
        self::assertSame('60', $response->getHeaderLine('Retry-After'));
        self::assertSame(['message' => 'Try later'], json_decode((string) $response->getBody(), true));
    }
}
