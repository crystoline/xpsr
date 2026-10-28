<?php

declare(strict_types=1);

namespace Crystoline\Tests\Unit;

use Countable;
use Crystoline\Configuration;
use Crystoline\Console\CommandRegistry;
use Crystoline\Console\Commands\EventListCommand;
use Crystoline\Events\EventDispatcher;
use Crystoline\Events\ListenerProvider;
use Crystoline\Events\StoppableEvent;
use Crystoline\Foundation\ServiceProviderManager;
use Crystoline\Registry\EventServiceRegistry;
use Crystoline\ServiceContainer\ContainerException;
use Crystoline\ServiceContainer\ServiceContainer;
use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\EventDispatcher\ListenerProviderInterface;
use RuntimeException;
use stdClass;
use Symfony\Component\Console\Tester\CommandTester;
use Throwable;

final class EventsTest extends TestCase
{
    public function testListenersRunByPriorityThenRegistrationOrderAndReturnTheSameEvent(): void
    {
        $provider = new ListenerProvider();
        $log = new EventsTestLog();
        $provider->listen(stdClass::class, static fn() => $log->add('first'));
        $provider->listen(stdClass::class, static fn() => $log->add('high'), priority: 10);
        $provider->listen(stdClass::class, static fn() => $log->add('second'));
        $provider->listen(stdClass::class, static fn() => $log->add('low'), priority: -5);
        $event = new stdClass();

        self::assertSame($event, (new EventDispatcher($provider))->dispatch($event));
        self::assertSame(['high', 'first', 'second', 'low'], $log->entries);
    }

    public function testListenersForParentClassesAndInterfacesReceiveTheEvent(): void
    {
        $provider = new ListenerProvider();
        $log = new EventsTestLog();
        $provider->listen(EventsTestParentEvent::class, static fn() => $log->add('parent'));
        $provider->listen(Countable::class, static fn() => $log->add('interface'));
        $provider->listen(EventsTestChildEvent::class, static fn() => $log->add('child'));
        $provider->listen(stdClass::class, static fn() => $log->add('unrelated'));

        (new EventDispatcher($provider))->dispatch(new EventsTestChildEvent());
        self::assertSame(['parent', 'interface', 'child'], $log->entries);

        $log->entries = [];
        (new EventDispatcher($provider))->dispatch(new EventsTestParentEvent());
        self::assertSame(['parent'], $log->entries, 'A listener for a subclass does not hear its parent.');
    }

    public function testStoppedEventsSkipLaterListenersIncludingWhenAlreadyStopped(): void
    {
        $provider = new ListenerProvider();
        $log = new EventsTestLog();
        $provider->listen(EventsTestStoppable::class, static function (EventsTestStoppable $event) use ($log): void {
            $log->add('stopper');
            $event->stopPropagation();
        });
        $provider->listen(EventsTestStoppable::class, static fn() => $log->add('after'));
        $dispatcher = new EventDispatcher($provider);

        $event = $dispatcher->dispatch(new EventsTestStoppable());
        self::assertTrue($event->isPropagationStopped());
        self::assertSame(['stopper'], $log->entries);

        $log->entries = [];
        $stopped = new EventsTestStoppable();
        $stopped->stopPropagation();
        $dispatcher->dispatch($stopped);
        self::assertSame([], $log->entries);
    }

    public function testServiceListenersResolveFromTheContainerOnlyWhenReached(): void
    {
        $container = new ServiceContainer();
        $log = new EventsTestLog();
        $container->instance(EventsTestLog::class, $log);
        $provider = new ListenerProvider($container);
        $provider->listen(EventsTestStoppable::class, static fn(EventsTestStoppable $event) => $event->stopPropagation(), priority: 1);
        $provider->listen(EventsTestStoppable::class, EventsTestInvokableListener::class);
        $provider->listen(stdClass::class, EventsTestInvokableListener::class);
        $provider->listen(stdClass::class, [EventsTestMethodListener::class, 'handle']);

        (new EventDispatcher($provider))->dispatch(new EventsTestStoppable());
        self::assertSame([], $log->entries, 'A listener after a stopped event is never constructed.');
        self::assertSame(0, EventsTestInvokableListener::$constructed);

        (new EventDispatcher($provider))->dispatch(new stdClass());
        self::assertSame(['invoked', 'handled'], $log->entries);
    }

