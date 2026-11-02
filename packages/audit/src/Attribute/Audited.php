<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Audit\Attribute;

use Attribute;

/**
 * Marks a Doctrine entity whose inserts, updates, and deletions are recorded automatically:
 *
 *     #[ORM\Entity]
 *     #[Audited(except: ['notes'], redact: ['iban'])]
 *     class Payment { ... }
 *
 * except names fields never recorded, redact fields recorded as "[redacted]"; both add to
 * audit.except and audit.redact.
 */
#[Attribute(Attribute::TARGET_CLASS)]
final class Audited
{
    /**
     * @param list<string> $except
     * @param list<string> $redact
     */
    public function __construct(public readonly array $except = [], public readonly array $redact = []) {}
}
