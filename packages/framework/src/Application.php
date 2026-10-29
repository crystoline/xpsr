<?php

declare(strict_types=1);

namespace Crystoline\Xpsr;

use Crystoline\Xpsr\Contracts\Container\ContainerBuilderInterface;
use Crystoline\Xpsr\Contracts\Container\ContainerInterface;
use Crystoline\Xpsr\Console\ConsoleKernel;
use Crystoline\Xpsr\Encryption\Encrypter;
use Crystoline\Xpsr\Routing\RouteDispatcher;
use Crystoline\Xpsr\Exception\ExceptionHandlerInterface;
use Crystoline\Xpsr\Foundation\ServiceProviderManager;
use Crystoline\Xpsr\Http\HttpKernel;
use Crystoline\Xpsr\Http\Middleware\CorsMiddleware;
use Crystoline\Xpsr\Http\Middleware\CorsOptions;
use Crystoline\Xpsr\ServiceContainer\ServiceContainer;
use DateTimeZone;
use Dotenv\Dotenv;
use InvalidArgumentException;
use LogicException;
use Psr\Clock\ClockInterface;
use Psr\Container\ContainerInterface as PsrContainerInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Symfony\Component\Clock\ClockInterface as SymfonyClockInterface;
use Symfony\Component\Clock\NativeClock;
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

        // PSR-20 clock in the app.timezone time zone. Tests replace it with a MockClock.
        $this->container->factory(ClockInterface::class, static function (PsrContainerInterface $container): ClockInterface {
            $timezone = $container->get(Configuration::class)->get('app.timezone', 'UTC');
            try {
                return new NativeClock(new DateTimeZone(is_string($timezone) ? $timezone : ''));
            } catch (\Exception $exception) {
                throw new InvalidArgumentException('app.timezone must be a time zone identifier such as UTC or Europe/Berlin.', 0, $exception);
            }
        }, shared: true);
        $this->container->alias(SymfonyClockInterface::class, ClockInterface::class);

        // Encryption with app.key (APP_KEY). Built on first use, so an application without a key
        // starts, and only code that encrypts fails.
        $this->container->factory(
            Encrypter::class,
            static fn(PsrContainerInterface $container): Encrypter => Encrypter::fromConfiguration($container->get(Configuration::class)),
            shared: true,
        );

        $this->container->factory(CorsMiddleware::class, static function (PsrContainerInterface $container): CorsMiddleware {
            $options = $container->get(Configuration::class)->get('cors', []);
            if (!is_array($options)) {
                throw new InvalidArgumentException('The cors configuration must be an array.');
            }

            return new CorsMiddleware(
                CorsOptions::fromArray($options),
                $container->get(ResponseFactoryInterface::class),
                $container->get(ExceptionHandlerInterface::class),
            );
        });

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
