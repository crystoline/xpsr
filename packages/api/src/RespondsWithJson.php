<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Api;

use Crystoline\Xpsr\Http\Response\ResponseBuilder;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * What resources and collections share: the status, headers, and meta of the response they
 * become, and the envelope key, which defaults to api.wrap.
 */
trait RespondsWithJson
{
    private int $status = 200;
    /** @var array<string, string|list<string>> */
    private array $headers = [];
    /** @var array<string, mixed> */
    private array $meta = [];
    private ?string $wrapKey = null;
    private bool $wrapChosen = false;

    /** The response body: the output under the envelope key, with the meta. */
    abstract public function output(ServerRequestInterface $request, ?string $wrap = 'data'): array;

    public function withStatus(int $status): static
    {
        $this->status = $status;

        return $this;
    }

    /** @param array<string, string|list<string>> $headers */
    public function withHeaders(array $headers): static
    {
        $this->headers = array_replace($this->headers, $headers);

        return $this;
    }

    /**
     * Values for the top-level "meta" key, next to the data. On a paginated collection they
     * join the pagination's meta.
     *
     * @param array<string, mixed> $meta
     */
    public function withMeta(array $meta): static
    {
        $this->meta = array_replace($this->meta, $meta);

        return $this;
    }

    /** The key to put the data under, in place of api.wrap; null for none. */
    public function wrap(?string $key): static
    {
        $this->wrapKey = $key;
        $this->wrapChosen = true;

        return $this;
    }

    public function toResponse(ServerRequestInterface $request, ContainerInterface $container): ResponseInterface
    {
        $options = $container->has(ApiOptions::class) ? $container->get(ApiOptions::class) : new ApiOptions();
        $wrap = $this->wrapChosen ? $this->wrapKey : $options->wrap;
        $response = $container->get(ResponseBuilder::class)->json($this->output($request, $wrap), $this->status);
        foreach ($this->headers as $name => $value) {
            $response = $response->withHeader($name, $value);
        }

        return $response;
    }

    /**
     * The body with the meta added.
     *
     * @param array<array-key, mixed> $body
     * @return array<array-key, mixed>
     */
    private function withMetaIn(array $body): array
    {
        if ($this->meta === []) {
            return $body;
        }
        $existing = $body['meta'] ?? [];
        $body['meta'] = array_replace(is_array($existing) ? $existing : [], $this->meta);

        return $body;
    }
}
