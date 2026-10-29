<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Storage;

use Crystoline\Xpsr\Application;
use Crystoline\Xpsr\Configuration;
use Crystoline\Xpsr\ServiceContainer\ServiceRegistry;
use Crystoline\Xpsr\Storage\Driver\LocalAdapterFactory;
use Crystoline\Xpsr\Storage\Driver\MemoryAdapterFactory;
use Crystoline\Xpsr\Storage\Driver\S3AdapterFactory;
use InvalidArgumentException;
use League\Flysystem\FilesystemOperator;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;

/**
 * Registers file storage, configured in config/storage.php:
 *
 *   Crystoline\Xpsr\Storage\StorageManager  disk(), fake(), store(), and download(). Shared.
 *   League\Flysystem\FilesystemOperator     the default disk
 *
 * and the drivers storage.driver.local, storage.driver.memory, and storage.driver.s3.
 */
final class StorageServiceRegistry extends ServiceRegistry
{
    public function register(): void
    {
        $this->container->factory(StorageOptions::class, static function (ContainerInterface $container): StorageOptions {
            $config = $container->get(Configuration::class)->get('storage', []);
            if (!is_array($config)) {
                throw new InvalidArgumentException('The storage configuration must be an array.');
            }

            return StorageOptions::fromArray($config);
        }, shared: true);
        $this->container->factory('storage.driver.local', static fn(ContainerInterface $container): LocalAdapterFactory => new LocalAdapterFactory(
            $container->get(Application::class)->getRootDir(),
        ), shared: true);
        $this->container->factory('storage.driver.memory', static fn(): MemoryAdapterFactory => new MemoryAdapterFactory(), shared: true);
        $this->container->factory('storage.driver.s3', static fn(): S3AdapterFactory => new S3AdapterFactory(), shared: true);
        $this->container->factory(StorageManager::class, static fn(ContainerInterface $container): StorageManager => new StorageManager(
            $container->get(StorageOptions::class),
            $container,
            $container->get(ResponseFactoryInterface::class),
            $container->get(StreamFactoryInterface::class),
        ), shared: true);
        // Not shared, so it follows StorageManager::fake().
        $this->container->factory(FilesystemOperator::class, static fn(ContainerInterface $container): FilesystemOperator => $container->get(StorageManager::class)->disk());
    }
}
