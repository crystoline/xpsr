<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Registry;

use Crystoline\Xpsr\Auth\Access\Gate;
use Crystoline\Xpsr\Auth\AuthOptions;
use Crystoline\Xpsr\Auth\CurrentIdentity;
use Crystoline\Xpsr\Auth\UserInterface;
use Crystoline\Xpsr\Configuration;
use Crystoline\Xpsr\ServiceContainer\ContainerException;
use Crystoline\Xpsr\ServiceContainer\ServiceRegistry;
use Crystoline\Xpsr\Session\CsrfMiddleware;
use Crystoline\Xpsr\Session\CurrentSession;
use Crystoline\Xpsr\Session\Session;
use Crystoline\Xpsr\Session\SessionMiddleware;
use Crystoline\Xpsr\View\ViewFunctionsInterface;
use Crystoline\Xpsr\View\ViewHandler;
use Crystoline\Xpsr\View\ViewHandlerInterface;
use InvalidArgumentException;
use LogicException;
use Psr\Container\ContainerInterface;

/**
 * Registers the view handler for app.view_engine (deferred: the engine loads only when a view is
 * rendered): twig (the default; requires twig/twig, which the framework suggests but does not
 * install), plates, latte, or blade from their packages, or a ViewHandlerInterface service class.
 */
class ViewServiceRegistry extends ServiceRegistry
{
    /**
     * Official engines next to Twig, each in its own Composer package. Each class takes the view
     * directory, the debug flag, and the cache directory (or false). Classes are named as strings
     * so the framework does not depend on any engine package.
     */
    public const ENGINES = [
        'plates' => ['class' => 'Crystoline\Xpsr\View\Plates\PlatesViewHandler', 'package' => 'crystoline/xpsr-view-plates'],
        'latte' => ['class' => 'Crystoline\Xpsr\View\Latte\LatteViewHandler', 'package' => 'crystoline/xpsr-view-latte'],
        'blade' => ['class' => 'Crystoline\Xpsr\View\Blade\BladeViewHandler', 'package' => 'crystoline/xpsr-view-blade'],
    ];

    protected bool $delayed = true;

    public function register(): void
    {
        $this->container->factory(ViewHandlerInterface::class, static function (ContainerInterface $container): ViewHandlerInterface {
            $config = $container->get(Configuration::class);

            if (!$config instanceof Configuration) {
                throw new ContainerException('The configuration service must be a Configuration instance.');
            }
            $handler = self::engine($config, $container);
            if ($handler instanceof ViewFunctionsInterface) {
                self::addSessionFunctions($handler, $container);
                self::addAuthFunctions($handler, $container);
            }

            return $handler;
        }, shared: true);
    }

    private static function engine(Configuration $config, ContainerInterface $container): ViewHandlerInterface
    {
        $engine = $config->get('app.view_engine', 'twig');
        $official = is_string($engine) ? (self::ENGINES[$engine] ?? null) : null;
        if ($engine !== 'twig' && $official === null) {
            if (!is_string($engine) || !is_a($engine, ViewHandlerInterface::class, true)) {
                throw new InvalidArgumentException('app.view_engine must be twig, ' . implode(', ', array_keys(self::ENGINES)) . ', or a ViewHandlerInterface service class.');
            }
            // Custom engines can use ordinary container factories for their dependencies.
            $handler = $container->get($engine);

            return $handler instanceof ViewHandlerInterface ? $handler : throw new ContainerException("The service {$engine} is not a ViewHandlerInterface.");
        }

        $directory = $config->get('app.view_dir');
        if (!is_string($directory) || $directory === '') {
            throw new InvalidArgumentException('app.view_dir must be the directory of the view templates.');
        }
        $cache = $config->get('app.view_cache_dir', false);
        $cache = is_string($cache) && $cache !== '' ? $cache : false;
        $debug = (bool) $config->get('app.debug', false);

        if ($official === null) {
            // Twig is optional: API-only applications can leave it out until they render a view.
            if (!class_exists(\Twig\Environment::class)) {
                throw new ContainerException('Rendering views needs Twig. Run: composer require twig/twig');
            }

            return new ViewHandler($directory, $debug, $cache);
        }
        if (!self::installed($official['class'])) {
            throw new ContainerException("The {$engine} view engine is not installed. Run: composer require {$official['package']}");
        }
        $handler = self::create($official['class'], $directory, $debug, $cache);

        return $handler instanceof ViewHandlerInterface ? $handler : throw new ContainerException("{$official['class']} is not a ViewHandlerInterface.");
    }

    /** Whether an engine package is installed; a function, so static analysis does not fold it. */
    private static function installed(string $class): bool
    {
        return class_exists($class);
    }

    /** Builds an official engine, which may not be installed where this is analysed. */
    private static function create(string $class, string $directory, bool $debug, string|false $cache): object
    {
        return new $class($directory, $debug, $cache);
    }

    /**
     * csrf_token() and csrf_field() for forms, and session('key') for values such as flash
     * messages, on routes behind SessionMiddleware.
     */
    private static function addSessionFunctions(ViewFunctionsInterface $handler, ContainerInterface $container): void
    {
        $session = static fn(): ?Session => $container->get(CurrentSession::class)->get();
        $token = static fn(): string => ($session() ?? throw new LogicException(
            'csrf_token() and csrf_field() need a session. Render this view from a route behind ' . SessionMiddleware::class . ', as in routes/web.php.',
        ))->token();
        $handler->addFunction('csrf_token', $token);
        $handler->addFunction(
            'csrf_field',
            static fn(): string => '<input type="hidden" name="' . CsrfMiddleware::FIELD . '" value="' . htmlspecialchars($token(), ENT_QUOTES, 'UTF-8') . '">',
            html: true,
        );
        $handler->addFunction('session', static function (string $key, mixed $default = null) use ($session): mixed {
            $current = $session();

            return $current !== null && $current->has($key) ? $current->get($key) : $default;
        });
    }

    /**
     * auth_user() for the signed-in user (or null), and can('update', task) for policy checks, on
     * routes behind Authenticate. Without AuthServiceRegistry, nobody is signed in.
     */
    private static function addAuthFunctions(ViewFunctionsInterface $handler, ContainerInterface $container): void
    {
        $user = static fn(): ?UserInterface => $container->has(AuthOptions::class) ? $container->get(CurrentIdentity::class)->get()?->user : null;
        $handler->addFunction('auth_user', $user);
        $handler->addFunction('can', static fn(string $ability, mixed ...$arguments): bool => $container->has(AuthOptions::class)
            && $container->get(Gate::class)->allows($user(), $ability, ...$arguments));
    }

    public function providedServices(): array
    {
        return [ViewHandlerInterface::class];
    }
}
