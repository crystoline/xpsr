<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Broadcast\Testing;

use Closure;
use Crystoline\Xpsr\Broadcast\BroadcastInterface;
use Crystoline\Xpsr\Broadcast\Update;
use PHPUnit\Framework\Assert;

/**
 * Keeps the updates the application would have published, with assertions:
 *
 *     $this->swap(BroadcastInterface::class, $broadcast = new FakeBroadcast());
 *     $this->postJson('/orders/42/pay');
 *     $broadcast->assertPublished('orders/42', fn(Update $update): bool => $update->json()['status'] === 'paid');
 */
final class FakeBroadcast implements BroadcastInterface
{
    /** @var list<Update> */
    private array $published = [];

    public function publish(string|array $topics, mixed $data, bool $private = false, ?string $type = null, ?string $id = null, ?int $retry = null): void
    {
        $this->publishUpdate(new Update($topics, $data, $private, $type, $id, $retry));
    }

    public function publishUpdate(Update $update): void
    {
        $this->published[] = $update;
    }

    /**
     * The updates published, in order; those to a topic when one is given.
     *
     * @return list<Update>
     */
    public function published(?string $topic = null): array
    {
        return array_values(array_filter($this->published, static fn(Update $update): bool => $topic === null || $update->hasTopic($topic)));
    }

    /**
     * Asserts an update went to the topic, passing the check when given, and returns the first
     * such update. With $times, asserts that many did.
     *
     * @param (Closure(Update): bool)|null $check
     */
    public function assertPublished(string $topic, ?Closure $check = null, ?int $times = null): Update
    {
        $matching = $this->matching($topic, $check);
        $message = sprintf('No update to "%s"%s was published. Published: %s.', $topic, $check === null ? '' : ' passing the check', $this->summary());
        Assert::assertNotSame([], $matching, $message);
        if ($times !== null) {
            Assert::assertCount($times, $matching, sprintf('%d update(s) to "%s"%s were published, not %d. Published: %s.', count($matching), $topic, $check === null ? '' : ' passing the check', $times, $this->summary()));
        }

        return $matching[0];
    }

    /** @param (Closure(Update): bool)|null $check */
    public function assertNotPublished(string $topic, ?Closure $check = null): void
    {
        $matching = $this->matching($topic, $check);
        Assert::assertCount(0, $matching, sprintf('%d update(s) to "%s"%s were published.', count($matching), $topic, $check === null ? '' : ' passing the check'));
    }

    public function assertNothingPublished(): void
    {
        Assert::assertSame([], $this->published, 'Updates were published: ' . $this->summary() . '.');
    }

    public function clear(): void
    {
        $this->published = [];
    }

    /**
     * @param (Closure(Update): bool)|null $check
     * @return list<Update>
     */
    private function matching(string $topic, ?Closure $check): array
    {
        return array_values(array_filter($this->published($topic), static fn(Update $update): bool => $check === null || $check($update)));
    }

    private function summary(): string
    {
        return $this->published === [] ? 'nothing' : implode('; ', array_map(static fn(Update $update): string => implode(', ', $update->topics), $this->published));
    }
}
