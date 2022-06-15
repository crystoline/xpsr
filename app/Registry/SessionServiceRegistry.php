<?php

namespace App\Registry;

use Crystoline\Xpsr\Configuration;
use Crystoline\Xpsr\ServiceContainer\ServiceRegistry;
use SessionHandler;

class SessionServiceRegistry extends ServiceRegistry
{
    //protected bool $delayed = true;

    function register(): void
    {
        $config = $this->container->get(Configuration::class);

        $lifetime = $config->get('session.lifetime');
        /** @var SessionHandler $handler */
        $handler = $this->container->get(SessionHandler::class);
        session_set_save_handler(
            array($handler, 'open'),
            array($handler, 'close'),
            array($handler, 'read'),
            array($handler, 'write'),
            array($handler, 'destroy'),
            array($handler, 'gc')
        );
        // the following prevents unexpected effects when using objects as save handlers
        register_shutdown_function('session_write_close');

        session_set_cookie_params($lifetime);
        ini_set('session.use_cookies', 1);
        ini_set('session.use_only_cookies',1);
        session_start();
    }

//    public function providedServices(): array
//    {
//        return [
//            'session',
//            \SessionHandlerInterface::class
//        ];
//    }
}