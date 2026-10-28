<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Validation;

use BackedEnum;
use Crystoline\Xpsr\Validation\Attribute\Field;
use Crystoline\Xpsr\Validation\Attribute\Filter;
use Crystoline\Xpsr\Validation\Attribute\Input;
use Crystoline\Xpsr\Validation\Attribute\SwitchableFilter;
use DateTime;
use DateTimeImmutable;
use DateTimeInterface;
use LogicException;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ServerRequestInterface;
use ReflectionAttribute;
use ReflectionClass;
use ReflectionNamedType;
use ReflectionParameter;
use ReflectionType;
use ReflectionUnionType;

/**
 * Builds input objects from request data.
 *
 * An input class lists its fields as constructor parameters. For each one the mapper finds the
 * field (by #[Field], #[Input(naming:)], or the parameter name), applies its filters, converts the
 * value to the parameter type, and passes it to the constructor. Fields the class does not name
 * are ignored. Every problem is collected, and a ValidationException lists them all:
 *
 *   missing field without a default          "This field is missing."
 *   null for a type that does not allow it   "This value should not be null."
 *   text that filters emptied to null        "This value should not be blank."
 *   a value that cannot be converted         "This value should be of type int."
 *
 * Conversions: int, float, and bool accept matching strings ("42", "2.5", "true"/"1"/"on"), as
 * query strings and forms send them; string and array accept only those types; DateTimeImmutable
 * and DateTimeInterface parse a date string; a backed enum takes one of its values; a parameter
 * typed with another class with a constructor is a nested input object, read from an array.
 * Untyped and mixed parameters receive the value as sent.
 *
 * Rules beyond types (length, format, ranges) belong to the ValidatorInterface implementation.
 */
final class InputMapper
{
    public const SOURCES = ['body', 'query', 'route'];

    /** @var array<class-string, array<string, array{field: string, parameter: ReflectionParameter, filters: list<FilterInterface>}>> */
    private array $classes = [];

    public function __construct(private readonly ?ContainerInterface $container = null) {}

    /**
     * The data of a request source: the parsed body, the query string, or the route parameters.
     *
     * @param string $from body, query, or route
     * @return array<array-key, mixed>
     */
    public static function data(ServerRequestInterface $request, string $from): array
    {
        $data = match ($from) {
            'body' => $request->getParsedBody(),
            'query' => $request->getQueryParams(),
            'route' => $request->getAttribute('_route_params', []),
            default => throw new \InvalidArgumentException(sprintf('Input comes from %s, not "%s".', implode(', ', self::SOURCES), $from)),
        };
        if ($data === null) {
            return [];
        }
        if (is_object($data)) {
            $data = get_object_vars($data);
        }
        if (!is_array($data) || ($data !== [] && array_is_list($data))) {
            throw new ValidationException([$from => ['This value should be of type object.']]);
        }

        return $data;
    }

    /**
     * @template T of object
     * @param array<array-key, mixed> $data
     * @param class-string<T> $class
     * @return T
     * @throws ValidationException
     */
    public function map(array $data, string $class): object
    {
        $errors = [];
        $object = $this->build($data, $class, '', $errors);
        if ($errors !== [] || $object === null) {
            throw new ValidationException($errors === [] ? ['' => ['The input is invalid.']] : $errors);
        }

        return $object;
    }

    /**
     * The client's name for a property path from a validator, such as "dueAt" or
     * "address.postCode", following nested input classes: "due_at", "address.post_code".
     *
     * @param class-string $class
     */
    public function fieldPath(string $class, string $propertyPath): string
    {
        $path = [];
        $current = $class;
        foreach (explode('.', $propertyPath) as $segment) {
            preg_match('/\A([^\[]*)(.*)\z/', $segment, $parts);
            [$property, $index] = [$parts[1] ?? $segment, $parts[2] ?? ''];
            $field = $property;
            $next = null;
            if ($current !== null && $property !== '') {
                $fields = $this->fields($current);
                if (isset($fields[$property])) {
                    $field = $fields[$property]['field'];
                    $type = $fields[$property]['parameter']->getType();
                    $next = $index === '' && $type instanceof ReflectionNamedType && !$type->isBuiltin() && self::isInputClass($type->getName()) ? $type->getName() : null;
                }
            }
            $path[] = $field . $index;
            $current = $next;
        }

        return implode('.', $path);
    }

