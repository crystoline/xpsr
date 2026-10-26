<?php

declare(strict_types=1);

namespace Crystoline\Tests\Unit;

use Crystoline\Foundation\ServiceProviderManager;
use Crystoline\ServiceContainer\ContainerException;
use Crystoline\ServiceContainer\ServiceContainer;
use Crystoline\ServiceContainer\ServiceRegistry;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use stdClass;

final class ServiceProviderManagerTest extends TestCase
{
    private ServiceContainer $container;
    private stdClass $log;

    protected function setUp(): void
    {
        $this->container = new ServiceContainer();
        $this->log = (object) ['events' => []];
        $this->container->instance('log', $this->log);
    }

    public function testAllEagerProvidersRegisterBeforeBootingAndDuplicatesRunOnlyOnce(): void
    {
        $first = new class ($this->container) extends ServiceRegistry {
            public function register(): void
            {
                $this->container->get('log')->events[] = 'first.register';
            }

            public function boot(): void
            {
                $this->container->get('log')->events[] = 'first.boot:' . $this->container->get('later');
            }
        };
        $second = new class ($this->container) extends ServiceRegistry {
            public function register(): void
            {
                $this->container->get('log')->events[] = 'second.register';
                $this->container->instance('later', 'ready');
            }

            public function boot(): void
            {
                $this->container->get('log')->events[] = 'second.boot';
            }
        };
        $manager = new ServiceProviderManager($this->container);
        $classes = [$first::class, $second::class, $first::class];
        $manager->boot($classes);
        $manager->boot($classes);
        self::assertSame(['first.register', 'second.register', 'first.boot:ready', 'second.boot'], $this->log->events);
    }

    public function testDeferredProviderRegistersAndBootsOnceOnFirstResolution(): void
    {
        $provider = new class ($this->container) extends ServiceRegistry {
            protected bool $delayed = true;

            public function providedServices(): array
            {
                return ['lazy.first', 'lazy.second'];
            }

            public function register(): void
            {
                $this->container->get('log')->events[] = 'register';
                $this->container->singleton('lazy.first', stdClass::class);
                $this->container->instance('lazy.second', 2);
            }

            public function boot(): void
            {
                // Consuming an already registered service here is valid.
                $this->container->get('lazy.first')->booted = true;
                $this->container->get('log')->events[] = 'boot';
            }
        };
        $manager = new ServiceProviderManager($this->container);
        $manager->boot([$provider::class]);
        self::assertTrue($this->container->has('lazy.first'));
        self::assertTrue($this->container->has('lazy.second'));
        self::assertSame([], $this->log->events);
        $first = $this->container->get('lazy.first');
        self::assertTrue($first->booted);
        self::assertSame($first, $this->container->get('lazy.first'));
        self::assertSame(2, $this->container->get('lazy.second'));
        $manager->boot([$provider::class]);
        self::assertSame(['register', 'boot'], $this->log->events);
    }

    public function testDeferredProviderUsedDuringRegistrationWaitsUntilTheBootPhase(): void
    {
        $lazy = new class ($this->container) extends ServiceRegistry {
            protected bool $delayed = true;

            public function providedServices(): array
            {
                return ['lazy'];
            }

            public function register(): void
            {
                $this->container->get('log')->events[] = 'lazy.register';
                $this->container->instance('lazy', 'ready');
            }

            public function boot(): void
            {
                $this->container->get('log')->events[] = 'lazy.boot:' . $this->container->get('last');
            }
        };
        $eager = new class ($this->container) extends ServiceRegistry {
            public function register(): void
            {
                $this->container->get('lazy');
                $this->container->get('log')->events[] = 'eager.register';
                $this->container->instance('last', 'ready');
            }

            public function boot(): void
            {
                $this->container->get('log')->events[] = 'eager.boot';
            }
        };
        (new ServiceProviderManager($this->container))->boot([$lazy::class, $eager::class]);
        self::assertSame(['lazy.register', 'eager.register', 'lazy.boot:ready', 'eager.boot'], $this->log->events);
    }

    public function testFailedDeferredBootDoesNotExposePartiallyInitializedServicesOrRetry(): void
    {
        $provider = new class ($this->container) extends ServiceRegistry {
            protected bool $delayed = true;

            public function providedServices(): array
            {
                return ['broken'];
            }

            public function register(): void
            {
                $this->container->get('log')->events[] = 'register';
                $this->container->instance('broken', 'partial');
            }

            public function boot(): void
            {
                $this->container->get('log')->events[] = 'boot';
                throw new RuntimeException('Boot failed');
            }
        };
        (new ServiceProviderManager($this->container))->boot([$provider::class]);
        for ($attempt = 0; $attempt < 2; ++$attempt) {
            try {
                $this->container->get('broken');
                self::fail('A failed provider must not expose partial state.');
            } catch (ContainerException $exception) {
                self::assertStringContainsString('Boot failed', $exception->getMessage());
            }
        }
        self::assertSame(['register', 'boot'], $this->log->events);
    }

    public function testFailedEagerLifecycleDoesNotRepeatSideEffects(): void
    {
        $provider = new class ($this->container) extends ServiceRegistry {
            public function register(): void
            {
                $this->container->get('log')->events[] = 'register';
            }

            public function boot(): void
            {
                $this->container->get('log')->events[] = 'boot';
                throw new RuntimeException('Boot failed');
            }
        };
        $manager = new ServiceProviderManager($this->container);
        for ($attempt = 0; $attempt < 2; ++$attempt) {
            try {
                $manager->boot([$provider::class]);
                self::fail('Expected provider failure.');
            } catch (RuntimeException $exception) {
                self::assertStringContainsString('Boot failed', $exception->getMessage());
            }
        }
        self::assertSame(['register', 'boot'], $this->log->events);
    }

    public function testMissingDeferredServiceDeclarationIsRejected(): void
    {
        $provider = new class ($this->container) extends ServiceRegistry {
            protected bool $delayed = true;

            public function register(): void {}
        };
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('non-empty list');
        (new ServiceProviderManager($this->container))->boot([$provider::class]);
    }
}
