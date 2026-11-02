<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Audit;

use BackedEnum;
use DateTimeInterface;
use Stringable;
use UnitEnum;

/**
 * The changes of an entry: field => [before, after], with excluded fields dropped, redacted
 * fields hidden, and values made storable as JSON.
 *
 * @internal
 */
final class Changes
{
    /**
     * @param array<string, mixed> $before
     * @param array<string, mixed> $after
     * @param list<string> $except
     * @param list<string> $redact
     * @return array<string, array{mixed, mixed}>
     */
    public static function between(array $before, array $after, array $except, array $redact): array
    {
        $changes = [];
        foreach (array_unique([...array_keys($before), ...array_keys($after)]) as $field) {
            if (in_array($field, $except, true)) {
                continue;
            }
            $old = self::value($before[$field] ?? null);
            $new = self::value($after[$field] ?? null);
            if ($old === $new) {
                continue;
            }
            $changes[$field] = in_array($field, $redact, true)
                ? [$old === null ? null : AuditOptions::REDACTED, $new === null ? null : AuditOptions::REDACTED]
                : [$old, $new];
        }

        return $changes;
    }

    /**
     * Changes given by hand, checked and normalized.
     *
     * @param array<string, array{mixed, mixed}|mixed> $changes field => [before, after]
     * @param list<string> $redact
     * @return array<string, array{mixed, mixed}>
     */
    public static function given(array $changes, array $redact): array
    {
        $normalized = [];
        foreach ($changes as $field => $change) {
            [$old, $new] = is_array($change) && array_is_list($change) && count($change) === 2 ? $change : [null, $change];
            $normalized[$field] = in_array($field, $redact, true)
                ? [$old === null ? null : AuditOptions::REDACTED, $new === null ? null : AuditOptions::REDACTED]
                : [self::value($old), self::value($new)];
        }

        return $normalized;
    }

    /** A value as JSON can hold it. */
    public static function value(mixed $value): mixed
    {
        return match (true) {
            $value === null, is_scalar($value) => $value,
            $value instanceof DateTimeInterface => $value->format(DateTimeInterface::ATOM),
            $value instanceof BackedEnum => $value->value,
            $value instanceof UnitEnum => $value->name,
            is_array($value) => array_map(self::value(...), $value),
            $value instanceof Stringable => (string) $value,
            is_object($value) => method_exists($value, 'getId') ? $value::class . ' ' . Keys::id($value->getId()) : $value::class,
            default => get_debug_type($value),
        };
    }
}
