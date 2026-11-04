<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Tests\Unit;

use Crystoline\Xpsr\Configuration;
use FilesystemIterator;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;

final class ConfigurationTest extends TestCase
{
    public function testKeysAreReadWithDotNotation(): void
    {
        $config = new Configuration(['app' => ['name' => 'Site', 'debug' => null, 'levels' => ['a' => 1]], 'example.com' => 'host']);

        self::assertSame('Site', $config->get('app.name'));
        self::assertSame(['a' => 1], $config->get('app.levels'));
        self::assertSame(1, $config->get('app.levels.a'));
        self::assertSame('host', $config->get('example.com'), 'A top-level key with a dot is found as a whole.');
        self::assertSame('fallback', $config->get('app.missing', 'fallback'));
        self::assertSame('fallback', $config->get('missing.deep.key', 'fallback'));
        self::assertNull($config->get('app.debug', 'fallback'), 'A key set to null returns null, not the default.');
        self::assertSame('fallback', $config->get('app.name.deeper', 'fallback'), 'A scalar on the way means the key is missing.');

        self::assertTrue($config->has('app.name'));
        self::assertTrue($config->has('app.debug'));
        self::assertTrue($config->has('example.com'));
        self::assertFalse($config->has('app.missing'));
        self::assertFalse($config->has('app.name.deeper'));
    }

    public function testSetCreatesTheArraysOnTheWay(): void
    {
        $config = new Configuration(['app' => ['name' => 'Site']]);

        $config->set('app.debug', true);
        $config->set('mail.from.address', 'hello@example.com');
        $config->set('app.name.first', 'replaced');

        self::assertSame(
            ['app' => ['name' => ['first' => 'replaced'], 'debug' => true], 'mail' => ['from' => ['address' => 'hello@example.com']]],
            $config->all(),
        );
        $config->set('app', ['fresh' => 1]);
        self::assertSame(['fresh' => 1], $config->get('app'));
    }

    public function testFilesAreLoadedByNameWithEnvironmentOverrides(): void
    {
        $root = sys_get_temp_dir() . '/xpsr-configuration-' . bin2hex(random_bytes(4));
        mkdir($root . '/config/testing', 0777, true);
        file_put_contents($root . '/config/b.php', "<?php return ['x' => 1, 'y' => ['z' => 2]];");
        file_put_contents($root . '/config/a.php', "<?php return ['name' => 'Site'];");
        file_put_contents($root . '/config/testing/b.php', "<?php return ['x' => 10, 'w' => 3];");
        file_put_contents($root . '/config/testing/c.php', "<?php return ['only' => 'here'];");
        file_put_contents($root . '/config/bad.txt', "not loaded");
        try {
            $config = new Configuration();
            $config->loadConfigurationFiles($root . '/config', 'testing');
            self::assertSame(['a', 'b', 'c'], array_keys($config->all()));
            self::assertSame(['x' => 10, 'y' => ['z' => 2], 'w' => 3], $config->get('b'), 'An override replaces only the keys it returns.');
            self::assertSame('here', $config->get('c.only'), 'An override without a base file still makes a section.');

            $config->loadConfigurationFiles($root . '/config');
            self::assertSame(['a', 'b'], array_keys($config->all()), 'Loading again starts from nothing.');
            self::assertSame(1, $config->get('b.x'));

            file_put_contents($root . '/config/a.php', "<?php return 'not an array';");
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('must return an array');
            $config->loadConfigurationFiles($root . '/config');
        } finally {
            $items = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
            foreach ($items as $item) {
                $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
            }
            rmdir($root);
        }
    }
}
