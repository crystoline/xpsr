<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Storage\Driver;

use Crystoline\Xpsr\Storage\StorageOptions;
use InvalidArgumentException;
use League\Flysystem\FilesystemAdapter;
use League\Flysystem\Local\LocalFilesystemAdapter;
use League\Flysystem\UnixVisibility\PortableVisibilityConverter;

/**
 * The local driver: files in a directory on this server.
 *
 *   root         the directory, relative to the project unless absolute (required)
 *   permissions  modes for public and private files and directories, such as
 *                ['file' => ['public' => 0644, 'private' => 0600], 'dir' => ['public' => 0755, 'private' => 0700]]
 *   links        "disallow" (the default: listing a directory with a symbolic link fails) or "skip"
 *   lock         false to write without an exclusive lock
 *
 * @internal Registered as storage.driver.local.
 */
final class LocalAdapterFactory implements AdapterFactoryInterface
{
    public function __construct(private readonly string $rootDir) {}

    public function create(string $disk, array $options): FilesystemAdapter
    {
        StorageOptions::assertKnown($disk, 'local', $options, ['root', 'permissions', 'links', 'lock']);
        $root = $options['root'] ?? null;
        if (!is_string($root) || $root === '') {
            throw new InvalidArgumentException("storage.disks.{$disk}.root must be a directory, such as var/storage.");
        }
        $permissions = $options['permissions'] ?? [];
        if (!is_array($permissions)) {
            throw new InvalidArgumentException("storage.disks.{$disk}.permissions must be ['file' => ['public' => 0644, 'private' => 0600], 'dir' => [...]].");
        }
        $links = $options['links'] ?? 'disallow';
        if (!in_array($links, ['disallow', 'skip'], true)) {
            throw new InvalidArgumentException("storage.disks.{$disk}.links must be \"disallow\" or \"skip\".");
        }
        $lock = $options['lock'] ?? true;
        if (!is_bool($lock)) {
            throw new InvalidArgumentException("storage.disks.{$disk}.lock must be true or false.");
        }
        $absolute = str_starts_with($root, '/') || preg_match('#\A[A-Za-z]:[\\\\/]#', $root) === 1;

        return new LocalFilesystemAdapter(
            $absolute ? $root : $this->rootDir . '/' . $root,
            PortableVisibilityConverter::fromArray($permissions),
            $lock ? LOCK_EX : 0,
            $links === 'skip' ? LocalFilesystemAdapter::SKIP_LINKS : LocalFilesystemAdapter::DISALLOW_LINKS,
            lazyRootCreation: true,
        );
    }
}
