<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Audit\Tests\Fixtures;

/** A plain object with a getId() method. */
final class Widget
{
    public function __construct(private readonly ?string $id = null) {}

    public function getId(): ?string
    {
        return $this->id;
    }
}