    public function testObjectMethodPairsAndInvokableObjectsAreCalledDirectly(): void
    {
        $log = new EventsTestLog();
        $provider = new ListenerProvider();
        $provider->listen(stdClass::class, new EventsTestInvokableListener($log));
        $provider->listen(stdClass::class, [new EventsTestMethodListener($log), 'handle']);

        (new EventDispatcher($provider))->dispatch(new stdClass());
        self::assertSame(['invoked', 'handled'], $log->entries);
    }

    public function testListenersAddedAfterADispatchAreIncludedNextTime(): void
    {
        $provider = new ListenerProvider();
        $log = new EventsTestLog();
        $dispatcher = new EventDispatcher($provider);
        $dispatcher->dispatch(new stdClass());
        $provider->listen(stdClass::class, static fn() => $log->add('late'));
        $dispatcher->dispatch(new stdClass());
        self::assertSame(['late'], $log->entries);
    }

    public function testListenerExceptionsReachTheCaller(): void
    {
        $provider = new ListenerProvider();
        $provider->listen(stdClass::class, static fn() => throw new RuntimeException('listener failed'));
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('listener failed');
        (new EventDispatcher($provider))->dispatch(new stdClass());
    }

    /** @return iterable<string, array{mixed, class-string<Throwable>, string}> */
    public static function invalidListeners(): iterable
    {
        yield 'empty service ID' => ['', InvalidArgumentException::class, 'empty service ID'];
        yield 'wrong pair size' => [[EventsTestMethodListener::class], InvalidArgumentException::class, '[service ID, method] pair'];
        yield 'non-list pair' => [['class' => EventsTestMethodListener::class, 'method' => 'handle'], InvalidArgumentException::class, '[service ID, method] pair'];
        yield 'missing object method' => [[new stdClass(), 'missing'], InvalidArgumentException::class, 'cannot be called: stdClass::missing'];
        yield 'object without __invoke' => [new stdClass(), InvalidArgumentException::class, 'stdClass object without an __invoke()'];
    }

    /** @param class-string<Throwable> $exception */
    #[DataProvider('invalidListeners')]
    public function testInvalidListenersAreRejectedWhenRegistered(mixed $listener, string $exception, string $message): void
    {
        $provider = new ListenerProvider(new ServiceContainer());
        $this->expectException($exception);
        $this->expectExceptionMessage($message);
        $provider->listen(stdClass::class, $listener);
    }

    public function testUnknownEventClassesAreRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Cannot listen for App\Events\Missing: no class or interface has that name.');
        (new ListenerProvider())->listen('App\Events\Missing', static fn() => null);
    }

    public function testServiceListenersNeedAContainer(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('this listener provider has no container');
        (new ListenerProvider())->listen(stdClass::class, EventsTestInvokableListener::class);
    }

    public function testAServiceThatIsNotCallableFailsWhenDispatched(): void
    {
        $provider = new ListenerProvider(new ServiceContainer());
        $provider->listen(stdClass::class, stdClass::class);
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Event listener stdClass is not callable. Give the class an __invoke(object $event) method.');
        (new EventDispatcher($provider))->dispatch(new stdClass());
    }

    public function testDefinitionsAreSortedByEventThenPriority(): void
    {
        $provider = new ListenerProvider(new ServiceContainer());
        $provider->listen(stdClass::class, 'b');
        $provider->listen(Countable::class, 'c');
        $provider->listen(stdClass::class, 'a', priority: 3);
        $provider->listen(stdClass::class, 'd');

        self::assertSame(
            [['Countable', 'c', 0], ['stdClass', 'a', 3], ['stdClass', 'b', 0], ['stdClass', 'd', 0]],
            array_map(static fn(array $d): array => [$d['event'], $d['listener'], $d['priority']], $provider->definitions()),
        );
    }

    public function testRegistryReadsConfiguredListenersAndSharesTheDispatcher(): void
    {
        $container = $this->container(['listeners' => [stdClass::class => [EventsTestInvokableListener::class, [EventsTestMethodListener::class, 'handle']]]]);

        $dispatcher = $container->get(EventDispatcherInterface::class);
        self::assertInstanceOf(EventDispatcher::class, $dispatcher);
        self::assertSame($dispatcher, $container->get(EventDispatcher::class));
        self::assertSame($container->get(ListenerProvider::class), $container->get(ListenerProviderInterface::class));
        $dispatcher->dispatch(new stdClass());
        self::assertSame(['invoked', 'handled'], $container->get(EventsTestLog::class)->entries);
    }

    /** @return iterable<string, array{mixed, string}> */
    public static function invalidConfiguration(): iterable
    {
        yield 'not an array' => ['listeners', 'events.listeners must map event classes to lists of listeners.'];
        yield 'list of listeners' => [[EventsTestInvokableListener::class], 'events.listeners must map event classes to lists of listeners.'];
        yield 'listeners not a list' => [[stdClass::class => ['x' => EventsTestInvokableListener::class]], 'events.listeners must map event classes to lists of listeners.'];
        yield 'listener is a number' => [[stdClass::class => [42]], 'A listener for stdClass in events.listeners must be a service ID'];
    }

    #[DataProvider('invalidConfiguration')]
    public function testRegistryRejectsMalformedConfiguration(mixed $listeners, string $message): void
    {
        $container = $this->container(['listeners' => $listeners]);
        try {
            $container->get(ListenerProvider::class);
            self::fail('The listener provider was built from invalid configuration.');
        } catch (ContainerException $exception) {
            self::assertInstanceOf(InvalidArgumentException::class, $exception->getPrevious());
            self::assertStringContainsString($message, $exception->getPrevious()->getMessage());
        }
    }

    public function testEventListCommandShowsListenersInRunOrder(): void
    {
        $container = $this->container(['listeners' => [stdClass::class => [EventsTestInvokableListener::class]]]);
        $container->get(ListenerProvider::class)->listen(stdClass::class, [EventsTestMethodListener::class, 'handle'], priority: 5);
        $container->get(ListenerProvider::class)->listen(Countable::class, static fn() => null);
        self::assertArrayHasKey('event:list', $container->get(CommandRegistry::class)->all());

        $tester = new CommandTester($container->get(EventListCommand::class));
        self::assertSame(0, $tester->execute(['--json' => true]));
        self::assertSame([
            ['event' => 'Countable', 'priority' => 0, 'listener' => 'Closure'],
            ['event' => 'stdClass', 'priority' => 5, 'listener' => EventsTestMethodListener::class . '::handle'],
            ['event' => 'stdClass', 'priority' => 0, 'listener' => EventsTestInvokableListener::class],
        ], json_decode($tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR));

        self::assertSame(0, $tester->execute(['--event' => 'countable']));
        self::assertStringContainsString('Countable', $tester->getDisplay());
        self::assertStringNotContainsString('stdClass', $tester->getDisplay());
        self::assertStringContainsString('1 listener(s).', $tester->getDisplay());
    }

    protected function setUp(): void
    {
        EventsTestInvokableListener::$constructed = 0;
    }

    /** @param array<string, mixed> $events */
    private function container(array $events): ServiceContainer
    {
        $container = new ServiceContainer();
        $config = new Configuration();
        $config->set('events', $events);
        $container->instance(Configuration::class, $config);
        $container->instance(EventsTestLog::class, new EventsTestLog());
        $container->instance(CommandRegistry::class, new CommandRegistry());
        (new ServiceProviderManager($container))->boot([EventServiceRegistry::class]);

        return $container;
    }
}

final class EventsTestLog
{
    /** @var list<string> */
    public array $entries = [];

    public function add(string $entry): void
    {
        $this->entries[] = $entry;
    }
}

class EventsTestParentEvent {}

final class EventsTestChildEvent extends EventsTestParentEvent implements Countable
{
    public function count(): int
    {
        return 0;
    }
}

final class EventsTestStoppable extends StoppableEvent {}

final class EventsTestInvokableListener
{
    public static int $constructed = 0;

    public function __construct(private readonly EventsTestLog $log)
    {
        self::$constructed++;
    }

    public function __invoke(object $event): void
    {
        $this->log->add('invoked');
    }
}

final class EventsTestMethodListener
{
    public function __construct(private readonly EventsTestLog $log) {}

    public function handle(object $event): void
    {
        $this->log->add('handled');
    }
}
