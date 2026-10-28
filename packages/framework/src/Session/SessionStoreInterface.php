<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Session;

/**
 * Where sessions are kept. A store holds each session's payload, an opaque string, under its ID,
 * and forgets it once it has not been written for the session lifetime.
 *
 * Implementations are checked by Crystoline\Xpsr\Testing\SessionStoreContractTestCase.
 */
interface SessionStoreInterface
{
    /** The payload written under this ID, or null when there is none or it has expired. */
    public function read(string $id): ?string;

    /** Store a payload under this ID, replacing any other, and restart its lifetime. */
    public function write(string $id, string $payload): void;

    /** Forget the payload under this ID; nothing happens when there is none. */
    public function destroy(string $id): void;

    /** Remove expired payloads and return how many; 0 for stores that expire entries themselves. */
    public function prune(): int;
}
