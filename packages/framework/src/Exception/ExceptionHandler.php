<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Exception;

use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Crystoline\Xpsr\Http\Exception\HttpExceptionInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface as SymfonyHttpExceptionInterface;
use Throwable;
use Whoops\Handler\PrettyPageHandler;
use Whoops\Run;

final class ExceptionHandler implements ExceptionHandlerInterface
{
    private readonly bool $whoops;

    /**
     * @param bool|null $whoops use filp/whoops for debug HTML pages; null uses it when installed.
     *                          Without it, debug pages use a plain built-in layout.
     */
    public function __construct(
        private readonly ResponseFactoryInterface $responses,
        private readonly StreamFactoryInterface $streams,
        private readonly LoggerInterface $logger,
        private readonly bool $debug = false,
        ?bool $whoops = null,
    ) {
        $this->whoops = $whoops ?? class_exists(Run::class);
    }

    public function report(Throwable $exception): void
    {
        $this->logger->error($exception->getMessage(), ['exception' => $exception]);
    }

    public function shouldReport(Throwable $exception): bool
    {
        $http = self::httpError($exception);

        return $http === null || $http[0] >= 500;
    }

    public function render(RequestInterface $request, Throwable $exception): ResponseInterface
    {
        if ($this->shouldReport($exception)) {
            $this->report($exception);
        }

        [$status, $headers] = self::httpError($exception) ?? [500, []];
        $response = $this->responses->createResponse($status);
        foreach ($headers as $name => $value) {
            $response = $response->withHeader($name, $value);
        }

        // Exception details are never included unless debug is explicitly enabled.
        $message = $this->debug ? $exception->getMessage() : $response->getReasonPhrase();

        if ($this->requestIsJson($request)) {
            $body = json_encode(['message' => $message], JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE);
            $contentType = 'application/json';
        } elseif ($this->debug) {
            $body = $this->whoops ? $this->whoopsPage($exception) : self::debugPage($exception);
            $contentType = 'text/html; charset=utf-8';
        } else {
            $body = '<h1>' . htmlspecialchars($message, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</h1>';
            $contentType = 'text/html; charset=utf-8';
        }

        return $response->withHeader('Content-Type', $contentType)
            ->withBody($this->streams->createStream($body));
    }

    /**
     * Status and headers of an HTTP exception, or null for any other exception.
     *
     * Symfony's HttpKernel exceptions are recognized too when symfony/http-kernel is installed,
     * so existing code that throws them keeps working.
     *
     * @return array{int, array<array-key, mixed>}|null
     */
    private static function httpError(Throwable $exception): ?array
    {
        if ($exception instanceof HttpExceptionInterface || $exception instanceof SymfonyHttpExceptionInterface) {
            return [$exception->getStatusCode(), $exception->getHeaders()];
        }

        return null;
    }

    private function whoopsPage(Throwable $exception): string
    {
        $whoops = new Run();
        $whoops->allowQuit(false);
        $whoops->writeToOutput(false);
        $page = new PrettyPageHandler();
        // Whoops renders nothing under the CLI SAPI by default, which would leave the body empty in
        // tests and in CLI-based servers. The caller already decided an HTML page is wanted.
        $page->handleUnconditionally(true);
        $whoops->pushHandler($page);

        return $whoops->handleException($exception);
    }

    /** A dependency-free debug page: each exception in the chain with its location and trace. */
    private static function debugPage(Throwable $exception): string
    {
        $escape = static fn(string $text): string => htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $sections = [];
        for ($current = $exception; $current !== null; $current = $current->getPrevious()) {
            $sections[] = sprintf(
                "<section>\n<h2>%s</h2>\n<p><code>%s</code> in <code>%s:%d</code></p>\n<pre>%s</pre>\n</section>",
                $escape($current->getMessage()),
                $escape($current::class),
                $escape($current->getFile()),
                $current->getLine(),
                $escape($current->getTraceAsString()),
            );
        }

        return "<!DOCTYPE html>\n<html lang=\"en\">\n<head>\n<meta charset=\"utf-8\">\n<title>" . $escape($exception::class) . "</title>\n"
            . "<style>body{font-family:system-ui,sans-serif;margin:2rem;max-width:72rem}pre{overflow-x:auto;background:#f4f4f4;padding:1rem}"
            . "section+section{border-top:1px solid #ccc;margin-top:2rem}</style>\n</head>\n<body>\n"
            . "<p>Debug mode is on (<code>APP_DEBUG=true</code>). Install <code>filp/whoops</code> for a richer page.</p>\n"
            . implode("\n", $sections) . "\n</body>\n</html>\n";
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
