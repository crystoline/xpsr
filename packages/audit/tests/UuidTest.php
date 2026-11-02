<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Audit\Tests;

use Crystoline\Xpsr\Audit\Uuid;
use PHPUnit\Framework\TestCase;

final class UuidTest extends TestCase
{
    public function testUuidsAreVersion7AndOrderedAsMade(): void
    {
        $ids = [];
        for ($i = 0; $i < 5000; $i++) {
            $ids[] = Uuid::next();
        }
        foreach ($ids as $i => $id) {
            self::assertTrue(Uuid::is($id), $id);
            self::assertSame('7', $id[14], 'The version nibble.');
            self::assertContains($id[19], ['8', '9', 'a', 'b'], 'The variant nibble.');
            if ($i > 0) {
                self::assertGreaterThan($ids[$i - 1], $id, 'Made later, so greater, within one millisecond too.');
            }
        }
        self::assertCount(5000, array_unique($ids));
        self::assertFalse(Uuid::is('not-a-uuid'));
    }
}
