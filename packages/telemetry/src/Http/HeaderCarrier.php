<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Telemetry\Http;

use OpenTelemetry\Context\Propagation\PropagationGetterInterface;
use Psr\Http\Message\MessageInterface;

/**
 * Reads the trace context headers (traceparent, tracestate) from a PSR-7 message.
 *
 * @internal
 */
final class HeaderCarrier implements PropagationGetterInterface
{
    /** @return list<string> */
    public function keys($carrier): array
    {
        return $carrier instanceof MessageInterface ? array_map('strval', array_keys($carrier->getHeaders())) : [];
    }

    public function get($carrier, string $key): ?string
    {
        if (!$carrier instanceof MessageInterface || !$carrier->hasHeader($key)) {
            return null;
        }

        return $carrier->getHeader($key)[0] ?? null;
    }
}
