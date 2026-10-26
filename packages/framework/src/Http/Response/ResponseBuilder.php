<?php

declare(strict_types=1);

namespace Crystoline\Http\Response;

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
}
