<?php

declare(strict_types=1);

namespace App\Registry;

use App\Console\Commands\GreetCommand;
use Crystoline\Console\CommandRegistry;
use Crystoline\ServiceContainer\ServiceRegistry;

final class CommandServiceRegistry extends ServiceRegistry
{
    public function register(): void
    {
        $this->container->bind(GreetCommand::class);
    }

    public function boot(): void
    {
        $this->container->get(CommandRegistry::class)->register(
            'app:greet',
            GreetCommand::class,
            'Example application command with constructor injection.',
        );
    }
}
