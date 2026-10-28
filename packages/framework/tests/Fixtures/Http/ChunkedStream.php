<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Tests\Fixtures\Http;

use Psr\Http\Message\StreamInterface;
use RuntimeException;

/** A read-only, non-seekable stream of unknown size that returns preset chunks, like a generator. */
final class ChunkedStream implements StreamInterface
{
    /** @var list<string> */
    public array $reads = [];

    /** @param list<string> $chunks */
    public function __construct(private array $chunks) {}

    public function __toString(): string
    {
        return $this->getContents();
    }

    public function close(): void
    {
        $this->chunks = [];
    }

    public function detach()
    {
        $this->chunks = [];

        return null;
    }

    public function getSize(): ?int
    {
        return null;
    }

    public function tell(): int
    {
        throw new RuntimeException('Not seekable.');
    }

    public function eof(): bool
    {
        return $this->chunks === [];
    }

    public function isSeekable(): bool
    {
        return false;
    }

    public function seek($offset, $whence = SEEK_SET): void
    {
        throw new RuntimeException('Not seekable.');
    }

    public function rewind(): void
    {
        throw new RuntimeException('Not seekable.');
    }

    public function isWritable(): bool
    {
        return false;
    }

    public function write($string): int
    {
        throw new RuntimeException('Not writable.');
    }

    public function isReadable(): bool
    {
        return true;
    }

    public function read($length): string
    {
        $chunk = array_shift($this->chunks) ?? '';
        if (strlen($chunk) > $length) {
            array_unshift($this->chunks, substr($chunk, $length));
            $chunk = substr($chunk, 0, $length);
        }
        $this->reads[] = $chunk;

        return $chunk;
    }

    public function getContents(): string
    {
        $contents = implode('', $this->chunks);
        $this->chunks = [];

        return $contents;
    }

    public function getMetadata($key = null)
    {
        return $key === null ? [] : null;
    }
}
