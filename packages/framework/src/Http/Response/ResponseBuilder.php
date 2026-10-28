<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Http\Response;

use InvalidArgumentException;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamFactoryInterface;

final class ResponseBuilder
{
    public function __construct(
        private readonly ResponseFactoryInterface $responses,
        private readonly StreamFactoryInterface $streams,
    ) {}

    public function json(mixed $data, int $status = 200): ResponseInterface
    {
        return $this->responses->createResponse($status)
            ->withHeader('Content-Type', 'application/json')
            ->withBody($this->streams->createStream(json_encode($data, JSON_THROW_ON_ERROR)));
    }

    public function html(string $html, int $status = 200): ResponseInterface
    {
        return $this->responses->createResponse($status)
            ->withHeader('Content-Type', 'text/html; charset=utf-8')
            ->withBody($this->streams->createStream($html));
    }

    /**
     * Send the browser to another URL. 303 (the default) makes it follow with GET, as after a
     * form post; 307 and 308 repeat the method; 301 and 308 are permanent.
     */
    public function redirect(string $url, int $status = 303): ResponseInterface
    {
        if (!in_array($status, [301, 302, 303, 307, 308], true)) {
            throw new InvalidArgumentException("A redirect needs status 301, 302, 303, 307, or 308, not {$status}.");
        }
        if ($url === '' || preg_match('/[\x00-\x1F\x7F]/', $url)) {
            throw new InvalidArgumentException('A redirect URL must not be empty or contain control characters.');
        }

        return $this->responses->createResponse($status)->withHeader('Location', $url);
    }
}
