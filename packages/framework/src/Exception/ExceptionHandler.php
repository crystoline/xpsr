<?php

declare(strict_types=1);

namespace Crystoline\Exception;

use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;
use Whoops\Handler\PrettyPageHandler;
use Whoops\Run;

final class ExceptionHandler implements ExceptionHandlerInterface
{
    public function __construct(
        private readonly ResponseFactoryInterface $responses,
        private readonly StreamFactoryInterface $streams,
        private readonly LoggerInterface $logger,
        private readonly bool $debug = false,
    ) {}

    public function report(Throwable $exception): void
    {
        $this->logger->error($exception->getMessage(), ['exception' => $exception]);
    }

    public function shouldReport(Throwable $exception): bool
    {
        return !$exception instanceof HttpExceptionInterface || $exception->getStatusCode() >= 500;
    }

    public function render(RequestInterface $request, Throwable $exception): ResponseInterface
    {
        if ($this->shouldReport($exception)) {
            $this->report($exception);
        }

        $status = $exception instanceof HttpExceptionInterface ? $exception->getStatusCode() : 500;
        $response = $this->responses->createResponse($status);

        if ($exception instanceof HttpExceptionInterface) {
            foreach ($exception->getHeaders() as $name => $value) {
                $response = $response->withHeader($name, $value);
            }
        }

        // Exception details are never included unless debug is explicitly enabled.
        $message = $this->debug ? $exception->getMessage() : $response->getReasonPhrase();

        if ($this->requestIsJson($request)) {
            $body = json_encode(['message' => $message], JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE);
            $contentType = 'application/json';
        } elseif ($this->debug) {
            $whoops = new Run();
            $whoops->allowQuit(false);
            $whoops->writeToOutput(false);
            $whoops->pushHandler(new PrettyPageHandler());
            $body = $whoops->handleException($exception);
            $contentType = 'text/html; charset=utf-8';
        } else {
            $body = '<h1>' . htmlspecialchars($message, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</h1>';
            $contentType = 'text/html; charset=utf-8';
        }

        return $response->withHeader('Content-Type', $contentType)
            ->withBody($this->streams->createStream($body));
    }

    private function requestIsJson(RequestInterface $request): bool
    {
        foreach (explode(',', strtolower($request->getHeaderLine('Accept'))) as $range) {
            $parts = array_map('trim', explode(';', $range));
            $type = array_shift($parts);
            $quality = 1.0;

            foreach ($parts as $parameter) {
                if (str_starts_with($parameter, 'q=')) {
                    $quality = (float) substr($parameter, 2);
                }
            }

            if ($quality > 0 && ($type === 'application/json' || str_ends_with($type, '+json'))) {
                return true;
            }
        }

        return false;
    }
}
