<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Storage\Driver;

use AsyncAws\S3\S3Client;
use Crystoline\Xpsr\Storage\StorageOptions;
use InvalidArgumentException;
use League\Flysystem\AsyncAwsS3\AsyncAwsS3Adapter;
use League\Flysystem\FilesystemAdapter;
use LogicException;

/**
 * The s3 driver: Amazon S3, or a compatible service through endpoint (Cloudflare R2, MinIO,
 * DigitalOcean Spaces, ...). Needs league/flysystem-async-aws-s3.
 *
 *   bucket         the bucket (required)
 *   region         default us-east-1
 *   key, secret    the access key; without them, the AWS environment variables, shared
 *                  credentials file, or instance role are used
 *   session_token  for temporary credentials
 *   endpoint       for S3-compatible services, such as https://<account>.r2.cloudflarestorage.com
 *   path_style     true for services that need bucket names in the path, such as MinIO
 *   prefix         a folder inside the bucket that the disk is limited to
 *
 * @internal Registered as storage.driver.s3.
 */
final class S3AdapterFactory implements AdapterFactoryInterface
{
    private const CLIENT_OPTIONS = ['region' => 'region', 'key' => 'accessKeyId', 'secret' => 'accessKeySecret', 'session_token' => 'sessionToken', 'endpoint' => 'endpoint'];

    public function create(string $disk, array $options): FilesystemAdapter
    {
        if (!class_exists(AsyncAwsS3Adapter::class)) {
            throw new LogicException("storage.disks.{$disk} uses the s3 driver, which needs league/flysystem-async-aws-s3: run composer require league/flysystem-async-aws-s3.");
        }
        StorageOptions::assertKnown($disk, 's3', $options, ['bucket', 'region', 'key', 'secret', 'session_token', 'endpoint', 'path_style', 'prefix']);
        $bucket = $options['bucket'] ?? null;
        if (!is_string($bucket) || $bucket === '') {
            throw new InvalidArgumentException("storage.disks.{$disk}.bucket must name the bucket.");
        }
        $client = [];
        foreach (self::CLIENT_OPTIONS as $option => $name) {
            $value = $options[$option] ?? null;
            if ($value === null || $value === '') {
                continue;
            }
            if (!is_string($value)) {
                throw new InvalidArgumentException("storage.disks.{$disk}.{$option} must be a string.");
            }
            $client[$name] = $value;
        }
        $pathStyle = $options['path_style'] ?? false;
        $prefix = $options['prefix'] ?? '';
        if (!is_bool($pathStyle) || !is_string($prefix)) {
            throw new InvalidArgumentException("storage.disks.{$disk}.path_style must be true or false, and prefix a string.");
        }
        $client['pathStyleEndpoint'] = $pathStyle ? 'true' : 'false';

        return new AsyncAwsS3Adapter(new S3Client($client), $bucket, $prefix);
    }
}
