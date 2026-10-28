<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Tests\Unit;

use Crystoline\Xpsr\Exception\ExceptionHandler;
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

    public function testBuiltInDebugPageWithoutWhoopsShowsTheEscapedExceptionChain(): void
    {
        $handler = new ExceptionHandler(new ResponseFactory(), new StreamFactory(), new NullLogger(), true, whoops: false);
        $request = (new ServerRequestFactory())->createServerRequest('GET', 'http://localhost/');
        $cause = new \LogicException('root <cause>');
        $response = $handler->render($request, new RuntimeException('Failed <script>alert(1)</script>', 0, $cause));

        self::assertSame(500, $response->getStatusCode());
        self::assertSame('text/html; charset=utf-8', $response->getHeaderLine('Content-Type'));
        $body = (string) $response->getBody();
        self::assertStringContainsString('Failed &lt;script&gt;alert(1)&lt;/script&gt;', $body);
        self::assertStringNotContainsString('<script>', $body, 'Exception messages are escaped.');
        self::assertStringContainsString('root &lt;cause&gt;', $body, 'Previous exceptions are listed.');
        self::assertStringContainsString('LogicException', $body);
        self::assertStringContainsString(__FILE__, $body, 'The location and trace are shown.');
        self::assertStringContainsString('filp/whoops', $body);
    }

    public function testWhoopsRendersTheDebugPageWhenAvailable(): void
    {
        $handler = new ExceptionHandler(new ResponseFactory(), new StreamFactory(), new NullLogger(), true, whoops: true);
        $request = (new ServerRequestFactory())->createServerRequest('GET', 'http://localhost/');
        $body = (string) $handler->render($request, new RuntimeException('visible in debug'))->getBody();

        self::assertStringContainsString('visible in debug', $body);
        self::assertStringContainsString('Whoops', $body);
    }

    public function testProductionHtmlIgnoresDebugRenderers(): void
    {
        foreach ([true, false] as $whoops) {
            $handler = new ExceptionHandler(new ResponseFactory(), new StreamFactory(), new NullLogger(), false, $whoops);
            $body = (string) $handler->render((new ServerRequestFactory())->createServerRequest('GET', 'http://localhost/'), new RuntimeException('secret'))->getBody();
            self::assertSame('<h1>Internal Server Error</h1>', $body);
        }
    }
}
