<?php

declare(strict_types=1);

namespace Crystoline\Tests\Unit;

use Crystoline\ServiceContainer\ContainerException;
use Crystoline\ServiceContainer\ServiceContainer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;
use RuntimeException;
use stdClass;
use Crystoline\Tests\Fixtures\Container as Fixture;

final class ServiceContainerTest extends TestCase
{
    public function testHasDoesNotInvokeFactoriesOrConstructServices(): void
    {
        $container = new ServiceContainer();
        $calls = 0;
        $container->factory('service', static function () use (&$calls): stdClass {
            ++$calls;
            return new stdClass();
        });
        self::assertTrue($container->has('service'));
        self::assertTrue($container->has(Fixture\FailingConstructor::class));
        self::assertSame(0, $calls);
        $container->get('service');
        self::assertSame(1, $calls);
    }

    public static function storedValues(): array
    {
        return [[null], [false], [0], [''], ['strlen'], [Fixture\Dependency::class], [['a' => 1]]];
    }

    #[DataProvider('storedValues')]
    public function testLiteralValuesAreKnownAndPreserved(mixed $value): void
    {
        $container = new ServiceContainer();
        $container->instance('value', $value);
        self::assertTrue($container->has('value'));
        self::assertSame($value, $container->get('value'));
    }

    public function testTransientClassesSharedClassesAndAliasesHaveExplicitLifetimes(): void
    {
        $container = new ServiceContainer();
        $container->bind('transient', Fixture\Dependency::class);
        $container->singleton('shared', Fixture\Dependency::class);
        $container->alias('alias', 'shared');
        self::assertNotSame($container->get('transient'), $container->get('transient'));
        self::assertSame($container->get('shared'), $container->get('shared'));
        self::assertSame($container->get('shared'), $container->get('alias'));
        $container->bind(Fixture\Dependency::class);
        self::assertInstanceOf(Fixture\Dependency::class, $container->get(Fixture\Dependency::class));
    }

    public function testSharedFactoriesCacheNullAndRebindingInvalidatesTheirValue(): void
    {
        $container = new ServiceContainer();
        $calls = 0;
        $container->factory('nullable', static function (ContainerInterface $received) use ($container, &$calls) {
            self::assertSame($container, $received);
            ++$calls;
            return null;
        }, shared: true);
        self::assertNull($container->get('nullable'));
        self::assertNull($container->get('nullable'));
        self::assertSame(1, $calls);
        $container->alias('alias', 'nullable');
        $container->instance('nullable', 42);
        self::assertSame(42, $container->get('alias'));
        $container->factory('nullable', static fn() => new stdClass());
        self::assertNotSame($container->get('nullable'), $container->get('nullable'));
    }

    public function testInvokableObjectsAndClosuresRemainServicesUntilRegisteredAsFactories(): void
    {
        $container = new ServiceContainer();
        $invokable = $container->get(Fixture\InvokableService::class);
        self::assertInstanceOf(Fixture\InvokableService::class, $invokable);
        self::assertSame(0, $invokable->calls);
        $container->instance('object', $invokable);
        self::assertSame($invokable, $container->get('object'));
        $closure = static fn() => 'closure result';
        $container->instance('closure', $closure);
        self::assertSame($closure, $container->get('closure'));
        $container->factory('invoked', $invokable);
        self::assertSame('called', $container->get('invoked'));
        self::assertSame(1, $invokable->calls);
    }

    public static function missingIdentifiers(): array
    {
        return [['absent'], [''], [Fixture\MissingDependency::class], [Fixture\PrivateConstructor::class]];
    }

    #[DataProvider('missingIdentifiers')]
    public function testUnknownIdentifiersHaveConsistentPsrExceptions(string $id): void
    {
        $container = new ServiceContainer();
        self::assertFalse($container->has($id));
        $this->expectException(NotFoundExceptionInterface::class);
        $container->get($id);
    }

    public function testKnownServiceWithMissingDependencyIsNotMisreportedAsAnUnknownService(): void
    {
        $container = new ServiceContainer();
        $container->alias('alias', 'missing');
        self::assertTrue($container->has('alias'));
        try {
            $container->get('alias');
            self::fail('Expected resolution to fail.');
        } catch (ContainerExceptionInterface $exception) {
            self::assertNotInstanceOf(NotFoundExceptionInterface::class, $exception);
            self::assertInstanceOf(NotFoundExceptionInterface::class, $exception->getPrevious());
        }
    }

