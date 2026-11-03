<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Orm\Cycle;

use Crystoline\Xpsr\Audit\Attribute\Audited;
use Crystoline\Xpsr\Audit\Cycle\AuditedBehavior;
use Cycle\Schema\GeneratorInterface;
use Cycle\Schema\Registry;
use ReflectionClass;

/**
 * Gives every entity marked #[Audited] the audit log's behavior, so that its inserts, updates,
 * and deletions are recorded. Added to the schema generators when crystoline/xpsr-audit is
 * installed.
 *
 * @internal
 */
final class AuditedEntities implements GeneratorInterface
{
    public function run(Registry $registry): Registry
    {
        foreach ($registry as $entity) {
            $class = $entity->getClass();
            if ($class === null || !class_exists($class)) {
                continue;
            }
            $attribute = (new ReflectionClass($class))->getAttributes(Audited::class)[0] ?? null;
            if ($attribute === null) {
                continue;
            }
            $audited = $attribute->newInstance();
            $entity->addSchemaModifier(new AuditedBehavior($audited->except, $audited->redact));
        }

        return $registry;
    }
}
