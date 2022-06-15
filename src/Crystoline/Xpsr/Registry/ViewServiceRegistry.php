<?php

namespace Crystoline\Xpsr\Registry;

use Crystoline\Xpsr\Configuration;
use Crystoline\Xpsr\ServiceContainer\ServiceRegistry;
use Crystoline\Xpsr\View\ViewHandler;
use Crystoline\Xpsr\View\ViewHandlerInterface;

class ViewServiceRegistry extends ServiceRegistry
{
    protected bool $delayed = true;

    function register(): void
    {
        $config = $this->container->get(Configuration::class);
        if(!$config instanceof Configuration){
            throw new \Exception(''); //TODO
        }

        $viewDir = $config->get('app.view_dir');
        $blade = new ViewHandler($viewDir);

        $this->container->bind(ViewHandlerInterface::class, $blade);
    }
    public function providedServices(): array
    {
        return [ViewHandlerInterface::class,];
    }
}