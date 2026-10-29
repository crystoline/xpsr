<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Storage;

use Crystoline\Xpsr\Http\Exception\NotFoundHttpException;
use Crystoline\Xpsr\Storage\Driver\AdapterFactoryInterface;
use InvalidArgumentException;
use League\Flysystem\Filesystem;
use League\Flysystem\FilesystemException;
use League\Flysystem\FilesystemOperator;
use League\Flysystem\InMemory\InMemoryFilesystemAdapter;
use League\MimeTypeDetection\FinfoMimeTypeDetector;
use LogicException;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Message\UploadedFileInterface;

/**
 * The disks in config/storage.php, each a Flysystem FilesystemOperator:
 *
 *     $storage->disk()->write('reports/october.csv', $csv);       // the default disk
 *     $storage->disk('s3')->readStream('backups/latest.sql.gz');
 *     $path = $storage->store($request->getUploadedFiles()['avatar'], 'avatars');
 *     return $storage->download('invoices/42.pdf', filename: 'Invoice 42.pdf');
 */
final class StorageManager
{
    /** Extensions store() gives files, by the type their content shows; other types get none. */
    private const EXTENSIONS = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/gif' => 'gif',
        'image/webp' => 'webp',
        'image/avif' => 'avif',
        'image/heic' => 'heic',
        'application/pdf' => 'pdf',
        'text/plain' => 'txt',
        'text/csv' => 'csv',
        'application/json' => 'json',
        'application/zip' => 'zip',
        'application/gzip' => 'gz',
        'application/msword' => 'doc',
        'application/vnd.ms-excel' => 'xls',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xlsx',
        'application/vnd.openxmlformats-officedocument.presentationml.presentation' => 'pptx',
        'audio/mpeg' => 'mp3',
        'audio/ogg' => 'ogg',
        'audio/wav' => 'wav',
        'audio/x-wav' => 'wav',
        'video/mp4' => 'mp4',
        'video/webm' => 'webm',
        'video/quicktime' => 'mov',
    ];

    /** @var array<string, FilesystemOperator> */
    private array $disks = [];

    public function __construct(
        private readonly StorageOptions $options,
        private readonly ContainerInterface $container,
        private readonly ResponseFactoryInterface $responses,
        private readonly StreamFactoryInterface $streams,
    ) {}

    /** A disk by name, or the default disk (storage.default). */
    public function disk(?string $name = null): FilesystemOperator
    {
        $name ??= $this->options->default;

        return $this->disks[$name] ??= $this->build($name, null);
    }

    /** @return list<string> the configured disk names */
    public function names(): array
    {
        return array_keys($this->options->disks);
    }

    /**
     * Replaces a disk with an empty in-memory one, for tests. It keeps the disk's URL and
     * visibility settings.
     */
    public function fake(?string $name = null): FilesystemOperator
    {
        $name ??= $this->options->default;

        return $this->disks[$name] = $this->build($name, new InMemoryFilesystemAdapter());
    }

    /**
     * Writes an uploaded file under $directory with a new random name, and returns its path. The
     * extension comes from the file's content, for common document, image, audio, and video
     * types, never from the name the client sent; other files get no extension. Validate the
     * upload's type and size before storing it.
     */
    public function store(UploadedFileInterface $file, string $directory = '', ?string $disk = null): string
    {
        if ($file->getError() !== UPLOAD_ERR_OK) {
            throw new InvalidArgumentException(self::uploadError($file->getError()));
        }
        $stream = $file->getStream();
        $stream->rewind();
        $head = $stream->read(8192);
        $stream->rewind();
        $type = (new FinfoMimeTypeDetector())->detectMimeTypeFromBuffer($head);
        $extension = self::EXTENSIONS[$type ?? ''] ?? null;
        $directory = trim(str_replace('\\', '/', $directory), '/');
        $path = ($directory === '' ? '' : $directory . '/') . bin2hex(random_bytes(16)) . ($extension === null ? '' : '.' . $extension);

        $resource = $stream->detach();
        if (is_resource($resource)) {
            try {
                $this->disk($disk)->writeStream($path, $resource);
            } finally {
                fclose($resource);
            }
        } else {
            $this->disk($disk)->write($path, (string) $stream);
        }

        return $path;
    }

    /**
     * A response that sends a stored file, as an attachment (or $inline, for types the browser
     * should show, such as images and PDFs). A missing file is a 404.
     */
    public function download(string $path, ?string $disk = null, ?string $filename = null, bool $inline = false): ResponseInterface
    {
        $files = $this->disk($disk);
        if (!$files->fileExists($path)) {
            throw new NotFoundHttpException('The file was not found.');
        }
        try {
            $type = $files->mimeType($path);
        } catch (FilesystemException) {
            $type = 'application/octet-stream';
        }
        $response = $this->responses->createResponse(200)
            ->withHeader('Content-Type', $type)
            ->withHeader('Content-Disposition', self::disposition($inline ? 'inline' : 'attachment', $filename ?? basename($path)))
            ->withHeader('X-Content-Type-Options', 'nosniff');
        try {
            $response = $response->withHeader('Content-Length', (string) $files->fileSize($path));
        } catch (FilesystemException) {
            // Sent without a length.
        }

        return $response->withBody($this->streams->createStreamFromResource($files->readStream($path)));
    }

    private function build(string $name, ?InMemoryFilesystemAdapter $adapter): FilesystemOperator
    {
        $options = $this->options->disks[$name] ?? throw new InvalidArgumentException(
            "There is no disk named \"{$name}\" in storage.disks. Disks: " . implode(', ', $this->names()) . '.',
        );
        if ($adapter === null) {
            $driver = (string) $options['driver'];
            $factory = $this->container->has("storage.driver.{$driver}") ? $this->container->get("storage.driver.{$driver}") : null;
            if (!$factory instanceof AdapterFactoryInterface) {
                throw new LogicException("storage.disks.{$name}.driver is \"{$driver}\", which is not a driver. Built in: local, memory, and s3 (with league/flysystem-async-aws-s3). Others are services named storage.driver.<driver> that implement " . AdapterFactoryInterface::class . '.');
            }
            $adapter = $factory->create($name, array_diff_key($options, array_flip(StorageOptions::COMMON)));
        }

        return new Filesystem($adapter, array_filter([
            'public_url' => $options['url'] ?? null,
            'visibility' => $options['visibility'] ?? null,
            'directory_visibility' => $options['directory_visibility'] ?? null,
        ], static fn(mixed $value): bool => $value !== null));
    }

    private static function disposition(string $type, string $filename): string
    {
        $filename = str_replace(['/', '\\'], '_', $filename);
        $fallback = (string) preg_replace('/[^\x20-\x7e]|["%]/', '_', $filename);
        $header = "{$type}; filename=\"{$fallback}\"";

        return $fallback === $filename ? $header : $header . "; filename*=UTF-8''" . rawurlencode($filename);
    }

    private static function uploadError(int $error): string
    {
        return match ($error) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'The uploaded file is larger than the server allows (upload_max_filesize).',
            UPLOAD_ERR_PARTIAL => 'The file was only partly uploaded.',
            UPLOAD_ERR_NO_FILE => 'No file was uploaded.',
            UPLOAD_ERR_NO_TMP_DIR, UPLOAD_ERR_CANT_WRITE, UPLOAD_ERR_EXTENSION => 'The server could not save the uploaded file.',
            default => "The upload failed (error {$error}).",
        };
    }
}
