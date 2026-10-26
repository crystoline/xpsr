<?php

declare(strict_types=1);

namespace Crystoline;

use Crystoline\Contracts\Container\ContainerBuilderInterface;
use Crystoline\Contracts\Container\ContainerInterface;
use Crystoline\Console\ConsoleKernel;
use Crystoline\Routing\RouteDispatcher;
use Crystoline\Exception\ExceptionHandlerInterface;
use Crystoline\Foundation\ServiceProviderManager;
use Crystoline\Http\HttpKernel;
use Crystoline\ServiceContainer\ServiceContainer;
use Dotenv\Dotenv;
use InvalidArgumentException;
use LogicException;
use Psr\Container\ContainerInterface as PsrContainerInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class Application
{
    private static ?self $instance = null;
    private Configuration $config;
    private readonly ContainerInterface $container;
    private readonly ServiceProviderManager $providers;
    public readonly string $rootDir;

    public function __construct(string $rootDir, ?ContainerInterface $container = null)
    {
        $root = realpath($rootDir);

        if ($root === false || !is_dir($root)) {
            throw new InvalidArgumentException('Application root must be an existing directory.');
        }

        $this->rootDir = $root;
        $this->container = $container ?? new ServiceContainer();
        self::$instance = $this;
        Dotenv::createImmutable($root)->safeLoad();
        $environment = env('APP_ENV', 'production');

        if (!is_string($environment) || !preg_match('/\A[a-zA-Z0-9_-]+\z/', $environment)) {
            throw new InvalidArgumentException('APP_ENV must contain only letters, numbers, underscores, or hyphens.');
        }

        $this->config = new Configuration();
        $this->config->loadConfigurationFiles($root . '/config', $environment);
        $this->registerCoreServices();
        $this->providers = new ServiceProviderManager($this->container);
    }

    public static function getInstance(): self
    {
        return self::$instance ?? throw new LogicException('The application has not been created.');
    }

    public function getConfig(): Configuration
    {
        return $this->config;
    }

    public function getContainer(): ContainerInterface
    {
        return $this->container;
    }

    public function getRootDir(): string
    {
        return $this->rootDir;
    }

    public function isConsole(): bool
    {
        return PHP_SAPI === 'cli';
    }

    public function boot(): void
    {
        $registries = $this->config->get('app.registry', []);

        if (!is_array($registries)) {
            throw new InvalidArgumentException('app.registry must be an array of service registry classes.');
        }

        $this->providers->boot($registries);
    }

    public function getHttpKernel(): RequestHandlerInterface
    {
        $this->boot();

        return $this->container->get(RequestHandlerInterface::class);
    }

    public function getConsoleKernel(): ConsoleKernel
    {
        $this->boot();

        return $this->container->get(ConsoleKernel::class);
    }

    public function get(string $id): mixed
    {
        return $this->container->get($id);
    }

    public function has(string $id): bool
    {
        return $this->container->has($id);
    }

    /** @deprecated Use get() or getContainer()->get(). */
    public function build(string $id): mixed
    {
        return $this->get($id);
    }

    private function registerCoreServices(): void
    {
        foreach ([ContainerInterface::class, ContainerBuilderInterface::class, PsrContainerInterface::class] as $id) {
            $this->container->instance($id, $this->container);
        }

        $this->container->instance(self::class, $this);
        $this->container->instance(Configuration::class, $this->config);
        $this->container->alias('config', Configuration::class);

        $this->container->factory(RequestHandlerInterface::class, static function (PsrContainerInterface $container): HttpKernel {
            $configuration = $container->get(Configuration::class);
            $middleware = [];

            foreach ($configuration->get('app.middleware', []) as $entry) {
                $instance = is_string($entry) ? $container->get($entry) : $entry;

                if (!$instance instanceof MiddlewareInterface) {
                    throw new InvalidArgumentException('Every HTTP middleware must implement PSR-15.');
                }

                $middleware[] = $instance;
            }

            return new HttpKernel(
                $container->get(RouteDispatcher::class),
                $container->get(ExceptionHandlerInterface::class),
                $container->get(StreamFactoryInterface::class),
                $middleware,
            );
        }, shared: true);
    }
}
