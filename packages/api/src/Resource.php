<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Api;

use Closure;
use Crystoline\Xpsr\Api\Pagination\Page;
use Crystoline\Xpsr\Http\ResponsableInterface;
use Doctrine\ORM\PersistentCollection;
use LogicException;
use Psr\Http\Message\ServerRequestInterface;
use ReflectionClass;
use ReflectionObject;

/**
 * Shapes one object, array, or value into the JSON an API sends. Subclasses list the fields in
 * toArray(); a route handler returns the resource, and it becomes the response:
 *
 *   return new PaymentResource($payment);
 *   return new PaymentResource($payment)->withStatus(201);
 *   return PaymentResource::collection($payments);
 *
 * Fields can be conditional: when(), whenNotNull(), mergeWhen(), and whenLoaded() return a
 * MissingValue when their condition fails, and the field is left out. Nested resources,
 * collections, and pages inside the array are rendered too. A resource of null renders as null.
 *
 * The subject is $this->resource. $this->field reads its field as well, so Laravel resources
 * carry over with few changes; static analysis knows such fields from @property annotations on
 * the resource class.
 */
abstract class Resource implements ResponsableInterface
{
    use RespondsWithJson;

    public function __construct(public readonly mixed $resource) {}

    /**
     * A collection of this resource, one per item. A Page adds its links and meta.
     *
     * @param iterable<mixed>|Page|MissingValue $items
     */
    public static function collection(iterable|Page|MissingValue $items): ResourceCollection
    {
        return new ResourceCollection($items, static::class);
    }

    /**
     * The fields. Values may be MissingValue (left out), MergeValue (spread into this array),
     * other resources, collections, pages, and anything json_encode() takes.
     *
     * @return array<array-key, mixed>
     */
    abstract public function toArray(ServerRequestInterface $request): array;

    /** A field of the subject: a property (or attribute) of an object, or a key of an array. */
    public function __get(string $name): mixed
    {
        if (is_array($this->resource)) {
            return $this->resource[$name] ?? null;
        }
        if (is_object($this->resource)) {
            return $this->resource->{$name};
        }

        throw new LogicException(sprintf('%s wraps %s, which has no field "%s".', static::class, get_debug_type($this->resource), $name));
    }

    public function __isset(string $name): bool
    {
        if (is_array($this->resource)) {
            return isset($this->resource[$name]);
        }

        return is_object($this->resource) && isset($this->resource->{$name});
    }

    /** $value when the condition holds, else $default (left out). A Closure value is called only then. */
    protected function when(bool|Closure $condition, mixed $value, mixed $default = new MissingValue()): mixed
    {
        if (!($condition instanceof Closure ? (bool) $condition() : $condition)) {
            return $default;
        }

        return $value instanceof Closure ? $value() : $value;
    }

    protected function whenNotNull(mixed $value, mixed $default = new MissingValue()): mixed
    {
        return $value ?? $default;
    }

    /**
     * Several fields at once when the condition holds, spread into the array that holds them.
     *
     * @param array<array-key, mixed>|Closure(): array<array-key, mixed> $values
     */
    protected function mergeWhen(bool|Closure $condition, array|Closure $values): MergeValue|MissingValue
    {
        if (!($condition instanceof Closure ? (bool) $condition() : $condition)) {
            return new MissingValue();
        }

        return new MergeValue($values instanceof Closure ? $values() : $values);
    }

    /**
     * A relation of the subject when it has been loaded, else $default (left out), so a resource
     * never triggers a query of its own. Eloquent models report loaded relations themselves; on
     * other objects, such as Doctrine entities, a relation is loaded when its property is set and
     * is neither an uninitialized lazy object nor an unloaded collection. $value replaces the
     * relation in the output, or, as a Closure, is called with it.
     */
    protected function whenLoaded(string $relation, mixed $value = null, mixed $default = new MissingValue()): mixed
    {
        $loaded = $this->loadedRelation($relation);
        if ($loaded instanceof MissingValue) {
            return $default;
        }
        if ($loaded === null) {
            return null;
        }

        return match (true) {
            $value === null => $loaded,
            $value instanceof Closure => $value($loaded),
            default => $value,
        };
    }

    private function loadedRelation(string $relation): mixed
    {
        $subject = $this->resource;
        if (!is_object($subject)) {
            return new MissingValue();
        }
        if (method_exists($subject, 'relationLoaded') && method_exists($subject, 'getRelation')) {
            return $subject->relationLoaded($relation) ? $subject->getRelation($relation) : new MissingValue();
        }
        $object = new ReflectionObject($subject);
        if (!$object->hasProperty($relation)) {
            return new MissingValue();
        }
        $property = $object->getProperty($relation);
        if (!$property->isInitialized($subject)) {
            return new MissingValue();
        }
        $loaded = $property->getValue($subject);
        if (is_object($loaded)) {
            if ((new ReflectionClass($loaded))->isUninitializedLazyObject($loaded)) {
                return new MissingValue();
            }
            if ($loaded instanceof PersistentCollection && !$loaded->isInitialized()) {
                return new MissingValue();
            }
        }

        return $loaded;
    }

    /**
     * The fields, rendered: conditions applied and nested resources resolved.
     *
     * @return array<array-key, mixed>
     */
    public function resolve(ServerRequestInterface $request): array
    {
        $resolved = self::value($this->toArray($request), $request);

        return is_array($resolved) ? $resolved : [];
    }

    public function output(ServerRequestInterface $request, ?string $wrap = 'data'): array
    {
        $data = $this->resource === null ? null : $this->resolve($request);

        return $this->withMetaIn($wrap === null ? $data ?? [] : [$wrap => $data]);
    }

    /**
     * Renders a value: resources, collections, and pages become arrays; in an array, missing
     * values are dropped and merge values spread, and a list stays a list.
     *
     * @internal
     */
    public static function value(mixed $value, ServerRequestInterface $request): mixed
    {
        if ($value instanceof self) {
            return match (true) {
                $value->resource instanceof MissingValue => $value->resource,
                $value->resource === null => null,
                default => $value->resolve($request),
            };
        }
        if ($value instanceof ResourceCollection) {
            return $value->isMissing() ? new MissingValue() : $value->resolve($request);
        }
        if ($value instanceof Page) {
            return $value->toArray();
        }
        if (!is_array($value)) {
            return $value;
        }
        $list = array_is_list($value);
        $result = [];
        foreach ($value as $key => $item) {
            if ($item instanceof MergeValue) {
                $merged = self::value($item->values, $request);
                foreach (is_array($merged) ? $merged : [] as $mergedKey => $mergedItem) {
                    $list ? $result[] = $mergedItem : $result[$mergedKey] = $mergedItem;
                }
                continue;
            }
            $item = self::value($item, $request);
            if ($item instanceof MissingValue) {
                continue;
            }
            $list ? $result[] = $item : $result[$key] = $item;
        }

        return $result;
    }
}
