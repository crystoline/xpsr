<?php

namespace App\Http\Middleware;

use JetBrains\PhpStorm\NoReturn;
use MedBase\Session\UnauthenticatedException;
use MongoDB\Driver\Exception\AuthenticationException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

class SessionMiddleware implements MiddlewareInterface
{


    const SESSION_KEY = 'session_key';


    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        //var_dump($_SESSION[self::SESSION_KEY]); die();
//        if(!array_key_exists(self::SESSION_KEY, $_SESSION??[])){
//            $json_request = json_decode($request->getParsedBody());
//            if( $json_request !== null ){
//                throw new UnauthenticatedException('Unauthenticated', 401);
//            }
//
//        }
        return $handler->handle($request);
    }
}