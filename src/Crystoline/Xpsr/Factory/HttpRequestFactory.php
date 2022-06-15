<?php

namespace Crystoline\Xpsr\Factory;

use Crystoline\Xpsr\Http\Request\HttpRequest;
use Psr\Container\ContainerInterface;

class HttpRequestFactory
{
    public function __invoke(ContainerInterface $container): HttpRequest
    {

        return new HttpRequest(
            $this->getUrl(),
            $_POST, //TODO
            'php://memory',
            $this->getHeaders()
        );
    }

    private function getUrl(): string
    {
        $https = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on';
        return ( $https? "https" : "http") . "://$_SERVER[HTTP_HOST]$_SERVER[REQUEST_URI]";
    }

    /**
     * @return array
     */
    public function getHeaders(): array
    {
        return array_change_key_case(getallheaders(), CASE_LOWER)?:[];
    }


}