    /**
     * @param array<array-key, mixed> $data
     * @param class-string $class
     * @param array<string, list<string>> $errors
     */
    private function build(array $data, string $class, string $prefix, array &$errors): ?object
    {
        $before = count($errors);
        $arguments = [];
        foreach ($this->fields($class) as $name => $definition) {
            $parameter = $definition['parameter'];
            $path = $prefix . $definition['field'];
            if (!array_key_exists($definition['field'], $data)) {
                if ($parameter->isDefaultValueAvailable()) {
                    continue;
                }
                if ($parameter->allowsNull()) {
                    $arguments[$name] = null;
                } else {
                    $errors[$path][] = 'This field is missing.';
                }
                continue;
            }
            $value = $data[$definition['field']];
            foreach ($definition['filters'] as $filter) {
                $value = $filter->filter($value);
            }
            if ($value === null && is_string($data[$definition['field']]) && !$parameter->allowsNull()) {
                // Text that filters emptied, such as "   " with Trim and EmptyToNull: a blank field.
                $errors[$path][] = 'This value should not be blank.';
                continue;
            }
            [$ok, $converted] = $this->convert($value, $parameter->getType(), $path, $errors);
            if ($ok) {
                $arguments[$name] = $converted;
            }
        }
        if (count($errors) > $before) {
            return null;
        }

        return (new ReflectionClass($class))->newInstanceArgs($arguments);
    }

    /**
     * @param array<string, list<string>> $errors
     * @return array{bool, mixed}
     */
    private function convert(mixed $value, ?ReflectionType $type, string $path, array &$errors): array
    {
        if ($type === null) {
            return [true, $value];
        }
        if ($value === null) {
            if ($type->allowsNull()) {
                return [true, null];
            }
            $errors[$path][] = 'This value should not be null.';

            return [false, null];
        }
        if ($type instanceof ReflectionUnionType) {
            foreach ($type->getTypes() as $member) {
                $attempt = [];
                [$ok, $converted] = $this->convert($value, $member, $path, $attempt);
                if ($ok) {
                    return [true, $converted];
                }
            }
            $names = array_map(static fn(ReflectionType $member): string => $member instanceof ReflectionNamedType ? $member->getName() : (string) $member, $type->getTypes());
            $errors[$path][] = 'This value should be of type ' . implode('|', array_diff($names, ['null'])) . '.';

            return [false, null];
        }
        if (!$type instanceof ReflectionNamedType) {
            throw new LogicException("Input fields cannot use intersection types: {$path}.");
        }

        $before = count($errors, COUNT_RECURSIVE);
        $converted = match ($type->getName()) {
            'mixed' => $value,
            'string' => is_string($value) ? $value : null,
            'int' => self::toInt($value),
            'float' => self::toFloat($value),
            'bool' => self::toBool($value),
            'array', 'iterable' => is_array($value) ? $value : null,
            default => $this->toObject($value, $type->getName(), $path, $errors),
        };
        if ($converted === null) {
            // A more specific error (a nested field, an enum choice, a date) may already explain it.
            if (count($errors, COUNT_RECURSIVE) === $before) {
                $errors[$path][] = 'This value should be of type ' . self::typeLabel($type->getName()) . '.';
            }

            return [false, null];
        }

        return [true, $converted];
    }

    private static function toInt(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }
        if (is_float($value) && is_finite($value) && floor($value) === $value && abs($value) <= PHP_INT_MAX) {
            return (int) $value;
        }
        $int = is_string($value) ? filter_var(trim($value), FILTER_VALIDATE_INT) : false;

