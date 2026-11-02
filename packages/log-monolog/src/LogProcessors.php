<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Log\Monolog;

use Monolog\Processor\ProcessorInterface;

/**
 * Monolog processors other providers add to the logger, as service IDs, instances, or
 * callables, in boot(). The logger gets them, after its own, when it is built, so add them
 * before anything logs: list the provider that adds one after MonologServiceRegistry.
 * Processors named in logging.processors come first.
 *
 *     $this->container->get(LogProcessors::class)->add(TraceContextProcessor::class);
 */
final class LogProcessors
{
    /** @var list<string|ProcessorInterface|callable> */
    private array $processors = [];

    public function add(string|ProcessorInterface|callable $processor): void
    {
        $this->processors[] = $processor;
    }

    /** @return list<string|ProcessorInterface|callable> */
    public function all(): array
    {
        return $this->processors;
    }
}
