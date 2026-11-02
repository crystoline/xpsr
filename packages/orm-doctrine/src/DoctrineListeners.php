<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Orm\Doctrine;

/**
 * The event subscribers the entity manager gets when it is built: service IDs of
 * Doctrine\Common\EventSubscriber implementations, from orm.listeners and from other providers
 * (such as the audit log's), which add() theirs in boot().
 */
final class DoctrineListeners
{
    /** @var list<string> */
    private array $ids = [];

    public function add(string $serviceId): void
    {
        if (!in_array($serviceId, $this->ids, true)) {
            $this->ids[] = $serviceId;
        }
    }

    /** @return list<string> */
    public function all(): array
    {
        return $this->ids;
    }
}
