<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Storage\Tests;

use Crystoline\Xpsr\Application;
use Crystoline\Xpsr\Http\Exception\NotFoundHttpException;
use Crystoline\Xpsr\ServiceContainer\ContainerException;
use Crystoline\Xpsr\Storage\StorageManager;
use Crystoline\Xpsr\Storage\StorageOptions;
use Crystoline\Xpsr\Storage\StorageServiceRegistry;
use Crystoline\Xpsr\Storage\Tests\Fixtures\PrefixedMemoryFactory;
use DateTimeImmutable;
use InvalidArgumentException;
use League\Flysystem\FilesystemOperator;
use League\Flysystem\Local\LocalFilesystemAdapter;
use LogicException;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Message\UploadedFileInterface;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Disks from config/storage.php on the local, memory, s3, and an application's own driver;
 * fake(), store(), and download().
 */
final class StorageTest extends TestCase
{
    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==';

    private string $root;
    private Psr17Factory $psr17;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/xpsr-storage-' . bin2hex(random_bytes(6));
        mkdir($this->root . '/config', 0777, true);
        $this->psr17 = new Psr17Factory();
    }

    protected function tearDown(): void
    {
        $items = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->root, RecursiveDirectoryIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($items as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($this->root);
    }

    public function testWithoutConfigurationTheDefaultDiskIsLocalInVarStorage(): void
    {
        $storage = $this->storage(null);
        self::assertSame(['local'], $storage->names());
        self::assertFileDoesNotExist($this->root . '/var/storage', 'The directory is created when a file is written.');

        $storage->disk()->write('reports/october.csv', "a,b\n");
        self::assertSame("a,b\n", file_get_contents($this->root . '/var/storage/reports/october.csv'));
        self::assertSame("a,b\n", $storage->disk('local')->read('reports/october.csv'));
        self::assertSame($storage->disk(), $storage->disk('local'), 'Each disk is built once.');
    }

    public function testNamedDisksWithTheirOwnSettings(): void
    {
        $storage = $this->storage([
            'default' => 'files',
            'disks' => [
                'files' => ['driver' => 'local', 'root' => 'var/files'],
                'public' => ['driver' => 'local', 'root' => $this->root . '/public/uploads', 'url' => '/uploads', 'visibility' => 'public'],
                'cdn' => ['driver' => 'memory', 'url' => ['https://a.example.com', 'https://b.example.com']],
            ],
        ]);

        $storage->disk()->write('a.txt', 'A');
        self::assertFileExists($this->root . '/var/files/a.txt');

        $public = $storage->disk('public');
        $public->write('avatars/1.png', 'png');
        self::assertSame('/uploads/avatars/1.png', $public->publicUrl('avatars/1.png'));
        self::assertSame('public', $public->visibility('avatars/1.png'), 'The disk\'s visibility for new files.');
        self::assertSame(0644, fileperms($this->root . '/public/uploads/avatars/1.png') & 0777);
        $storage->disk()->write('b.txt', 'B', ['visibility' => 'private']);
        self::assertSame(0600, fileperms($this->root . '/var/files/b.txt') & 0777, 'Per write.');

        self::assertMatchesRegularExpression('#\Ahttps://[ab]\.example\.com/x\.css\z#', $storage->disk('cdn')->publicUrl('x.css'), 'A list of URLs spreads files over them.');
    }

    public function testTheDefaultDiskIsAServiceThatFollowsFake(): void
    {
        $application = $this->application(['disks' => ['local' => ['driver' => 'local', 'root' => 'var/storage', 'url' => '/files']]]);
        $storage = $application->get(StorageManager::class);
        $application->get(FilesystemOperator::class)->write('real.txt', 'on disk');

        $fake = $storage->fake();
        self::assertSame($fake, $application->get(FilesystemOperator::class));
        self::assertFalse($fake->fileExists('real.txt'), 'The fake starts empty.');
        $fake->write('test.txt', 'in memory');
        self::assertSame('/files/test.txt', $fake->publicUrl('test.txt'), 'It keeps the disk\'s URL.');
        self::assertFileDoesNotExist($this->root . '/var/storage/test.txt');
        self::assertFileExists($this->root . '/var/storage/real.txt');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('There is no disk named "s3" in storage.disks. Disks: local.');
        $storage->fake('s3');
    }

    public function testStoreNamesUploadsByTheirContentNeverByTheClientsName(): void
    {
        $storage = $this->storage(['disks' => ['memory' => ['driver' => 'memory']], 'default' => 'memory']);
        $png = (string) base64_decode(self::PNG, true);

        $path = $storage->store($this->upload($png, 'evil.php'), '/avatars/');
        self::assertMatchesRegularExpression('#\Aavatars/[0-9a-f]{32}\.png\z#', $path);
        self::assertSame($png, $storage->disk()->read($path));

        $path = $storage->store($this->upload("<?php echo 'hi';\n", 'notes.jpg'));
        self::assertMatchesRegularExpression('#\A[0-9a-f]{32}\z#', $path, 'A PHP file gets no extension, whatever its name.');

        $path = $storage->store($this->upload("Plain notes.\n", 'notes.html'), 'notes');
        self::assertMatchesRegularExpression('#\Anotes/[0-9a-f]{32}\.txt\z#', $path);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('The uploaded file is larger than the server allows (upload_max_filesize).');
        $storage->store($this->psr17->createUploadedFile($this->psr17->createStream(''), 0, UPLOAD_ERR_INI_SIZE));
    }

    public function testDownloadSendsAStoredFile(): void
    {
        $storage = $this->storage(null);
        $storage->disk()->write('invoices/42.pdf', '%PDF-1.4 invoice');

        $response = $storage->download('invoices/42.pdf');
        self::assertSame(200, $response->getStatusCode());
        self::assertSame('application/pdf', $response->getHeaderLine('Content-Type'));
        self::assertSame('16', $response->getHeaderLine('Content-Length'));
        self::assertSame('attachment; filename="42.pdf"', $response->getHeaderLine('Content-Disposition'));
        self::assertSame('nosniff', $response->getHeaderLine('X-Content-Type-Options'));
        self::assertSame('%PDF-1.4 invoice', (string) $response->getBody());

        $response = $storage->download('invoices/42.pdf', filename: 'Facture n°42 "final".pdf', inline: true);
        self::assertSame(
            'inline; filename="Facture n__42 _final_.pdf"; filename*=UTF-8\'\'Facture%20n%C2%B042%20%22final%22.pdf',
            $response->getHeaderLine('Content-Disposition'),
        );
        self::assertSame('attachment; filename="a_b.txt"', $storage->download('invoices/42.pdf', filename: 'a/b.txt')->getHeaderLine('Content-Disposition'));

        $this->expectException(NotFoundHttpException::class);
        $storage->download('invoices/43.pdf');
    }

    public function testTheS3DriverSignsTemporaryUrlsWithoutANetworkCall(): void
    {
        $disk = $this->storage(['default' => 's3', 'disks' => ['s3' => [
            'driver' => 's3',
            'bucket' => 'media',
            'region' => 'eu-west-1',
            'key' => 'AKIDEXAMPLE',
            'secret' => 'secret',
            'endpoint' => 'http://localhost:9000',
            'path_style' => true,
            'prefix' => 'app',
        ]]])->disk();

        $url = $disk->temporaryUrl('reports/a.pdf', new DateTimeImmutable('+10 minutes'));
        self::assertStringStartsWith('http://localhost:9000/media/app/reports/a.pdf?', $url);
        self::assertStringContainsString('X-Amz-Signature=', $url);
        self::assertStringContainsString('X-Amz-Credential=AKIDEXAMPLE%2F', $url);
    }

    public function testApplicationsAddDriversAsServices(): void
    {
        $factory = new PrefixedMemoryFactory();
        $application = $this->application(['default' => 'mine', 'disks' => ['mine' => ['driver' => 'custom', 'url' => '/m', 'token' => 'abc']]], $factory);
        $application->get(StorageManager::class)->disk()->write('x', 'y');
        self::assertSame(['mine' => ['token' => 'abc']], $factory->created, 'The driver gets its own options only.');
    }

    /** @param array<string, mixed> $config */
    #[DataProvider('diskErrors')]
    public function testDiskErrorsNameTheOption(array $config, string $disk, string $type, string $message): void
    {
        $storage = $this->storage($config);
        $this->expectException($type);
        $this->expectExceptionMessage($message);
        $storage->disk($disk);
    }

    /** @return iterable<string, array{array<string, mixed>, string, class-string<\Throwable>, string}> */
    public static function diskErrors(): iterable
    {
        yield 'unknown disk' => [[], 'nope', InvalidArgumentException::class, 'There is no disk named "nope" in storage.disks. Disks: local.'];
        yield 'unknown driver' => [['disks' => ['local' => ['driver' => 'dropbox']]], 'local', LogicException::class, 'storage.disks.local.driver is "dropbox", which is not a driver. Built in: local, memory, and s3'];
        yield 'local option' => [['disks' => ['local' => ['driver' => 'local', 'root' => 'x', 'bucket' => 'b']]], 'local', InvalidArgumentException::class, 'Unknown option storage.disks.local.bucket. The local driver takes root, permissions, links, lock; every disk also takes url, visibility, directory_visibility.'];
        yield 'local root' => [['disks' => ['local' => ['driver' => 'local']]], 'local', InvalidArgumentException::class, 'storage.disks.local.root must be a directory'];
        yield 'local links' => [['disks' => ['local' => ['driver' => 'local', 'root' => 'x', 'links' => 'follow']]], 'local', InvalidArgumentException::class, 'storage.disks.local.links must be "disallow" or "skip".'];
        yield 'memory option' => [['disks' => ['local' => ['driver' => 'memory', 'root' => 'x']]], 'local', InvalidArgumentException::class, 'The memory driver takes no options'];
        yield 's3 bucket' => [['disks' => ['local' => ['driver' => 's3']]], 'local', InvalidArgumentException::class, 'storage.disks.local.bucket must name the bucket.'];
        yield 's3 option type' => [['disks' => ['local' => ['driver' => 's3', 'bucket' => 'b', 'region' => 5]]], 'local', InvalidArgumentException::class, 'storage.disks.local.region must be a string.'];
    }

    /** @param array<string, mixed> $config */
    #[DataProvider('invalidOptions')]
    public function testInvalidOptionsAreRejected(array $config, string $message): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message);
        StorageOptions::fromArray($config);
    }

    /** @return iterable<string, array{array<string, mixed>, string}> */
    public static function invalidOptions(): iterable
    {
        yield 'unknown key' => [['disk' => 'local'], 'Unknown option storage.disk. Known options: default, disks.'];
        yield 'no disks' => [['disks' => []], 'storage.disks must name at least one disk'];
        yield 'list of disks' => [['disks' => [['driver' => 'local']]], 'storage.disks must map disk names to their options'];
        yield 'no driver' => [['disks' => ['local' => ['root' => 'x']]], 'storage.disks.local needs a driver'];
        yield 'bad url' => [['disks' => ['local' => ['driver' => 'local', 'url' => 5]]], 'storage.disks.local.url must be a URL prefix'];
        yield 'bad visibility' => [['disks' => ['local' => ['driver' => 'local', 'visibility' => 'hidden']]], 'storage.disks.local.visibility must be "public" or "private".'];
        yield 'default not a disk' => [['default' => 's3'], 'storage.default must name a disk in storage.disks: local.'];
    }

    public function testLocalDriverOptionsAndAConfigurationThatIsNotAnArray(): void
    {
        $adapter = (new \Crystoline\Xpsr\Storage\Driver\LocalAdapterFactory($this->root))->create('local', ['root' => 'var/x', 'lock' => false, 'links' => 'skip']);
        self::assertInstanceOf(LocalFilesystemAdapter::class, $adapter);

        $this->expectException(ContainerException::class);
        $this->application(['disks' => 'local'])->get(StorageManager::class);
    }

    /** @param array<string, mixed>|null $config */
    private function storage(?array $config): StorageManager
    {
        return $this->application($config)->get(StorageManager::class);
    }

    /** @param array<string, mixed>|null $config */
    private function application(?array $config, ?PrefixedMemoryFactory $custom = null): Application
    {
        $application = new Application($this->root);
        $application->getConfig()->set('app.registry', [StorageServiceRegistry::class]);
        if ($config !== null) {
            $application->getConfig()->set('storage', $config);
        }
        $container = $application->getContainer();
        $container->instance(ResponseFactoryInterface::class, $this->psr17);
        $container->instance(StreamFactoryInterface::class, $this->psr17);
        if ($custom !== null) {
            $container->instance('storage.driver.custom', $custom);
        }
        $application->boot();

        return $application;
    }

    private function upload(string $content, string $name): UploadedFileInterface
    {
        return $this->psr17->createUploadedFile($this->psr17->createStream($content), strlen($content), UPLOAD_ERR_OK, $name);
    }
}
