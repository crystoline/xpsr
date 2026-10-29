<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Storage\Driver;

use Crystoline\Xpsr\Storage\StorageOptions;
use League\Flysystem\FilesystemAdapter;
use League\Flysystem\InMemory\InMemoryFilesystemAdapter;

/**
 * The memory driver: files kept for the current process only, for tests.
 *
 * @internal Registered as storage.driver.memory.
 */
final class MemoryAdapterFactory implements AdapterFactoryInterface
{
    public function create(string $disk, array $options): FilesystemAdapter
    {
        StorageOptions::assertKnown($disk, 'memory', $options, []);

        return new InMemoryFilesystemAdapter();
    }
}