        return $int === false ? null : $int;
    }

    private static function toFloat(mixed $value): ?float
    {
        if (is_float($value) || is_int($value)) {
            return (float) $value;
        }
        $float = is_string($value) && is_numeric(trim($value)) ? filter_var(trim($value), FILTER_VALIDATE_FLOAT) : false;

        return $float === false ? null : $float;
    }

    private static function toBool(mixed $value): ?bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if ($value === 0 || $value === 1) {
            return $value === 1;
        }

        return is_string($value) ? match (strtolower(trim($value))) {
            '1', 'true', 'on', 'yes' => true,
            '0', 'false', 'off', 'no' => false,
            default => null,
        } : null;
    }

    /** @param array<string, list<string>> $errors */
    private function toObject(mixed $value, string $class, string $path, array &$errors): ?object
    {
        if ($value instanceof $class) {
            return $value;
        }
        if (in_array($class, [DateTimeImmutable::class, DateTimeInterface::class, DateTime::class], true)) {
            if (!is_string($value) || trim($value) === '') {
                return null;
            }
            try {
                return $class === DateTime::class ? new DateTime($value) : new DateTimeImmutable($value);
            } catch (\Exception) {
                $errors[$path][] = 'This value is not a valid datetime.';

                return null;
            }
        }
        if (is_subclass_of($class, BackedEnum::class)) {
            $backing = (string) (new \ReflectionEnum($class))->getBackingType();
            $key = $backing === 'int' ? self::toInt($value) : (is_string($value) ? $value : null);
            $case = $key === null ? null : $class::tryFrom($key);
            if ($case === null) {
                $errors[$path][] = 'The value you selected is not a valid choice.';
            }

            return $case;
        }
        if (self::isInputClass($class)) {
            if (!is_array($value) || ($value !== [] && array_is_list($value))) {
                return null;
            }
            /** @var class-string $class */
            return $this->build($value, $class, $path . '.', $errors);
        }

        throw new LogicException("Input field {$path} has type {$class}, which cannot be read from request data. Use a scalar, array, date, backed enum, or another input class.");
    }

    private static function isInputClass(string $class): bool
    {
        return class_exists($class) && !is_subclass_of($class, DateTimeInterface::class) && (new ReflectionClass($class))->isInstantiable();
    }

    private static function typeLabel(string $type): string
    {
        return match (true) {
            $type === 'iterable' => 'array',
            in_array($type, [DateTimeImmutable::class, DateTimeInterface::class, DateTime::class], true) => 'datetime',
            class_exists($type) => 'object',
            default => $type,
        };
    }

    /**
     * The fields of an input class, by parameter name, cached.
     *
     * @param class-string $class
     * @return array<string, array{field: string, parameter: ReflectionParameter, filters: list<FilterInterface>}>
     */
    private function fields(string $class): array
    {
        if (isset($this->classes[$class])) {
            return $this->classes[$class];
        }
        $reflection = new ReflectionClass($class);
        $constructor = $reflection->getConstructor();
        if (!$reflection->isInstantiable() || $constructor === null) {
            throw new LogicException("Input class {$class} needs a public constructor whose parameters are its fields.");
        }
        $settings = ($reflection->getAttributes(Input::class)[0] ?? null)?->newInstance() ?? new Input();
        $defaults = [];
        foreach ($settings->filters as $filterClass) {
            $defaults[$filterClass] = $this->filterService($filterClass, $class);
        }
        $fields = [];
        $taken = [];
        foreach ($constructor->getParameters() as $parameter) {
            if ($parameter->isVariadic()) {
                throw new LogicException("Input class {$class} cannot have a variadic constructor parameter.");
            }
            $explicit = ($parameter->getAttributes(Field::class)[0] ?? null)?->newInstance();
            $field = $explicit->name ?? ($settings->naming === 'snake' ? self::snake($parameter->getName()) : $parameter->getName());
            if (isset($taken[$field])) {
                throw new LogicException("Input class {$class}: \"\${$parameter->getName()}\" and \"\${$taken[$field]}\" both read the field \"{$field}\".");
            }
            $taken[$field] = $parameter->getName();
            $fields[$parameter->getName()] = ['field' => $field, 'parameter' => $parameter, 'filters' => $this->filters($parameter, $defaults, $class)];
        }

        return $this->classes[$class] = $fields;
    }

    /**
     * Class defaults first, in order; a field's own filter of the same class replaces the
     * default in place, a switched-off one removes it, and other filters follow.
     *
     * @param array<string, FilterInterface> $defaults
     * @return list<FilterInterface>
     */
    private function filters(ReflectionParameter $parameter, array $defaults, string $class): array
    {
        $filters = $defaults;
        $extra = [];
        foreach ($parameter->getAttributes() as $attribute) {
            $name = $attribute->getName();
            if ($name === Filter::class) {
                $service = $attribute->newInstance()->service;
                $extra[] = $this->filterService($service, $class);
                continue;
            }
            if (!is_subclass_of($name, FilterInterface::class)) {
                continue;
            }
            /** @var ReflectionAttribute<FilterInterface> $attribute */
            $filter = $attribute->newInstance();
            if ($filter instanceof SwitchableFilter && !$filter->enabled) {
                unset($filters[$name]);
            } elseif (isset($filters[$name])) {
                $filters[$name] = $filter;
            } else {
                $extra[] = $filter;
            }
        }

        return [...array_values($filters), ...$extra];
    }

    private function filterService(string $service, string $class): FilterInterface
    {
        $filter = match (true) {
            $this->container !== null && $this->container->has($service) => $this->container->get($service),
            class_exists($service) => new $service(),
            default => null,
        };
        if (!$filter instanceof FilterInterface) {
            throw new LogicException("Filter \"{$service}\" on input class {$class} must be a service or class implementing " . FilterInterface::class . '.');
        }

        return $filter;
    }

    private static function snake(string $name): string
    {
        return strtolower((string) preg_replace(['/([A-Z]+)([A-Z][a-z])/', '/([a-z0-9])([A-Z])/'], '$1_$2', $name));
    }
}
