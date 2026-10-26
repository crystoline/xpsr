<?php

declare(strict_types=1);

namespace Crystoline\Tests\Unit;

use Crystoline\Log\ErrorLogLogger;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Psr\Log\InvalidArgumentException;
use Psr\Log\LogLevel;
use RuntimeException;
use stdClass;

final class ErrorLogLoggerTest extends TestCase
{
    /** @var list<string> */
    private array $lines = [];

    private function logger(string $minimum = LogLevel::DEBUG): ErrorLogLogger
    {
        return new ErrorLogLogger($minimum, function (string $line): void {
            $this->lines[] = $line;
        });
    }

    public function testInterpolatesContextAndAppendsExceptions(): void
    {
        $exception = new RuntimeException('database unavailable');
        $this->logger()->error('User {user} failed at {time}: {flag} {missing} {object}', [
            'user' => 'ada',
            'time' => new DateTimeImmutable('2026-10-26T10:00:00+00:00'),
            'flag' => false,
            'object' => new stdClass(),
            'exception' => $exception,
        ]);
        self::assertCount(1, $this->lines);
        [$first] = explode(PHP_EOL, $this->lines[0], 2);
        self::assertSame('[ERROR] User ada failed at 2026-10-26T10:00:00+00:00: false {missing} {object}', $first);
        self::assertStringContainsString('RuntimeException: database unavailable', $this->lines[0]);
        self::assertStringContainsString(__FILE__, $this->lines[0], 'The stack trace is included.');
    }

    public function testMinimumLevelFiltersLowerSeverities(): void
    {
        $logger = $this->logger(LogLevel::WARNING);
        $logger->info('skip');
        $logger->notice('skip');
        $logger->warning('keep warning');
        $logger->emergency('keep emergency');
        self::assertSame(['[WARNING] keep warning', '[EMERGENCY] keep emergency'], $this->lines);
    }

    public function testUnknownLevelsAreRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->logger()->log('verbose', 'message');
    }

    public function testUnknownMinimumLevelIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new ErrorLogLogger('loud');
    }
}
