<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Audit\Doctrine;

use Crystoline\Xpsr\Audit\Attribute\Audited;
use Crystoline\Xpsr\Audit\AuditKey;
use Crystoline\Xpsr\Audit\AuditLog;
use Crystoline\Xpsr\Audit\AuditOptions;
use Crystoline\Xpsr\Audit\Changes;
use Crystoline\Xpsr\Audit\Keys;
use Doctrine\Common\EventSubscriber;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\PostPersistEventArgs;
use Doctrine\ORM\Event\PostRemoveEventArgs;
use Doctrine\ORM\Event\PostUpdateEventArgs;
use Doctrine\ORM\Event\PreRemoveEventArgs;
use Doctrine\ORM\Events;
use ReflectionClass;

/**
 * Records entities marked #[Audited] as they are inserted, updated, and deleted, inside the
 * flush's transaction: created with every field, updated with the unit of work's change set,
 * deleted with the fields that were there. Added to the entity manager through
 * DoctrineListeners by AuditServiceRegistry.
 */
final class DoctrineAuditSubscriber implements EventSubscriber
{
    /** @var array<int, array{AuditKey, array<string, mixed>}> what a removed entity was, by object ID, taken before Doctrine clears its identifier */
    private array $removing = [];

    public function __construct(private readonly AuditLog $log, private readonly AuditOptions $options) {}

    public function getSubscribedEvents(): array
    {
        return [Events::postPersist, Events::postUpdate, Events::preRemove, Events::postRemove];
    }

    public function postPersist(PostPersistEventArgs $args): void
    {
        $entity = $args->getObject();
        $audited = self::audited($entity);
        if ($audited === null) {
            return;
        }
        $em = $args->getObjectManager();
        $this->log->record('created', self::key($em, $entity), Changes::between([], self::fields($em, $entity), $this->except($audited), $this->redact($audited)));
    }

    public function postUpdate(PostUpdateEventArgs $args): void
    {
        $entity = $args->getObject();
        $audited = self::audited($entity);
        if ($audited === null) {
            return;
        }
        $em = $args->getObjectManager();
        $before = [];
        $after = [];
        foreach ($em->getUnitOfWork()->getEntityChangeSet($entity) as $field => $change) {
            // Collections appear in change sets as well; only field changes are [before, after] pairs.
            if (is_array($change)) {
                [$before[$field], $after[$field]] = $change;
            }
        }
        $changes = Changes::between($before, $after, $this->except($audited), $this->redact($audited));
        if ($changes !== []) {
            $this->log->record('updated', self::key($em, $entity), $changes);
        }
    }

    public function preRemove(PreRemoveEventArgs $args): void
    {
        $entity = $args->getObject();
        if (self::audited($entity) === null) {
            return;
        }
        $em = $args->getObjectManager();
        $this->removing[spl_object_id($entity)] = [self::key($em, $entity), self::fields($em, $entity)];
    }

    public function postRemove(PostRemoveEventArgs $args): void
    {
        $entity = $args->getObject();
        $audited = self::audited($entity);
        $removed = $this->removing[spl_object_id($entity)] ?? null;
        unset($this->removing[spl_object_id($entity)]);
        if ($audited === null || $removed === null) {
            return;
        }
        [$key, $fields] = $removed;
        $this->log->record('deleted', $key, Changes::between($fields, [], $this->except($audited), $this->redact($audited)));
    }

    private static function audited(object $entity): ?Audited
    {
        $attribute = (new ReflectionClass($entity))->getAttributes(Audited::class)[0] ?? null;

        return $attribute?->newInstance();
    }

    private static function key(EntityManagerInterface $em, object $entity): AuditKey
    {
        $metadata = $em->getClassMetadata($entity::class);
        $ids = array_filter($metadata->getIdentifierValues($entity), static fn(mixed $id): bool => $id !== null);

        return new AuditKey($metadata->getName(), implode(':', array_map(Keys::id(...), $ids)));
    }

    /** @return array<string, mixed> */
    private static function fields(EntityManagerInterface $em, object $entity): array
    {
        $metadata = $em->getClassMetadata($entity::class);
        $values = [];
        foreach ($metadata->getFieldNames() as $field) {
            $values[$field] = $metadata->getFieldValue($entity, $field);
        }

        return $values;
    }

    /** @return list<string> */
    private function except(Audited $audited): array
    {
        return [...$this->options->except, ...$audited->except];
    }

    /** @return list<string> */
    private function redact(Audited $audited): array
    {
        return [...$this->options->redact, ...$audited->redact];
    }
}
