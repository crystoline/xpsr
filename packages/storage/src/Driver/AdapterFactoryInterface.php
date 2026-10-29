<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Storage\Driver;

use League\Flysystem\FilesystemAdapter;

/**
 * Builds the Flysystem adapter for a disk's driver. The storage provider looks a driver up as the
 * service "storage.driver.<driver>", so a package or application adds one (SFTP, Google Cloud
 * Storage, Azure, ...) by registering its factory under that name.
 */
interface AdapterFactoryInterface
{
    /**
     * @param string $disk the disk's name, for error messages
     * @param array<string, mixed> $options the disk's options, without driver, url, visibility, and directory_visibility
     */
    public function create(string $disk, array $options): FilesystemAdapter;
}
