<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Queue\Messenger;

use Closure;
use Crystoline\Xpsr\Queue\QueueOptions;
use InvalidArgumentException;
use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;
use RuntimeException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Retry\MultiplierRetryStrategy;
use Symfony\Component\Messenger\Retry\RetryStrategyInterface;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransportFactory;
use Symfony\Component\Messenger\Transport\Serialization\PhpSerializer;
use Symfony\Component\Messenger\Transport\Sync\SyncTransportFactory;
use Symfony\Component\Messenger\Transport\TransportFactory;
use Symfony\Component\Messenger\Transport\TransportFactoryInterface;
use Symfony\Component\Messenger\Transport\TransportInterface;

/**
 * The transports of queue.transports by name, built on first use, as the PSR-11 container
 * Messenger looks senders up in, and their retry strategies.
 *
 * @internal Built by QueueServiceRegistry.
 */
final class Transports implements ContainerInterface
{
    /** Bridges in other Composer packages: DSN scheme => [factory class, package]. */
    private const BRIDGES = [
        'redis' => ['Symfony\Component\Messenger\Bridge\Redis\Transport\RedisTransportFactory', 'symfony/redis-messenger'],
        'rediss' => ['Symfony\Component\Messenger\Bridge\Redis\Transport\RedisTransportFactory', 'symfony/redis-messenger'],
        'amqp' => ['Symfony\Component\Messenger\Bridge\Amqp\Transport\AmqpTransportFactory', 'symfony/amqp-messenger'],
        'amqps' => ['Symfony\Component\Messenger\Bridge\Amqp\Transport\AmqpTransportFactory', 'symfony/amqp-messenger'],
        'sqs' => ['Symfony\Component\Messenger\Bridge\AmazonSqs\Transport\AmazonSqsTransportFactory', 'symfony/amazon-sqs-messenger'],
    ];

    /** @var array<string, TransportInterface> */
    private array $built = [];

    private readonly PhpSerializer $serializer;

    /** @param Closure(): MessageBusInterface $bus */
    public function __construct(
        private readonly QueueOptions $options,
        private readonly Closure $bus,
        private readonly ?DatabaseTransportFactory $database,
    ) {
        $this->serializer = new PhpSerializer();
    }

    public function get(string $id): TransportInterface
    {
        if (!isset($this->options->transports[$id])) {
            throw new class (sprintf('There is no transport "%s" in queue.transports. Configured transports: %s.', $id, implode(', ', array_keys($this->options->transports)))) extends InvalidArgumentException implements NotFoundExceptionInterface {};
        }

        return $this->built[$id] ??= $this->create($this->options->transports[$id]['dsn'], $this->options->transports[$id]['options']);
    }

    public function has(string $id): bool
    {
        return isset($this->options->transports[$id]);
    }

    /** @return list<string> */
    public function names(): array
    {
        return array_keys($this->options->transports);
    }

    /** Whether a transport runs jobs at once, so a worker has nothing to take from it. */
    public function isSync(string $name): bool
    {
        return str_starts_with($this->options->transports[$name]['dsn'] ?? '', 'sync://');
    }

    public function serializer(): PhpSerializer
    {
        return $this->serializer;
    }

    /** The retry strategies by transport name, for SendFailedMessageForRetryListener. */
    public function retryStrategies(): ContainerInterface
    {
        $strategies = [];
        foreach ($this->options->transports as $name => $transport) {
            [$max, $delay, $multiplier, $maxDelay] = $transport['retry'];
            $strategies[$name] = new MultiplierRetryStrategy($max, $delay, $multiplier, $maxDelay);
        }

        return new class ($strategies) implements ContainerInterface {
            /** @param array<string, RetryStrategyInterface> $strategies */
            public function __construct(private readonly array $strategies) {}

            public function get(string $id): RetryStrategyInterface
            {
                return $this->strategies[$id] ?? throw new class ("No retry strategy for \"{$id}\".") extends InvalidArgumentException implements NotFoundExceptionInterface {};
            }

            public function has(string $id): bool
            {
                return isset($this->strategies[$id]);
            }
        };
    }

    /** Whether an optional bridge is installed; a parameter, so analysis does not assume it is absent. */
    private static function installed(string $class): bool
    {
        return class_exists($class);
    }

    /** @return TransportFactoryInterface<TransportInterface> */
    private static function bridge(string $class): TransportFactoryInterface
    {
        $factory = new $class();
        if (!$factory instanceof TransportFactoryInterface) {
            throw new RuntimeException("{$class} is not a Messenger transport factory.");
        }

        return $factory;
    }

    /** @param array<array-key, mixed> $options */
    private function create(string $dsn, array $options): TransportInterface
    {
        $scheme = strtolower((string) strstr($dsn, '://', true));
        /** @var list<TransportFactoryInterface<TransportInterface>> $factories */
        $factories = [new SyncTransportFactory(($this->bus)()), new InMemoryTransportFactory()];
        if ($this->database !== null) {
            $factories[] = $this->database;
        }
        if (isset(self::BRIDGES[$scheme])) {
            [$class, $package] = self::BRIDGES[$scheme];
            if (!self::installed($class)) {
                throw new RuntimeException("The {$scheme}:// transport needs {$package}: run composer require {$package}.");
            }
            $factories[] = self::bridge($class);
        }
        if ($scheme === 'database' && $this->database === null) {
            throw new RuntimeException('The database:// transport needs Crystoline\Xpsr\Database\DatabaseServiceRegistry in app.registry.');
        }
        try {
            return (new TransportFactory($factories))->createTransport($dsn, $options, $this->serializer);
        } catch (\Symfony\Component\Messenger\Exception\InvalidArgumentException $exception) {
            throw new InvalidArgumentException("The queue DSN {$scheme}://… is not supported: use sync://, in-memory://, database://, redis://, amqp://, or sqs://.", 0, $exception);
        }
    }
}
