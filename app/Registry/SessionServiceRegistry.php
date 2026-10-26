<?php

namespace App\Registry;

use Crystoline\Configuration;
use Crystoline\ServiceContainer\ServiceRegistry;
use SessionHandler;

class SessionServiceRegistry extends ServiceRegistry
{
    //protected bool $delayed = true;

    public function register(): void
    {
        $this->container->singleton(SessionHandler::class);
    }

    public function boot(): void
    {
        if (PHP_SAPI === 'cli') {
            return;
        }

        $config = $this->container->get(Configuration::class);

        $lifetime = $config->get('session.lifetime');
        /** @var SessionHandler $handler */
        $handler = $this->container->get(SessionHandler::class);
        session_set_save_handler(
            [$handler, 'open'],
            [$handler, 'close'],
            [$handler, 'read'],
            [$handler, 'write'],
            [$handler, 'destroy'],
            [$handler, 'gc'],
        );
        // the following prevents unexpected effects when using objects as save handlers
        register_shutdown_function('session_write_close');

        session_set_cookie_params($lifetime);
        ini_set('session.use_cookies', 1);
        ini_set('session.use_only_cookies', 1);
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
