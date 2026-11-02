<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Audit;

use Closure;
use Crystoline\Xpsr\Auth\UserInterface;
use Doctrine\ORM\EntityManagerInterface;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use ReflectionObject;

/**
 * The AuditKey of an object: an AuditableInterface says its own; an Eloquent model is its morph
 * class and key; a Doctrine entity is its class and identifier; a user is its class and
 * authIdentifier(); any other object its class and public $id property or getId() method.
 */
final class Keys
{
    /** @param (Closure(): ?EntityManagerInterface)|null $doctrine the entity manager, when the ORM package is installed */
    public function __construct(private readonly ?Closure $doctrine = null) {}

    public function of(object $subject): AuditKey
    {
        if ($subject instanceof AuditKey) {
            return $subject;
        }
        if ($subject instanceof AuditableInterface) {
            return $subject->auditKey();
        }
        if ($subject instanceof Model) {
            $key = $subject->getKey();
            if ($key === null) {
                throw new InvalidArgumentException(sprintf('The %s model has no key yet, so it cannot be audited.', $subject::class));
            }

            return new AuditKey($subject->getMorphClass(), self::id($key));
        }
        if ($subject instanceof UserInterface) {
            return new AuditKey($subject::class, self::id($subject->authIdentifier()));
        }
        $entity = $this->doctrineKey($subject);
        if ($entity !== null) {
            return $entity;
        }
        $object = new ReflectionObject($subject);
        if ($object->hasProperty('id') && $object->getProperty('id')->isPublic() && $object->getProperty('id')->isInitialized($subject)) {
            $id = $object->getProperty('id')->getValue($subject);
            if ($id !== null) {
                return new AuditKey($subject::class, self::id($id));
            }
        }
        if (method_exists($subject, 'getId')) {
            $id = $subject->getId();
            if ($id !== null) {
                return new AuditKey($subject::class, self::id($id));
            }
        }

        throw new InvalidArgumentException(sprintf('%s cannot be audited: give it an id, implement AuditableInterface, or pass an AuditKey.', $subject::class));
    }

    private function doctrineKey(object $subject): ?AuditKey
    {
        $em = $this->doctrine === null ? null : ($this->doctrine)();
        if ($em === null || $em->getMetadataFactory()->isTransient($subject::class)) {
            return null;
        }
        $metadata = $em->getClassMetadata($subject::class);
        $ids = array_filter($metadata->getIdentifierValues($subject), static fn(mixed $id): bool => $id !== null);
        if ($ids === []) {
            throw new InvalidArgumentException(sprintf('The %s entity has no identifier yet, so it cannot be audited; flush first.', $subject::class));
        }

        return new AuditKey($metadata->getName(), implode(':', array_map(self::id(...), $ids)));
    }

    /** @internal */
    public static function id(mixed $id): string
    {
        return match (true) {
            is_string($id), is_int($id) => (string) $id,
            $id instanceof \Stringable => (string) $id,
            $id instanceof \BackedEnum => (string) $id->value,
            is_object($id) && method_exists($id, 'getId') => self::id($id->getId()),
            default => throw new InvalidArgumentException('An audit ID must be a string, a number, or convertible to one; got ' . get_debug_type($id) . '.'),
        };
    }
}
