<?php

namespace Crystoline\Xpsr\Http\Request;

use Psr\Http\Message\ServerRequestInterface;

class ServerRequest extends HttpRequest implements ServerRequestInterface
{

    public function getServerParams()
    {
        return $_SERVER;
    }

    public function getCookieParams()
    {
        return $_COOKIE;
    }

    public function withCookieParams(array $cookies)
    {
        return array_merge($_COOKIE, $cookies);
    }

    public function getQueryParams()
    {
        return $_GET;
    }

    public function withQueryParams(array $query)
    {
        return array_merge($_GET, $query);
    }

    public function getUploadedFiles()
    {
        // TODO: Implement getUploadedFiles() method.
    }

    public function withUploadedFiles(array $uploadedFiles)
    {
        return $_FILES? : [];
    }

    public function getParsedBody()
    {

    }

    public function withParsedBody($data)
    {
        // TODO: Implement withParsedBody() method.
    }

    public function getAttributes()
    {
        // TODO: Implement getAttributes() method.
    }

    public function getAttribute($name, $default = null)
    {
        // TODO: Implement getAttribute() method.
    }

    public function withAttribute($name, $value)
    {
        // TODO: Implement withAttribute() method.
    }

    public function withoutAttribute($name)
    {
        // TODO: Implement withoutAttribute() method.
    }
}