<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Health;

use Crystoline\Xpsr\Health\Event\HealthChecked;
use InvalidArgumentException;
use Psr\Clock\ClockInterface;
use Psr\Container\ContainerInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\SimpleCache\CacheInterface;
use Throwable;

/**
 * Runs the checks and gives a Report. A report is kept in the cache for health.cache seconds,
 * so frequent probes do not run the checks each time; check(fresh: true) runs them regardless.
 * Each check is timed, and one that throws is a failing result with the exception's message.
 */
final class Health
{
    public const CACHE_KEY = 'xpsr.health.report';

    /** @var array<string, CheckInterface>|null */
    private ?array $resolved = null;

    /**
     * @param list<string|CheckInterface> $checks service IDs or instances
     * @param list<string> $skip names of checks not to run
     */
    public function __construct(
        private readonly array $checks,
        private readonly array $skip,
        private readonly ContainerInterface $container,
        private readonly ClockInterface $clock,
        private readonly ?CacheInterface $cache = null,
        private readonly int $cacheSeconds = 0,
        private readonly ?EventDispatcherInterface $events = null,
    ) {}

    public function check(bool $fresh = false): Report
    {
        $cached = $this->cacheSeconds > 0 && $this->cache !== null;
        if (!$fresh && $cached) {
            $report = $this->cache->get(self::CACHE_KEY);
            if ($report instanceof Report) {
                return $report;
            }
        }
        $results = [];
        foreach ($this->checks() as $name => $check) {
            $results[$name] = $this->run($check);
        }
        $report = new Report($results, $this->clock->now());
        if ($cached) {
            $this->cache->set(self::CACHE_KEY, $report, $this->cacheSeconds);
        }
        $this->events?->dispatch(new HealthChecked($report));

        return $report;
    }

    /** The checks that run, by name. @return array<string, CheckInterface> */
    public function checks(): array
    {
        if ($this->resolved !== null) {
            return $this->resolved;
        }
        $checks = [];
        foreach ($this->checks as $entry) {
            $check = is_string($entry) ? $this->container->get($entry) : $entry;
            if (!$check instanceof CheckInterface) {
                throw new InvalidArgumentException(sprintf('Health check %s must implement %s.', $entry, CheckInterface::class));
            }
            $name = $check->name();
            if (in_array($name, $this->skip, true)) {
                continue;
            }
            if (isset($checks[$name])) {
                throw new InvalidArgumentException("Two health checks are named \"{$name}\".");
            }
            $checks[$name] = $check;
        }

        return $this->resolved = $checks;
    }

    private function run(CheckInterface $check): Result
    {
        $start = hrtime(true);
        try {
            $result = $check->check();
        } catch (Throwable $exception) {
            $result = Result::failing($exception->getMessage(), ['exception' => $exception::class]);
        }

        return $result->timed((hrtime(true) - $start) / 1_000_000);
    }
}
