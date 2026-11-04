<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Tests\Unit;

use Closure;
use Crystoline\Xpsr\Configuration;
use Crystoline\Xpsr\ConfigurationCache;
use Crystoline\Xpsr\StaleConfigurationCacheException;
use FilesystemIterator;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

final class ConfigurationCacheTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/xpsr-config-cache-' . bin2hex(random_bytes(4));
        mkdir($this->root . '/config/testing', 0777, true);
        file_put_contents($this->root . '/config/app.php', "<?php return ['name' => env('CACHE_TEST_NAME', 'Default'), 'debug' => false, 'list' => [1, 2.5, 'x', null, true]];");
        file_put_contents($this->root . '/config/factory.php', "<?php return ['service' => static fn() => 'built', 'plain' => 'value'];");
        file_put_contents($this->root . '/config/testing/app.php', "<?php return ['debug' => true, 'extra' => 'testing only'];");
        file_put_contents($this->root . '/config/testing/factory.php', "<?php return ['plain' => 'testing value'];");
        $_ENV['CACHE_TEST_NAME'] = 'Cached';
    }

    protected function tearDown(): void
    {
        unset($_ENV['CACHE_TEST_NAME']);
        $items = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($items as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($this->root);
    }

    public function testTheCacheHoldsExportableSectionsAndReadsTheOthersLive(): void
    {
        $cache = new ConfigurationCache($this->root);
        self::assertNull($cache->load('testing'));
        self::assertFalse($cache->exists());

        self::assertSame(['cached' => ['app'], 'live' => ['factory']], $cache->build('testing'));
        self::assertTrue($cache->exists());
        self::assertFileExists($this->root . '/' . ConfigurationCache::PATH);

        $_ENV['CACHE_TEST_NAME'] = 'Changed after caching';
        $configuration = $cache->load('testing');
        self::assertInstanceOf(Configuration::class, $configuration);
        self::assertSame('Cached', $configuration->get('app.name'), 'Values from the environment are fixed in the cache.');
        self::assertTrue($configuration->get('app.debug'), 'The environment override is merged in.');
        self::assertSame('testing only', $configuration->get('app.extra'));
        self::assertSame([1, 2.5, 'x', null, true], $configuration->get('app.list'));
        self::assertInstanceOf(Closure::class, $configuration->get('factory.service'));
        self::assertSame('built', ($configuration->get('factory.service'))());
        self::assertSame('testing value', $configuration->get('factory.plain'), 'A live section gets its override too.');

        $live = new Configuration();
        $live->loadConfigurationFiles($this->root . '/config', 'testing');
        self::assertSame(array_keys($live->all()), array_keys($configuration->all()));
    }

    public function testAChangedFileSetsTheCacheAside(): void
    {
        $cache = new ConfigurationCache($this->root);
        $cache->build('testing');
        file_put_contents($this->root . '/config/app.php', "<?php return ['name' => 'Edited'];");

        $this->expectException(StaleConfigurationCacheException::class);
        $this->expectExceptionMessage('var/cache/config.php no longer matches config/app.php. The configuration files were used instead; run "php xpsr config:cache" again, or "php xpsr config:clear".');
        $cache->load('testing');
    }

    public function testAChangedOverrideSetsTheCacheAside(): void
    {
        $cache = new ConfigurationCache($this->root);
        $cache->build('testing');
        file_put_contents($this->root . '/config/testing/app.php', "<?php return ['debug' => false];");

        $this->expectException(StaleConfigurationCacheException::class);
        $this->expectExceptionMessage('no longer matches config/testing/app.php');
        $cache->load('testing');
    }

    public function testANewFileSetsTheCacheAside(): void
    {
        $cache = new ConfigurationCache($this->root);
        $cache->build('testing');
        file_put_contents($this->root . '/config/mail.php', "<?php return [];");

        $this->expectException(StaleConfigurationCacheException::class);
        $this->expectExceptionMessage('no longer matches config/mail.php');
        $cache->load('testing');
    }

    public function testAChangedEnvFileSetsTheCacheAside(): void
    {
        file_put_contents($this->root . '/.env', "CACHE_TEST_NAME=FromFile\n");
        $cache = new ConfigurationCache($this->root);
        $cache->build('testing');
        self::assertInstanceOf(Configuration::class, $cache->load('testing'));
        file_put_contents($this->root . '/.env', "CACHE_TEST_NAME=Edited\n");

        $this->expectException(StaleConfigurationCacheException::class);
        $this->expectExceptionMessage('no longer matches .env');
        $cache->load('testing');
    }

    public function testACacheForAnotherEnvironmentIsSetAside(): void
    {
        $cache = new ConfigurationCache($this->root);
        $cache->build('testing');

        $this->expectException(StaleConfigurationCacheException::class);
        $this->expectExceptionMessage('var/cache/config.php was built for APP_ENV=testing, but APP_ENV is production.');
        $cache->load('production');
    }

    public function testAFileThatIsNotACacheIsSetAside(): void
    {
        mkdir($this->root . '/var/cache', 0777, true);
        file_put_contents($this->root . '/' . ConfigurationCache::PATH, "<?php return 'nonsense';");

        $this->expectException(StaleConfigurationCacheException::class);
        $this->expectExceptionMessage('var/cache/config.php is not a configuration cache.');
        (new ConfigurationCache($this->root))->load('testing');
    }

    public function testClearRemovesTheCache(): void
    {
        $cache = new ConfigurationCache($this->root);
        self::assertFalse($cache->clear());
        $cache->build('testing');
        self::assertTrue($cache->clear());
        self::assertFalse($cache->exists());
        self::assertNull($cache->load('testing'));
    }

    public function testConfigurationFilesAreListedInNameOrder(): void
    {
        self::assertSame(['app', 'factory'], array_keys(Configuration::files($this->root . '/config')));
        self::assertSame([], Configuration::files($this->root . '/missing'));
        self::assertSame(['debug' => true, 'extra' => 'testing only'], Configuration::read($this->root . '/config/testing/app.php'));
    }
}
