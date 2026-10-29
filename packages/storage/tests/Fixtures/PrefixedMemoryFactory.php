<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Storage\Tests\Fixtures;

use Crystoline\Xpsr\Storage\Driver\AdapterFactoryInterface;
use League\Flysystem\FilesystemAdapter;
use League\Flysystem\InMemory\InMemoryFilesystemAdapter;

/** A driver an application registers itself, as storage.driver.custom. */
final class PrefixedMemoryFactory implements AdapterFactoryInterface
{
    /** @var array<string, array<string, mixed>> */
    public array $created = [];

    public function create(string $disk, array $options): FilesystemAdapter
    {
        $this->created[$disk] = $options;

        return new InMemoryFilesystemAdapter();
    }
}
