<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Registry;

use Crystoline\Xpsr\Configuration;
use Crystoline\Xpsr\ServiceContainer\ContainerException;
use Crystoline\Xpsr\ServiceContainer\ServiceRegistry;
use Crystoline\Xpsr\Session\CsrfMiddleware;
use Crystoline\Xpsr\Session\CurrentSession;
use Crystoline\Xpsr\Session\Session;
use Crystoline\Xpsr\Session\SessionMiddleware;
use Crystoline\Xpsr\View\ViewHandler;
use Crystoline\Xpsr\View\ViewHandlerInterface;
use LogicException;
use Psr\Container\ContainerInterface;

/**
 * Registers the Twig view handler (deferred: Twig loads only when a view is rendered).
 * Requires twig/twig, which the framework suggests but does not install.
 */
class ViewServiceRegistry extends ServiceRegistry
{
    protected bool $delayed = true;

    public function register(): void
    {
        $this->container->factory(ViewHandlerInterface::class, static function (ContainerInterface $container): ViewHandler {
            $config = $container->get(Configuration::class);

            if (!$config instanceof Configuration) {
                throw new ContainerException('The configuration service must be a Configuration instance.');
            }
            // Twig is optional: API-only applications can leave it out until they render a view.
            if (!class_exists(\Twig\Environment::class)) {
                throw new ContainerException('Rendering views needs Twig. Run: composer require twig/twig');
            }

            $handler = new ViewHandler(
                $config->get('app.view_dir'),
                (bool) $config->get('app.debug', false),
                $config->get('app.view_cache_dir', false),
            );
            self::addSessionFunctions($handler, $container);

            return $handler;
        }, shared: true);
    }

    /**
     * csrf_token() and csrf_field() for forms, and session('key') for values such as flash
     * messages, on routes behind SessionMiddleware.
     */
    private static function addSessionFunctions(ViewHandler $handler, ContainerInterface $container): void
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

    public function providedServices(): array
    {
        return [ViewHandlerInterface::class];
    }
}
