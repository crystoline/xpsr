<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Health\Checks;

use Crystoline\Xpsr\Health\CheckInterface;
use Crystoline\Xpsr\Health\Result;
use Psr\SimpleCache\CacheInterface;

/** Writes a value to the application cache, reads it back, and deletes it. */
final class CacheCheck implements CheckInterface
{
    public const KEY = 'xpsr.health.probe';

    public function __construct(private readonly CacheInterface $cache) {}

    public function name(): string
    {
        return 'cache';
    }

    public function check(): Result
    {
        $value = bin2hex(random_bytes(8));
        $this->cache->set(self::KEY, $value, 60);
        $read = $this->cache->get(self::KEY);
        $this->cache->delete(self::KEY);
        if ($read !== $value) {
            return Result::failing('The cache did not return what was written', ['written' => $value, 'read' => is_scalar($read) || $read === null ? $read : get_debug_type($read)]);
        }

        return Result::ok('Written and read back');
    }
}
