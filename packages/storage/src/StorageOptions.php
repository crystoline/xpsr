<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Storage;

use InvalidArgumentException;

/**
 * The "storage" configuration (config/storage.php), validated:
 *
 *   default  the disk disk() returns without a name (default: local)
 *   disks    named disks, each with a driver and its options, plus for every driver:
 *              url                   the public URL prefix for publicUrl(), or a list to spread files over
 *              visibility            "public" or "private" for new files; without it, the driver
 *                                    decides (the umask for local files, the bucket's policy on S3)
 *              directory_visibility  the same, for new directories
 *
 * Without disks, there is one: local, in var/storage.
 *
 * @internal Not covered by the backward-compatibility promise; see docs/VERSIONING.md.
 */
final class StorageOptions
{
    /** Options every disk takes, whatever its driver. */
    public const COMMON = ['driver', 'url', 'visibility', 'directory_visibility'];

    /** @param array<string, array<string, mixed>> $disks */
    private function __construct(
        public readonly string $default,
        public readonly array $disks,
    ) {}

    /** @param array<array-key, mixed> $config */
    public static function fromArray(array $config): self
    {
        foreach (array_keys($config) as $key) {
            if (!in_array($key, ['default', 'disks'], true)) {
                throw new InvalidArgumentException("Unknown option storage.{$key}. Known options: default, disks.");
            }
        }
        $disks = $config['disks'] ?? ['local' => ['driver' => 'local', 'root' => 'var/storage']];
        if (!is_array($disks) || $disks === []) {
            throw new InvalidArgumentException('storage.disks must name at least one disk, such as [\'local\' => [\'driver\' => \'local\', \'root\' => \'var/storage\']].');
        }
        $valid = [];
        foreach ($disks as $name => $disk) {
            if (!is_string($name) || $name === '' || !is_array($disk)) {
                throw new InvalidArgumentException('storage.disks must map disk names to their options, such as [\'local\' => [\'driver\' => \'local\', \'root\' => \'var/storage\']].');
            }
            if (!is_string($disk['driver'] ?? null) || $disk['driver'] === '') {
                throw new InvalidArgumentException("storage.disks.{$name} needs a driver, such as 'local', 'memory', or 's3'.");
            }
            $url = $disk['url'] ?? null;
            if ($url !== null && !is_string($url) && !(is_array($url) && $url !== [] && array_is_list($url) && array_filter($url, is_string(...)) === $url)) {
                throw new InvalidArgumentException("storage.disks.{$name}.url must be a URL prefix such as https://cdn.example.com/files, or a list of them.");
            }
            foreach (['visibility', 'directory_visibility'] as $option) {
                if (!in_array($disk[$option] ?? 'private', ['public', 'private'], true)) {
                    throw new InvalidArgumentException("storage.disks.{$name}.{$option} must be \"public\" or \"private\".");
                }
            }
            $options = [];
            foreach ($disk as $key => $value) {
                $options[(string) $key] = $value;
            }
            $valid[$name] = $options;
        }
        $default = $config['default'] ?? 'local';
        if (!is_string($default) || !isset($valid[$default])) {
            throw new InvalidArgumentException('storage.default must name a disk in storage.disks: ' . implode(', ', array_keys($valid)) . '.');
        }

        return new self($default, $valid);
    }

    /**
     * Rejects driver options outside $known, naming the disk.
     *
     * @param array<string, mixed> $options the disk's options without the COMMON ones
     * @param list<string> $known
     */
    public static function assertKnown(string $disk, string $driver, array $options, array $known): void
    {
        foreach (array_keys($options) as $key) {
            if (!in_array($key, $known, true)) {
                throw new InvalidArgumentException(sprintf(
                    'Unknown option storage.disks.%s.%s. The %s driver takes %s; every disk also takes %s.',
                    $disk,
                    $key,
                    $driver,
                    $known === [] ? 'no options' : implode(', ', $known),
                    implode(', ', array_slice(self::COMMON, 1)),
                ));
            }
        }
    }
}