    public function testAutowiringResolvesDependenciesDefaultsNullableAndParentTypes(): void
    {
        $container = new ServiceContainer();
        $container->singleton(Fixture\Dependency::class);
        $consumer = $container->get(Fixture\OptionalConsumer::class);
        self::assertSame($container->get(Fixture\Dependency::class), $consumer->dependency);
        self::assertSame(7, $consumer->count);
        self::assertNull($consumer->optional);
        self::assertNull($container->get(Fixture\NullableConsumer::class)->dependency);
        self::assertInstanceOf(Fixture\ParentDependency::class, $container->get(Fixture\ParentConsumer::class)->dependency);
        self::assertSame([], $container->get(Fixture\VariadicConsumer::class)->dependencies);
        self::assertSame($container, $container->get(Fixture\ContainerConsumer::class)->container);
    }

    public static function nonAutowirableTypes(): array
    {
        return [
            [Fixture\ScalarConsumer::class],
            [Fixture\UntypedConsumer::class],
            [Fixture\UnionConsumer::class],
            [Fixture\IntersectionConsumer::class],
            [Fixture\RequiredConsumer::class],
        ];
    }

    #[DataProvider('nonAutowirableTypes')]
    public function testUnsupportedParametersRequireAnExplicitFactory(string $class): void
    {
        $container = new ServiceContainer();
        self::assertTrue($container->has($class));
        $this->expectException(ContainerException::class);
        $this->expectExceptionMessage('Register an explicit factory');
        $container->get($class);
    }

    public function testScalarDependenciesCanBeProvidedByFactoryWithoutReadingSessionState(): void
    {
        $previous = $_SESSION ?? null;
        $_SESSION['token'] = 'session value';
        try {
            $container = new ServiceContainer();
            try {
                $container->get(Fixture\UntypedConsumer::class);
                self::fail('Untyped parameters must not read the session.');
            } catch (ContainerException $exception) {
                self::assertStringContainsString('untyped parameter', $exception->getMessage());
            }
            $container->instance('token', 'configured value');
            $container->factory(Fixture\ScalarConsumer::class, static fn(ContainerInterface $c) => new Fixture\ScalarConsumer($c->get('token')));
            self::assertSame('configured value', $container->get(Fixture\ScalarConsumer::class)->token);
        } finally {
            if ($previous === null) {
                unset($_SESSION);
            } else {
                $_SESSION = $previous;
            }
        }
    }

    public function testClassAndAliasCyclesReportTheResolutionPathAndLeaveContainerUsable(): void
    {
        $container = new ServiceContainer();
        try {
            $container->get(Fixture\CycleA::class);
            self::fail('Expected a circular dependency.');
        } catch (ContainerException $exception) {
            self::assertStringContainsString(Fixture\CycleA::class . ' -> ' . Fixture\CycleB::class . ' -> ' . Fixture\CycleA::class, $exception->getMessage());
        }
        $container->alias('a', 'b');
        $container->alias('b', 'a');
        try {
            $container->get('a');
            self::fail('Expected an alias cycle.');
        } catch (ContainerException $exception) {
            self::assertStringContainsString('a -> b -> a', $exception->getMessage());
        }
        $container->instance('b', 'repaired');
        self::assertSame('repaired', $container->get('a'));
        self::assertInstanceOf(Fixture\Dependency::class, $container->get(Fixture\Dependency::class));
    }

    public function testFactoryAndConstructorErrorsPreserveTheirCauses(): void
    {
        $container = new ServiceContainer();
        $cause = new RuntimeException('Factory failed');
        $container->factory('failure', static fn() => throw $cause);
        try {
            $container->get('failure');
            self::fail('Expected a factory failure.');
        } catch (ContainerException $exception) {
            self::assertSame($cause, $exception->getPrevious());
        }
        try {
            $container->get(Fixture\FailingConstructor::class);
            self::fail('Expected a constructor failure.');
        } catch (ContainerException $exception) {
            self::assertSame('Constructor failure', $exception->getPrevious()->getMessage());
        }
    }

