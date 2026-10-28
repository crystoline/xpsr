<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Log\Monolog;

use Crystoline\Xpsr\Application;
use Crystoline\Xpsr\Configuration;
use Crystoline\Xpsr\Log\Monolog\Http\RequestIdMiddleware;
use Crystoline\Xpsr\ServiceContainer\ServiceRegistry;
use InvalidArgumentException;
use Monolog\Formatter\FormatterInterface;
use Monolog\Formatter\JsonFormatter;
use Monolog\Formatter\LineFormatter;
use Monolog\Handler\HandlerInterface;
use Monolog\Handler\RotatingFileHandler;
use Monolog\Handler\StreamHandler;
use Monolog\Handler\SyslogHandler;
use Monolog\Logger;
use Monolog\Processor\PsrLogMessageProcessor;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Replaces the application logger (Psr\Log\LoggerInterface) with Monolog. List it in
 * app.registry after FactoryRegistry, whose config/factory.php binds the default logger.
 *
 *   Psr\Log\LoggerInterface            the Monolog logger (also Monolog\Logger)
 *   RequestId                          the current request's ID
 *   Http\RequestIdMiddleware           sets it; list it first in app.middleware
 *
 * Options: config/logging.php and LOG_CHANNEL, LOG_FORMAT, LOG_LEVEL; see LoggingOptions.
 */
final class MonologServiceRegistry extends ServiceRegistry
{
    public function register(): void
    {
        $this->container->singleton(RequestId::class);
        $this->container->bind(RequestIdMiddleware::class);
        $this->container->factory(LoggerInterface::class, static fn(ContainerInterface $container): Logger => self::logger($container), shared: true);
        $this->container->alias(Logger::class, LoggerInterface::class);
    }

    private static function logger(ContainerInterface $container): Logger
    {
        $config = $container->get(Configuration::class);
        $logging = $config->get('logging', []);
        if (!is_array($logging)) {
            throw new InvalidArgumentException('The logging configuration must be an array.');
        }
        $options = LoggingOptions::from(
            $logging,
            ['LOG_CHANNEL' => env('LOG_CHANNEL'), 'LOG_FORMAT' => env('LOG_FORMAT'), 'LOG_LEVEL' => env('LOG_LEVEL')],
            (string) $config->get('app.env', 'production'),
            (string) $config->get('app.name', 'app'),
            $container->get(Application::class)->getRootDir(),
        );
        $handler = self::handler($options);
        $handler->setFormatter(self::formatter($options));

        return new Logger($options->name, [$handler], [
            new PsrLogMessageProcessor(removeUsedContextFields: true),
            new RequestIdProcessor($container->get(RequestId::class)),
        ]);
    }

    /** @return HandlerInterface&\Monolog\Handler\FormattableHandlerInterface */
    private static function handler(LoggingOptions $options): HandlerInterface
    {
        return match ($options->channel) {
            'stderr' => new StreamHandler('php://stderr', $options->level),
            'file' => new StreamHandler($options->path, $options->level),
            'daily' => new RotatingFileHandler($options->path, $options->days, $options->level),
            'syslog' => new SyslogHandler($options->name, LOG_USER, $options->level),
            default => throw new InvalidArgumentException("Unknown log channel \"{$options->channel}\"."),
        };
    }

    private static function formatter(LoggingOptions $options): FormatterInterface
    {
        if ($options->format === 'json') {
            return (new JsonFormatter())->includeStacktraces(true);
        }
        $formatter = new LineFormatter(null, 'Y-m-d\TH:i:s.uP', allowInlineLineBreaks: true, ignoreEmptyContextAndExtra: true);

        return $formatter->includeStacktraces(true);
    }
}
