<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Tests\Unit;

use Crystoline\Xpsr\Exception\ExceptionHandler;
use Crystoline\Xpsr\Http\Exception\BadRequestHttpException;
use Crystoline\Xpsr\Http\Exception\ConflictHttpException;
use Crystoline\Xpsr\Http\Exception\ForbiddenHttpException;
use Crystoline\Xpsr\Http\Exception\HttpException;
use Crystoline\Xpsr\Http\Exception\HttpExceptionInterface;
use Crystoline\Xpsr\Http\Exception\MethodNotAllowedHttpException;
use Crystoline\Xpsr\Http\Exception\NotFoundHttpException;
use Crystoline\Xpsr\Http\Exception\PayloadTooLargeHttpException;
use Crystoline\Xpsr\Http\Exception\ServiceUnavailableHttpException;
use Crystoline\Xpsr\Http\Exception\TooManyRequestsHttpException;
use Crystoline\Xpsr\Http\Exception\UnauthorizedHttpException;
use Crystoline\Xpsr\Http\Exception\UnprocessableEntityHttpException;
use Crystoline\Xpsr\Http\Exception\UnsupportedMediaTypeHttpException;
use InvalidArgumentException;
use LogicException;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException as SymfonyNotFoundHttpException;

final class HttpExceptionTest extends TestCase
{
    public static function exceptions(): iterable
    {
        yield '400' => [new BadRequestHttpException('m'), 400, []];
        yield '401' => [new UnauthorizedHttpException('Bearer realm="api"', 'm'), 401, ['WWW-Authenticate' => 'Bearer realm="api"']];
        yield '403' => [new ForbiddenHttpException('m'), 403, []];
        yield '404' => [new NotFoundHttpException('m'), 404, []];
        yield '405' => [new MethodNotAllowedHttpException(['get', 'POST'], 'm'), 405, ['Allow' => 'GET, POST']];
        yield '409' => [new ConflictHttpException('m'), 409, []];
        yield '413' => [new PayloadTooLargeHttpException('m'), 413, []];
        yield '415' => [new UnsupportedMediaTypeHttpException('m'), 415, []];
        yield '422' => [new UnprocessableEntityHttpException('m'), 422, []];
        yield '429 with retry' => [new TooManyRequestsHttpException(60, 'm'), 429, ['Retry-After' => '60']];
        yield '429 without retry' => [new TooManyRequestsHttpException(message: 'm'), 429, []];
        yield '503' => [new ServiceUnavailableHttpException('m', null, ['Retry-After' => '120']), 503, ['Retry-After' => '120']];
        yield 'custom status' => [new HttpException(402, 'm'), 402, []];
    }

    #[DataProvider('exceptions')]
    public function testStatusAndHeaders(HttpExceptionInterface $exception, int $status, array $headers): void
    {
        self::assertSame($status, $exception->getStatusCode());
        self::assertSame($headers, $exception->getHeaders());
        self::assertSame('m', $exception->getMessage());
    }

    public function testKeepsThePreviousExceptionAndCode(): void
    {
        $previous = new LogicException('cause');
        $exception = new ConflictHttpException('m', $previous, ['X-Reason' => 'version'], 7);
        self::assertSame($previous, $exception->getPrevious());
        self::assertSame(7, $exception->getCode());
        self::assertSame(['X-Reason' => 'version'], $exception->getHeaders());
    }

    public function testExplicitHeadersCannotReplaceTheRequiredOne(): void
    {
        $exception = new MethodNotAllowedHttpException(['GET'], headers: ['Allow' => 'DELETE', 'X-Extra' => '1']);
        self::assertSame(['Allow' => 'GET', 'X-Extra' => '1'], $exception->getHeaders());
    }

    public static function invalidStatuses(): iterable
    {
        yield 'success' => [200];
        yield 'redirect' => [302];
        yield 'out of range' => [600];
    }

    #[DataProvider('invalidStatuses')]
    public function testRejectsNonErrorStatuses(int $status): void
    {
        $this->expectException(InvalidArgumentException::class);
        new HttpException($status);
    }

    public function testHandlerRendersXpsrAndSymfonyExceptionsAlikeWithoutLoggingClientErrors(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::never())->method('error');
        $factory = new Psr17Factory();
        $handler = new ExceptionHandler($factory, $factory, $logger);
        $request = $factory->createServerRequest('GET', 'http://localhost/')->withHeader('Accept', 'application/json');

        foreach ([new NotFoundHttpException('private'), new SymfonyNotFoundHttpException('private')] as $exception) {
            $response = $handler->render($request, $exception);
            self::assertSame(404, $response->getStatusCode(), $exception::class);
            self::assertSame(['message' => 'Not Found'], json_decode((string) $response->getBody(), true));
        }
        $limited = $handler->render($request, new TooManyRequestsHttpException(30));
        self::assertSame('30', $limited->getHeaderLine('Retry-After'));
    }

    public function testServerErrorStatusesAreLogged(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('error');
        $factory = new Psr17Factory();
        $response = (new ExceptionHandler($factory, $factory, $logger))->render(
            $factory->createServerRequest('GET', 'http://localhost/'),
            new ServiceUnavailableHttpException('maintenance'),
        );
        self::assertSame(503, $response->getStatusCode());
        self::assertSame('<h1>Service Unavailable</h1>', (string) $response->getBody());
        self::assertTrue((new ExceptionHandler($factory, $factory, new NullLogger()))->shouldReport(new \RuntimeException('x')));
    }
}
