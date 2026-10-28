<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Routing;

use InvalidArgumentException;

/**
 * The portable grammar deliberately excludes optional segments and vendor regex extensions.
 *
 * @internal Not covered by the backward-compatibility promise; see docs/VERSIONING.md.
 */
final class RoutePattern
{
    /** @var list<string|array{string, string}> */
    private array $parts = [];
    /** @var array<string, string> */
    private array $parameters = [];

    public function __construct(public readonly string $path, array $constraints = [])
    {
        if (str_starts_with($path, '//') || !preg_match('#\A/(?:[A-Za-z0-9._~/-]|\{[a-zA-Z_][a-zA-Z0-9_]{0,31}\})*\z#D', $path)) {
            throw new InvalidArgumentException("Unsupported route path: {$path}");
        }
        foreach (explode('/', $path) as $segment) {
            if ($segment === '.' || $segment === '..'
                || (str_contains($segment, '{') && !preg_match('/\A\{[a-zA-Z_][a-zA-Z0-9_]*\}\z/', $segment))) {
                throw new InvalidArgumentException('Placeholders must occupy a whole segment; dot segments are unsupported.');
            }
        }
        $parts = preg_split('/(\{[a-zA-Z_][a-zA-Z0-9_]*\})/', $path, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY);
        foreach ($parts as $part) {
            if ($part[0] !== '{') {
                $this->parts[] = $part;
                continue;
            }
            $name = substr($part, 1, -1);
            if (isset($this->parameters[$name]) || in_array($name, ['_route', '_route_params'], true)) {
                throw new InvalidArgumentException("Duplicate or reserved route parameter: {$name}");
            }
            $regex = $constraints[$name] ?? '[^/]+';
            if (isset($constraints[$name])) {
                self::validateConstraint($regex);
            }
            $this->parameters[$name] = $regex;
            $this->parts[] = [$name, $regex];
        }
        if (array_diff_key($constraints, $this->parameters) !== []) {
            throw new InvalidArgumentException('A constraint references an unknown route parameter.');
        }
    }

    /** @return list<string|array{string, string}> */
    public function parts(): array
    {
        return $this->parts;
    }

    public function isStatic(): bool
    {
        return $this->parameters === [];
    }

    public function signature(): string
    {
        return implode('', array_map(static fn($part) => is_string($part) ? $part : '{:' . $part[1] . '}', $this->parts));
    }

    public function generate(array $parameters): string
    {
        if (array_diff_key($parameters, $this->parameters) !== []) {
            throw new InvalidArgumentException('Unknown URL parameter; pass query parameters separately.');
        }
        $path = '';
        foreach ($this->parts as $part) {
            $path .= is_string($part) ? $part : $this->encode($part[0], $parameters[$part[0]] ?? null);
        }
        return $path;
    }

    public function encode(string $name, mixed $value): string
    {
        if (!isset($this->parameters[$name]) || (!is_string($value) && !is_int($value))) {
            throw new InvalidArgumentException("Missing or invalid route parameter: {$name}");
        }
        $value = (string) $value;
        if (!self::validSegment($value) || !preg_match('#\A(?:' . $this->parameters[$name] . ')\z#uD', $value)) {
            throw new InvalidArgumentException("Route parameter does not satisfy its constraint: {$name}");
        }
        return rawurlencode($value);
    }

    public static function normalizePath(string $path): ?string
    {
        if ($path === '') {
            return '/';
        }
        if ($path[0] !== '/' || preg_match('/%(?![a-fA-F0-9]{2})/', $path)) {
            return null;
        }
        $segments = explode('/', $path);
        foreach ($segments as &$segment) {
            if ($segment === '') {
                continue;
            }
            $decoded = rawurldecode($segment);
            if (!self::validSegment($decoded)) {
                return null;
            }
            $segment = rawurlencode($decoded);
        }
        return implode('/', $segments);
    }

    /** @var array<string, true> Constraints already validated in this process. */
    private static array $validConstraints = [];

    private static function validateConstraint(mixed $regex): void
    {
        if (is_string($regex) && isset(self::$validConstraints[$regex])) {
            return;
        }
        if (!is_string($regex) || ($regex !== '\d+' && !preg_match('/\A\[[A-Za-z0-9_-]+\]\+\z/', $regex))
            || @preg_match('#\A(?:' . $regex . ')\z#D', '') === false) {
            throw new InvalidArgumentException('Portable constraints support \d+ or a positive ASCII character class followed by +.');
        }
        // Ranges such as A-z include punctuation which would be percent-encoded.
        // Keep constrained values identical in their decoded and wire representations.
        for ($code = 0; $code < 128; ++$code) {
            $character = chr($code);
            if (preg_match('#\A(?:' . $regex . ')\z#D', $character)
                && !preg_match('/\A[A-Za-z0-9_-]\z/', $character)) {
                throw new InvalidArgumentException('Constraint ranges may only match ASCII letters, digits, underscores, and hyphens.');
            }
        }
        self::$validConstraints[$regex] = true;
    }

    private static function validSegment(string $value): bool
    {
        return $value !== '' && $value !== '.' && $value !== '..'
            && !preg_match('#[/\\\\\x00-\x1F\x7F]#', $value) && preg_match('//u', $value) === 1;
    }
}
