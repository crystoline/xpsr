<?php

namespace Crystoline\Xpsr\Http\Request;
use InvalidArgumentException;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\StreamInterface;

class HttpRequest implements RequestInterface
{
    use MessageTrait, RequestTrait;

    /**
     * @param null|string $uri URI for the request, if any.
     * @param null|string $method HTTP method for the request, if any.
     * @param string|resource|StreamInterface $body Message body, if any.
     * @param array $headers Headers for the message, if any.
     * @throws InvalidArgumentException for any invalid value.
     */
    public function __construct($uri = null, $method = null, $body = 'php://memory', array $headers = [])
    {
        $this->initialize($uri, $method, $body, $headers);
    }

    /**
     * {@inheritdoc}
     */
    public function getHeaders()
    {
        $headers = $this->headers;
        if (! $this->hasHeader('host')
            && ($this->uri && $this->uri->getHost())
        ) {
            $headers['Host'] = [$this->getHostFromUri()];
        }

        return $headers;
    }

    /**
     * {@inheritdoc}
     */
    public function getHeader($header)
    {
        if (! $this->hasHeader($header)) {
            if (strtolower($header) === 'host'
                && ($this->uri && $this->uri->getHost())
            ) {
                return [$this->getHostFromUri()];
            }

            return [];
        }

        $header = $this->headerNames[strtolower($header)];

        $value = $this->headers[$header];
        $value = is_array($value) ? $value : [$value];
        return $value;
    }
}

