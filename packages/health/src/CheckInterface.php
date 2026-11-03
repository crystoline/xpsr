<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Health;

/**
 * One health check. name() is the key in the report, such as "database"; check() looks and
 * answers. An exception thrown by check() is a failing result with its message.
 *
 *     final class GatewayCheck implements CheckInterface
 *     {
 *         public function __construct(private readonly Gateway $gateway) {}
 *
 *         public function name(): string
 *         {
 *             return 'gateway';
 *         }
 *
 *         public function check(): Result
 *         {
 *             $balance = $this->gateway->balance();
 *
 *             return $balance > 0 ? Result::ok("Balance {$balance}") : Result::warning('The gateway balance is empty', ['balance' => $balance]);
 *         }
 *     }
 *
 * List it in health.checks.
 */
interface CheckInterface
{
    public function name(): string;

    public function check(): Result;
}
