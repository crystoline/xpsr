<?php

namespace Crystoline\Xpsr\Registry;

use Crystoline\Xpsr\Configuration;
use Crystoline\Xpsr\ServiceContainer\ServiceRegistry;

class FactoryRegistry extends ServiceRegistry
{

    function register(): void
    {
        $config = $this->container->get('config');
        if(!$config instanceof Configuration){
            return;
        }


        $factories =  $config->get('factory');

        foreach ($factories as $key => $factory){
            $this->container->bind($key, $factory);
        }
    }
}