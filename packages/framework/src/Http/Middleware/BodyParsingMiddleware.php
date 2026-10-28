<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Http\Middleware;

use InvalidArgumentException;
use JsonException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Crystoline\Xpsr\Http\Exception\BadRequestHttpException;
use Crystoline\Xpsr\Http\Exception\PayloadTooLargeHttpException;

/**
 * Fills getParsedBody() for request bodies PHP does not parse itself.
 *
 * - JSON (application/json or any +json type): decoded to arrays on every method.
 * - URL-encoded forms: parsed on methods other than POST; PHP already parses POST into $_POST.
 * - Other types, including multipart, are left unchanged.
 *
 * Malformed JSON, or JSON that is not an object or array, is a 400 error. A body larger than
 * the limit is a 413 error. The body stream is rewound afterwards when possible, so handlers
 * can still read the raw body.
 */
final class BodyParsingMiddleware implements MiddlewareInterface
{
    public function __construct(private readonly int $maxBytes = 1_048_576)
    {
        if ($maxBytes < 1) {
            throw new InvalidArgumentException('The body size limit must be at least 1 byte.');
        }
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $type = strtolower(trim(explode(';', $request->getHeaderLine('Content-Type'), 2)[0]));
        $json = $type === 'application/json' || (str_starts_with($type, 'application/') && str_ends_with($type, '+json'));
        $form = $type === 'application/x-www-form-urlencoded' && $request->getMethod() !== 'POST';
        if (!$json && !$form) {
            return $handler->handle($request);
        }

        $contents = $this->read($request);
        if ($contents === '') {
            return $handler->handle($request);
        }
        if ($form) {
            parse_str($contents, $fields);

            return $handler->handle($request->withParsedBody($fields));
        }
        try {
            $data = json_decode($contents, true, 512, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
        } catch (JsonException $exception) {
            throw new BadRequestHttpException('Malformed JSON request body: ' . $exception->getMessage() . '.', $exception);
        }
        if (!is_array($data)) {
            throw new BadRequestHttpException('The JSON request body must be an object or an array.');
        }

        return $handler->handle($request->withParsedBody($data));
    }

    private function read(ServerRequestInterface $request): string
    {
        $body = $request->getBody();
        $size = $body->getSize();
        if ($size !== null && $size > $this->maxBytes) {
            throw $this->tooLarge();
        }
        if ($body->isSeekable()) {
            $body->rewind();
        }
        $contents = '';
        while (!$body->eof()) {
            $chunk = $body->read(8192);
            if ($chunk === '') {
                break;
            }
            $contents .= $chunk;
            if (strlen($contents) > $this->maxBytes) {
                throw $this->tooLarge();
            }
        }
        if ($body->isSeekable()) {
            $body->rewind();
        }

        return $contents;
    }

    private function tooLarge(): PayloadTooLargeHttpException
    {
        return new PayloadTooLargeHttpException("The request body is larger than {$this->maxBytes} bytes.");
    }
}
