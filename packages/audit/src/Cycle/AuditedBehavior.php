<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Audit\Cycle;

use Cycle\ORM\Entity\Behavior\Schema\BaseModifier;

/**
 * The entity behavior that records a Cycle entity marked #[Audited]: it attaches AuditListener
 * to the entity's role. crystoline/xpsr-orm-cycle adds it for every entity with the attribute.
 *
 * @internal
 */
final class AuditedBehavior extends BaseModifier
{
    /**
     * @param list<string> $except fields never recorded
     * @param list<string> $redact fields recorded as "[redacted]"
     */
    public function __construct(private readonly array $except = [], private readonly array $redact = []) {}

    protected function getListenerClass(): string
    {
        return AuditListener::class;
    }

    protected function getListenerArgs(): array
    {
        return ['except' => $this->except, 'redact' => $this->redact];
    }
}
