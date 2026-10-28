<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Validation\Symfony;

use Crystoline\Xpsr\Configuration;
use Crystoline\Xpsr\Console\CommandRegistry;
use Crystoline\Xpsr\Console\Commands\MakeInputCommand;
use Crystoline\Xpsr\ServiceContainer\ServiceRegistry;
use Crystoline\Xpsr\Validation\InputMapper;
use Crystoline\Xpsr\Validation\ValidatorInterface;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Container\ContainerInterface;
use Symfony\Component\Validator\Validation;
use Symfony\Component\Validator\Validator\ValidatorInterface as SymfonyValidatorInterface;

/**
 * Registers request validation backed by Symfony Validator:
 *
 *   Crystoline\Xpsr\Validation\ValidatorInterface         validate() and validateArray()
 *   Crystoline\Xpsr\Validation\InputMapper                maps request data onto input classes
 *   Symfony\...\Validator\ValidatorInterface               Symfony's validator, for objects of your own
 *
 * and the make:input generator. Rule metadata is cached in the application cache (crystoline/xpsr-cache)
 * when it is installed and APP_DEBUG is off.
 */
final class ValidationServiceRegistry extends ServiceRegistry
{
    public function register(): void
    {
        $this->container->factory(InputMapper::class, static fn(ContainerInterface $container): InputMapper => new InputMapper($container), shared: true);
        $this->container->factory(SymfonyValidatorInterface::class, static function (ContainerInterface $container): SymfonyValidatorInterface {
            $builder = Validation::createValidatorBuilder()->enableAttributeMapping();
            $debug = (bool) $container->get(Configuration::class)->get('app.debug', false);
            if (!$debug && $container->has(CacheItemPoolInterface::class)) {
                $builder->setMappingCache($container->get(CacheItemPoolInterface::class));
            }

            return $builder->getValidator();
        }, shared: true);
        $this->container->factory(ValidatorInterface::class, static fn(ContainerInterface $container): ValidatorInterface => new SymfonyValidator(
            $container->get(SymfonyValidatorInterface::class),
            $container->get(InputMapper::class),
        ), shared: true);
        $this->container->alias(SymfonyValidator::class, ValidatorInterface::class);
        $this->container->bind(MakeInputCommand::class);
    }

    public function boot(): void
    {
        if ($this->container->has(CommandRegistry::class)) {
            $this->container->get(CommandRegistry::class)->register('make:input', MakeInputCommand::class, 'Create an input class for request validation.');
        }
    }
}