    public function testRebindingDuringResolutionFailsInsteadOfCachingAnObsoleteResult(): void
    {
        $container = new ServiceContainer();
        $container->factory('self', static function () use ($container): void {
            $container->instance('self', 'replacement');
        }, shared: true);
        $this->expectException(ContainerException::class);
        $this->expectExceptionMessage('while it is being resolved');
        $container->get('self');
    }

    public function testDeferredRegistrationRunsOnceAndPreservesSingletonOnFirstAccess(): void
    {
        $container = new ServiceContainer();
        $calls = 0;
        $container->defer(['first', 'second'], static function () use ($container, &$calls): void {
            ++$calls;
            $container->singleton('first', Fixture\Dependency::class);
            $container->instance('second', null);
        });
        self::assertTrue($container->has('first'));
        self::assertTrue($container->has('second'));
        self::assertSame(0, $calls);
        self::assertSame($container->get('first'), $container->get('first'));
        self::assertNull($container->get('second'));
        self::assertSame(1, $calls);
    }

    public function testIncompleteDeferredRegistrationFailsOnceForEveryAdvertisedService(): void
    {
        $container = new ServiceContainer();
        $calls = 0;
        $container->defer(['first', 'missing'], static function () use ($container, &$calls): void {
            ++$calls;
            $container->instance('first', 'partial result');
        });
        foreach (['first', 'missing', 'first'] as $id) {
            try {
                $container->get($id);
                self::fail('Partial deferred registrations must not be returned.');
            } catch (ContainerException $exception) {
                self::assertStringContainsString('advertised service "missing"', $exception->getMessage());
            }
        }
        self::assertSame(1, $calls);
        $container->instance('first', 'explicit recovery');
        self::assertSame('explicit recovery', $container->get('first'));
    }

    public function testDeferredRegistrationCycleFailsClearly(): void
    {
        $container = new ServiceContainer();
        $container->defer(['first', 'second'], static fn() => $container->get('second'));
        $this->expectException(ContainerException::class);
        $this->expectExceptionMessage('Circular deferred registration');
        $container->get('first');
    }

    public static function invalidDeferredDeclarations(): array
    {
        return [[[]], [['duplicate', 'duplicate']], [['']], [['valid', 12]], [['valid', new stdClass()]]];
    }

    #[DataProvider('invalidDeferredDeclarations')]
    public function testDeferredDeclarationsAreValidatedBeforeAnythingIsRegistered(array $ids): void
    {
        $container = new ServiceContainer();
        try {
            $container->defer($ids, static function (): void {});
            self::fail('Expected invalid declaration to be rejected.');
        } catch (ContainerException $exception) {
            self::assertFalse($container->has('valid'));
            self::assertNotSame('', $exception->getMessage());
        }
    }

    public function testKnownBindingToAnInvalidClassRaisesAContainerError(): void
    {
        $container = new ServiceContainer();
        $container->bind('invalid', Fixture\PrivateConstructor::class);
        self::assertTrue($container->has('invalid'));
        $this->expectException(ContainerException::class);
        $this->expectExceptionMessage('not instantiable');
        $container->get('invalid');
    }

    public function testEmptyBindingIdentifiersAreRejected(): void
    {
        $this->expectException(ContainerException::class);
        (new ServiceContainer())->instance('', null);
    }

    public function testDeferredDefinitionsCannotSilentlyOverwriteExistingBindings(): void
    {
        $container = new ServiceContainer();
        $container->instance('existing', 1);
        $this->expectException(ContainerException::class);
        $container->defer(['existing'], static function (): void {});
    }

    public function testPendingDeferredDefinitionsRequireProviderReplacementOrPriorLoadingToRebind(): void
    {
        $container = new ServiceContainer();
        $container->defer(['pending'], static fn() => $container->instance('pending', 1));
        try {
            $container->instance('pending', 2);
            self::fail('Must not silently override a pending provider.');
        } catch (ContainerException $exception) {
            self::assertStringContainsString('replace its provider', $exception->getMessage());
        }
        self::assertSame(1, $container->get('pending'));
        $container->instance('pending', 2);
        self::assertSame(2, $container->get('pending'));
    }
}
