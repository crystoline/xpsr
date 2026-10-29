# crystoline/xpsr-storage

File storage for XPSR applications, on [Flysystem](https://flysystem.thephpleague.com/docs/). Disks are named in `config/storage.php`, like database connections. Each one is a Flysystem `FilesystemOperator`, whether its files are on this server, in memory, or in S3.

```sh
composer require crystoline/xpsr-storage
```

Add the provider to `app.registry` in `config/app.php`:

```php
Crystoline\Xpsr\Storage\StorageServiceRegistry::class,
```

and, for more than the default disk (`local`, in `var/storage`), create `config/storage.php`:

```php
return [
    'default' => env('STORAGE_DISK', 'local'),
    'disks' => [
        'local' => ['driver' => 'local', 'root' => 'var/storage'],
        'public' => ['driver' => 'local', 'root' => 'public/uploads', 'url' => '/uploads', 'visibility' => 'public'],
        's3' => [
            'driver' => 's3',
            'bucket' => env('S3_BUCKET'),
            'region' => env('S3_REGION', 'us-east-1'),
            'key' => env('S3_KEY'),
            'secret' => env('S3_SECRET'),
            'url' => env('S3_URL'),          // for publicUrl(), such as a CDN in front of the bucket
        ],
    ],
];
```

## Disks

```php
use Crystoline\Xpsr\Storage\StorageManager;

$storage->disk()->write('reports/october.csv', $csv);          // the default disk
$storage->disk('s3')->writeStream('backups/db.sql.gz', $stream);
$storage->disk('public')->publicUrl('avatars/7.png');           // /uploads/avatars/7.png
$storage->disk('s3')->temporaryUrl('invoices/42.pdf', new DateTimeImmutable('+10 minutes'));
```

`disk()` returns the [Flysystem API](https://flysystem.thephpleague.com/docs/usage/filesystem-api/): `write()`, `read()`, `writeStream()`, `readStream()`, `fileExists()`, `delete()`, `listContents()`, `move()`, `copy()`, `mimeType()`, `fileSize()`, `lastModified()`, `visibility()`, and the rest. A service or controller can also take `League\Flysystem\FilesystemOperator`, which is the default disk.

Every disk takes `url` (the prefix for `publicUrl()`, or a list of prefixes to spread files over), and `visibility` and `directory_visibility` (`public` or `private`) for new files and directories. Without them, the driver decides: local files get the server's umask, and S3 objects the bucket's policy.

## Drivers

| Driver | Options | Needs |
|---|---|---|
| `local` | `root` (relative to the project, or absolute), `permissions`, `links` (`disallow` or `skip`), `lock` | — |
| `memory` | — (files last for the current process; for tests) | — |
| `s3` | `bucket`, `region`, `key`, `secret`, `session_token`, `endpoint`, `path_style`, `prefix` | `league/flysystem-async-aws-s3` |

Without `key` and `secret`, the s3 driver uses the AWS environment variables, the shared credentials file, or the instance role. `endpoint` points it at an S3-compatible service: Cloudflare R2, MinIO (with `'path_style' => true`), DigitalOcean Spaces, and others.

Another Flysystem adapter (SFTP, Google Cloud Storage, Azure, ...) is a service named `storage.driver.<name>` that implements `Crystoline\Xpsr\Storage\Driver\AdapterFactoryInterface`. It gets the disk's options other than `driver`, `url`, and the visibilities:

```php
// config/factory.php
'storage.driver.sftp' => static fn() => new App\Storage\SftpAdapterFactory(),
```

## Uploads

```php
$upload = $request->getUploadedFiles()['avatar'];
$path = $storage->store($upload, 'avatars');             // avatars/3f9c...e1.png, on the default disk
$path = $storage->store($upload, 'avatars', 'public');
```

`store()` writes the file under a new random name. Its extension comes from the file's content for common image, document, audio, and video types, and never from the name the client sent, so `shell.php` cannot be stored as something the web server runs. Other files are stored without an extension. Check an upload's type and size, with validation, before storing it.

## Downloads

```php
return $storage->download('invoices/42.pdf', filename: 'Invoice 42.pdf');
return $storage->download($path, 'public', inline: true);      // shown in the browser
```

`download()` returns a response that streams the file, with its type, length, `Content-Disposition`, and `X-Content-Type-Options: nosniff`. A missing file is a 404. Use `inline` only for types that are safe to show, such as images and PDFs, never for HTML that users uploaded.

## Testing

```php
$disk = $this->application()->get(StorageManager::class)->fake('s3');

$this->postJson('/reports', [...]);

self::assertTrue($disk->fileExists('reports/october.csv'));
```

`fake()` replaces a disk with an empty one in memory that keeps its URL and visibility settings, for the rest of the test.

## Services

| Service | Provides |
|---|---|
| `Crystoline\Xpsr\Storage\StorageManager` | `disk()`, `fake()`, `store()`, and `download()`. Shared. |
| `League\Flysystem\FilesystemOperator` | The default disk. |
| `storage.driver.local`, `storage.driver.memory`, `storage.driver.s3` | The drivers' adapter factories. |

Requires PHP 8.4+ and `ext-fileinfo`.
