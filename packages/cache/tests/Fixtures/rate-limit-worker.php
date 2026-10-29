<?php

declare(strict_types=1);

// One process of RateLimitConcurrencyTest. Boots an application, waits for the shared start
// time, counts attempts against one key, and prints how many were accepted.
//
// Usage: php rate-limit-worker.php <autoload.php> <root> <settings JSON> <start time>

use Crystoline\Xpsr\Application;
use Crystoline\Xpsr\Cache\RateLimit\RateLimits;
use Crystoline\Xpsr\ServiceContainer\ServiceContainer;
use Symfony\Component\Cache\Adapter\RedisAdapter;

[, $autoload, $root, $json, $start] = $_SERVER['argv'];
require $autoload;

/** @var array{config: array<string, mixed>, redis: ?string, key: string, attempts: int} $settings */
$settings = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
$container = new ServiceContainer();
$redis = $settings['redis'];
if ($redis !== null) {
    $container->factory('cache.redis', static fn(): RedisAdapter => new RedisAdapter(RedisAdapter::createConnection($redis), 'xpsr_rate_limit_test'), shared: true);
}
$application = new Application($root, $container);
foreach ($settings['config'] as $key => $value) {
    $application->getConfig()->set($key, $value);
}
$application->boot();
$limits = $application->get(RateLimits::class);
if (!$limits instanceof RateLimits) {
    throw new LogicException('RateLimits is not registered.');
}

time_sleep_until((float) $start);
$accepted = 0;
for ($i = 0; $i < $settings['attempts']; $i++) {
    if ($limits->hit('burst', $settings['key'])->accepted) {
        $accepted++;
    }
}
echo $accepted;
