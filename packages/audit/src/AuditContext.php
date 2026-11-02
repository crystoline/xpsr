<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Audit;

/**
 * Where the current work comes from, for the entries recorded during it: "http" with the
 * client's IP and user agent while AuditContextMiddleware handles a request, otherwise
 * "console". The request ID comes from crystoline/xpsr-log-monolog when it is installed.
 */
final class AuditContext
{
    public string $source = 'console';
    public ?string $ip = null;
    public ?string $userAgent = null;

    public function set(string $source, ?string $ip = null, ?string $userAgent = null): void
    {
        $this->source = $source;
        $this->ip = $ip;
        $this->userAgent = $userAgent;
    }

    public function clear(): void
    {
        $this->set('console');
    }
}
