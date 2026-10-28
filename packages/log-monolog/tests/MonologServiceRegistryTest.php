<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Log\Monolog\Tests;

use Crystoline\Xpsr\Application;
use Crystoline\Xpsr\Log\Monolog\Http\RequestIdMiddleware;
use Crystoline\Xpsr\Log\Monolog\LoggingOptions;
use Crystoline\Xpsr\Log\Monolog\MonologServiceRegistry;
use Crystoline\Xpsr\Log\Monolog\RequestId;
use Crystoline\Xpsr\ServiceContainer\ContainerException;
use InvalidArgumentException;
use Monolog\Formatter\JsonFormatter;
use Monolog\Formatter\LineFormatter;
use Monolog\Handler\RotatingFileHandler;
use Monolog\Handler\StreamHandler;
use Monolog\Handler\SyslogHandler;
use Monolog\Level;
use Monolog\Logger;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

final class MonologServiceRegistryTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/xpsr-log-' . bin2hex(random_bytes(6));
        mkdir($this->root . '/config', 0777, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->root . '/var/log/*') ?: [] as $file) {
            unlink($file);
        }
        foreach ([$this->root . '/var/log', $this->root . '/var', $this->root . '/config', $this->root] as $directory) {
            if (is_dir($directory)) {
                rmdir($directory);
            }
        }
    }

    public function testTheApplicationLoggerBecomesMonologWritingLinesToStderrByDefault(): void
    {
        $app = $this->application([]);
        $logger = $app->get(LoggerInterface::class);
        self::assertInstanceOf(Logger::class, $logger);
        self::assertSame($logger, $app->get(Logger::class));
        self::assertSame('Testing', $logger->getName());
        $handler = $logger->getHandlers()[0];
        self::assertInstanceOf(StreamHandler::class, $handler);
        self::assertSame('php://stderr', $handler->getUrl());
        self::assertInstanceOf(LineFormatter::class, $handler->getFormatter());
        self::assertSame(Level::Debug, $handler->getLevel());
    }

    public function testFileLinesCarryTheRequestIdAndInterpolatedContext(): void
    {
        $app = $this->application(['channel' => 'file']);
        $app->get(RequestId::class)->set('req-123');
        $app->get(LoggerInterface::class)->warning('Order {order} failed', ['order' => 42, 'reason' => 'card declined']);
        $app->get(LoggerInterface::class)->error('With exception', ['exception' => new RuntimeException('Boom')]);

        $log = (string) file_get_contents($this->root . '/var/log/app.log');
        self::assertMatchesRegularExpression('/^\[\d{4}-\d\d-\d\dT[\d:.]+[+-]\d\d:\d\d\] Testing\.WARNING: Order 42 failed \{"reason":"card declined"\} \{"request_id":"req-123"\}$/m', $log);
        self::assertStringContainsString('RuntimeException(code: 0): Boom', $log);
        self::assertStringContainsString('#0 ', $log, 'Exceptions are logged with their stack trace.');
    }

    public function testProductionDefaultsToJsonAndHonoursTheLevel(): void
    {
        $app = $this->application(['channel' => 'file', 'level' => 'warning'], env: 'production');
        $logger = $app->get(LoggerInterface::class);
        self::assertInstanceOf(JsonFormatter::class, $logger->getHandlers()[0]->getFormatter());
        $logger->info('ignored');
        $logger->error('kept', ['user' => 7]);

        $lines = array_values(array_filter(explode("\n", (string) file_get_contents($this->root . '/var/log/app.log'))));
        self::assertCount(1, $lines);
        $record = json_decode($lines[0], true, flags: JSON_THROW_ON_ERROR);
        self::assertSame(['kept', 'ERROR', 7], [$record['message'], $record['level_name'], $record['context']['user']]);

        self::assertInstanceOf(LineFormatter::class, $this->application(['format' => 'line'], env: 'production')->get(LoggerInterface::class)->getHandlers()[0]->getFormatter());
    }

    public function testDailyAndSyslogChannels(): void
    {
        $daily = $this->application(['channel' => 'daily', 'days' => 3])->get(LoggerInterface::class);
        self::assertInstanceOf(RotatingFileHandler::class, $daily->getHandlers()[0]);
        $daily->info('today');
        self::assertCount(1, glob($this->root . '/var/log/app-' . date('Y-m-d') . '.log') ?: []);

        self::assertInstanceOf(SyslogHandler::class, $this->application(['channel' => 'syslog'])->get(LoggerInterface::class)->getHandlers()[0]);
    }

    public function testEnvironmentVariablesApplyWhenTheConfigurationIsSilent(): void
    {
        $options = LoggingOptions::from([], ['LOG_CHANNEL' => 'DAILY', 'LOG_FORMAT' => 'json', 'LOG_LEVEL' => 'Notice'], 'local', 'Shop', '/srv/app');
        self::assertSame(['daily', 'json', Level::Notice, '/srv/app/var/log/app.log', 14, 'Shop'], [$options->channel, $options->format, $options->level, $options->path, $options->days, $options->name]);
        $configured = LoggingOptions::from(['channel' => 'file', 'path' => '/var/log/shop.log'], ['LOG_CHANNEL' => 'daily'], 'local', 'Shop', '/srv/app');
        self::assertSame(['file', '/var/log/shop.log'], [$configured->channel, $configured->path], 'Configuration wins over the environment.');
    }

    /** @return iterable<string, array{array<string, mixed>, string}> */
    public static function invalidOptions(): iterable
    {
        yield 'unknown option' => [['handler' => 'stderr'], 'Unknown option logging.handler.'];
        yield 'channel' => [['channel' => 'papertrail'], 'The log channel (logging.channel or LOG_CHANNEL) must be stderr, file, daily, syslog, not "papertrail".'];
        yield 'format' => [['format' => 'xml'], 'The log format (logging.format or LOG_FORMAT) must be line or json, not "xml".'];
        yield 'level' => [['level' => 'loud'], 'The log level (logging.level or LOG_LEVEL) must be a PSR-3 level such as debug, info, or warning, not "loud".'];
        yield 'days' => [['days' => 0], 'logging.days must be a whole number of days, 1 or more.'];
        yield 'not a string' => [['channel' => ['file']], 'Logging settings must be strings, except logging.days.'];
    }

    /** @param array<string, mixed> $logging */
    #[DataProvider('invalidOptions')]
    public function testInvalidOptionsNameTheSetting(array $logging, string $message): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message);
        LoggingOptions::from($logging, [], 'local', 'app', '/srv/app');
    }

    public function testInvalidOptionsFailWhenTheLoggerIsFirstUsed(): void
    {
        $app = $this->application(['channel' => 'papertrail']);
        $this->expectException(ContainerException::class);
        $app->get(LoggerInterface::class);
    }

    public function testTheMiddlewareKeepsAValidIncomingIdAndReplacesOthers(): void
    {
        $app = $this->application([]);
        $middleware = $app->get(RequestIdMiddleware::class);
        $factory = new Psr17Factory();
        /** @var \ArrayObject<int, mixed> $seen */
        $seen = new \ArrayObject();
        $handler = new class ($factory, $seen) implements RequestHandlerInterface {
            /** @param \ArrayObject<int, mixed> $seen */
            public function __construct(private readonly Psr17Factory $factory, private readonly \ArrayObject $seen) {}

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                $this->seen[] = $request->getAttribute('request_id');

                return $this->factory->createResponse(204);
            }
        };

        $kept = $middleware->process($factory->createServerRequest('GET', '/')->withHeader('X-Request-Id', 'lb-7f3a.12_x'), $handler);
        self::assertSame('lb-7f3a.12_x', $kept->getHeaderLine('X-Request-Id'));
        self::assertSame('lb-7f3a.12_x', $seen[0]);
        self::assertSame('lb-7f3a.12_x', $app->get(RequestId::class)->get());

        foreach (['', "evil\nInjected: yes", str_repeat('a', 129), '<script>'] as $incoming) {
            $request = $factory->createServerRequest('GET', '/');
            if ($incoming !== '' && !str_contains($incoming, "\n")) {
                $request = $request->withHeader('X-Request-Id', $incoming);
            }
            $generated = $middleware->process($request, $handler)->getHeaderLine('X-Request-Id');
            self::assertMatchesRegularExpression('/\A[0-9a-f]{32}\z/', $generated, var_export($incoming, true));
        }
        self::assertNotSame($seen[1], $seen[2], 'Each request gets a new ID.');
    }

    /** @param array<string, mixed> $logging */
    private function application(array $logging, string $env = 'local'): Application
    {
        $app = new Application($this->root);
        $config = $app->getConfig();
        $config->set('app.name', 'Testing');
        $config->set('app.env', $env);
        $config->set('logging', $logging);
        $config->set('app.registry', [MonologServiceRegistry::class]);
        $app->boot();

        return $app;
    }
}
