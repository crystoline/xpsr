<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Broadcast;

use InvalidArgumentException;
use JsonException;

/**
 * An update to publish: the topics it goes to, its data, and whether it is private.
 *
 *     new Update('orders/42', ['status' => 'paid']);
 *     new Update(['orders/42', 'users/7/orders'], ['status' => 'paid'], private: true, type: 'order.paid');
 *
 * Data that is not a string is encoded as JSON; a string is sent as it is. A private update reaches
 * only subscribers whose authorization covers one of its topics (see Subscriptions). The type is
 * the event name browsers listen for with addEventListener(); without one, they get a "message"
 * event. The ID and the retry delay are the hub's defaults when left out.
 */
final class Update
{
    private const CONTROL = '/[\x00-\x1F\x7F]/';

    /** @var list<string> */
    public readonly array $topics;
    public readonly string $data;

    /**
     * @param string|array<array-key, mixed> $topics a topic, or a list of them
     * @param int|null $retry milliseconds browsers wait before reconnecting, sent with the update
     */
    public function __construct(
        string|array $topics,
        mixed $data,
        public readonly bool $private = false,
        public readonly ?string $type = null,
        public readonly ?string $id = null,
        public readonly ?int $retry = null,
    ) {
        $list = [];
        foreach (is_string($topics) ? [$topics] : $topics as $topic) {
            if (!is_string($topic) || $topic === '' || preg_match(self::CONTROL, $topic) === 1) {
                throw new InvalidArgumentException('A topic must be a non-empty string without control characters, such as "orders/42".');
            }
            $list[] = $topic;
        }
        if ($list === []) {
            throw new InvalidArgumentException('An update needs at least one topic.');
        }
        $this->topics = $list;
        $this->data = is_string($data) ? $data : self::encode($data);
        foreach (['type' => $type, 'id' => $id] as $name => $value) {
            if ($value !== null && ($value === '' || preg_match(self::CONTROL, $value) === 1)) {
                throw new InvalidArgumentException("An update's {$name} must be a non-empty string without control characters.");
            }
        }
        if ($retry !== null && $retry < 0) {
            throw new InvalidArgumentException("An update's retry must be 0 or more milliseconds.");
        }
    }

    /** Whether the update goes to a topic. */
    public function hasTopic(string $topic): bool
    {
        return in_array($topic, $this->topics, true);
    }

    /** The data decoded from JSON: the array or object given, or what a JSON string holds. */
    public function json(): mixed
    {
        try {
            return json_decode($this->data, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new InvalidArgumentException('The update data is not JSON: ' . $e->getMessage(), 0, $e);
        }
    }

    private static function encode(mixed $data): string
    {
        try {
            return json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
        } catch (JsonException $e) {
            throw new InvalidArgumentException('The update data cannot be encoded as JSON: ' . $e->getMessage(), 0, $e);
        }
    }
}
