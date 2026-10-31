<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Scheduler;

use Closure;
use Cron\CronExpression;
use Crystoline\Xpsr\Queue\JobInterface;
use DateInterval;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use InvalidArgumentException;

/**
 * One scheduled task: what runs, and when. Without a frequency it runs every minute.
 *
 *     $schedule->command('session:prune')->daily('03:00');
 *     $schedule->command('report:send')->weekly('monday', '08:00')->timezone('Africa/Lagos');
 *     $schedule->call(fn(Mailer $mailer) => ...)->name('ping')->everyMinutes(5)->weekdays();
 *
 * Frequencies set the minute, hour, day of month, and month; weekdays(), weekends(), and on()
 * set the day of the week, so they can be combined in either order.
 */
final class Task
{
    private const DAYS = ['sunday' => 0, 'monday' => 1, 'tuesday' => 2, 'wednesday' => 3, 'thursday' => 4, 'friday' => 5, 'saturday' => 6];

    /** @var list<string> minute, hour, day of month, month, day of week */
    private array $fields = ['*', '*', '*', '*', '*'];
    private string $description;
    private ?int $repeatSeconds = null;
    private ?int $lockSeconds = null;
    /** @var list<array{Closure, bool}> */
    private array $conditions = [];

    /**
     * @param 'command'|'call'|'job' $kind
     * @param JobInterface|null $job
     */
    private function __construct(
        public readonly string $kind,
        string $description,
        private string $timezone,
        public readonly ?string $command = null,
        public readonly ?Closure $callback = null,
        public readonly ?object $job = null,
        public readonly ?string $queue = null,
    ) {
        $this->description = $description;
    }

    /** @internal Use Schedule::command(). */
    public static function forCommand(string $command, string $timezone): self
    {
        $command = trim($command);
        if ($command === '') {
            throw new InvalidArgumentException('A scheduled command needs a name, such as "session:prune".');
        }

        return new self('command', $command, $timezone, command: $command);
    }

    /** @internal Use Schedule::call(). */
    public static function forCallback(Closure $callback, string $description, string $timezone): self
    {
        return new self('call', $description, $timezone, callback: $callback);
    }

    /** @internal Use Schedule::job(). */
    public static function forJob(JobInterface $job, ?string $queue, string $timezone): self
    {
        return new self('job', $job::class, $timezone, job: $job, queue: $queue);
    }

    /** A cron expression: five fields (minute hour day-of-month month day-of-week), or @daily and the like. */
    public function cron(string $expression): self
    {
        $parts = CronExpression::isValidExpression($expression) ? (new CronExpression($expression))->getParts() : [];
        if (count($parts) !== 5) {
            throw new InvalidArgumentException("\"{$expression}\" is not a cron expression. Give five fields, minute hour day-of-month month day-of-week, such as \"30 2 * * 1\".");
        }
        $this->fields = array_values($parts);

        return $this;
    }

    public function everyMinute(): self
    {
        return $this->at('*', '*', '*', '*');
    }

    /**
     * Every $seconds seconds within each minute the schedule matches; $seconds must divide a
     * minute evenly. schedule:run repeats the task until the minute it started in ends, in its
     * own process, so keep the task short, or make it a job.
     */
    public function everySeconds(int $seconds): self
    {
        if ($seconds < 1 || $seconds > 30 || 60 % $seconds !== 0) {
            throw new InvalidArgumentException("everySeconds({$seconds}): the seconds must divide a minute evenly (1, 2, 3, 4, 5, 6, 10, 12, 15, 20, or 30). Use everyMinutes() for a minute or longer.");
        }
        $this->repeatSeconds = $seconds;

        return $this;
    }

    /** Every $minutes minutes from the start of each hour; $minutes must divide an hour evenly. */
    public function everyMinutes(int $minutes): self
    {
        if ($minutes < 1 || 60 % $minutes !== 0) {
            throw new InvalidArgumentException("everyMinutes({$minutes}): the minutes must divide an hour evenly (1, 2, 3, 4, 5, 6, 10, 12, 15, 20, or 30). Use cron() for other patterns.");
        }

        return $this->at($minutes === 1 ? '*' : "*/{$minutes}", '*', '*', '*');
    }

    /** Every hour, at $minute past. */
    public function hourly(int $minute = 0): self
    {
        return $this->at((string) self::number($minute, 0, 59, 'minute'), '*', '*', '*');
    }

    /** Every $hours hours from midnight, at $minute past; $hours must divide a day evenly. */
    public function everyHours(int $hours, int $minute = 0): self
    {
        if ($hours < 1 || 24 % $hours !== 0) {
            throw new InvalidArgumentException("everyHours({$hours}): the hours must divide a day evenly (1, 2, 3, 4, 6, 8, or 12). Use cron() for other patterns.");
        }

        return $this->at((string) self::number($minute, 0, 59, 'minute'), $hours === 1 ? '*' : "*/{$hours}", '*', '*');
    }

    /** Every day at $time, such as "03:00" or "17:30". */
    public function daily(string $time = '00:00'): self
    {
        [$hour, $minute] = self::time($time);

        return $this->at($minute, $hour, '*', '*');
    }

    /** Every week on $day ("monday" or 0–7, where 0 and 7 are Sunday) at $time. */
    public function weekly(string|int $day = 'sunday', string $time = '00:00'): self
    {
        [$hour, $minute] = self::time($time);
        $this->at($minute, $hour, '*', '*');

        return $this->days((string) self::day($day));
    }

    /** Every month on day $day (months without that day are skipped) at $time. */
    public function monthly(int $day = 1, string $time = '00:00'): self
    {
        [$hour, $minute] = self::time($time);

        return $this->at($minute, $hour, (string) self::number($day, 1, 31, 'day of the month'), '*');
    }

    /** Every year on $month/$day at $time. */
    public function yearly(int $month = 1, int $day = 1, string $time = '00:00'): self
    {
        [$hour, $minute] = self::time($time);

        return $this->at($minute, $hour, (string) self::number($day, 1, 31, 'day of the month'), (string) self::number($month, 1, 12, 'month'));
    }

    /** Only Monday to Friday. */
    public function weekdays(): self
    {
        return $this->days('1-5');
    }

    /** Only Saturday and Sunday. */
    public function weekends(): self
    {
        return $this->days('0,6');
    }

    /** Only on these days of the week: names such as "monday", or 0–7 (0 and 7 are Sunday). */
    public function on(string|int ...$days): self
    {
        if ($days === []) {
            throw new InvalidArgumentException('on() needs at least one day.');
        }
        $numbers = array_unique(array_map(self::day(...), $days));
        sort($numbers);

        return $this->days(implode(',', $numbers));
    }

    /** The time zone the schedule is read in; the default is scheduler.timezone, then app.timezone. */
    public function timezone(string $timezone): self
    {
        $this->timezone = self::validTimezone($timezone, 'timezone()');

        return $this;
    }

    /** How the task appears in schedule:list and the log, and the name its lock goes by. */
    public function name(string $name): self
    {
        if (trim($name) === '') {
            throw new InvalidArgumentException('A task name cannot be empty.');
        }
        $this->description = $name;

        return $this;
    }

    /**
     * Skip a run while the previous one is still going. The lock is released when the task ends,
     * or when the process holding it dies; on stores that expire locks, it lasts at most
     * $expiresAfter ("1 day", or seconds).
     */
    public function withoutOverlapping(string|int $expiresAfter = '1 day'): self
    {
        $seconds = is_int($expiresAfter) ? $expiresAfter : self::seconds($expiresAfter);
        if ($seconds < 1) {
            throw new InvalidArgumentException("withoutOverlapping() needs a period such as \"1 day\", or a number of seconds above 0.");
        }
        $this->lockSeconds = $seconds;

        return $this;
    }

    /** Run only when $condition returns true. Its parameters are services, by type. */
    public function when(callable $condition): self
    {
        $this->conditions[] = [Closure::fromCallable($condition), true];

        return $this;
    }

    /** Skip the run when $condition returns true. Its parameters are services, by type. */
    public function skip(callable $condition): self
    {
        $this->conditions[] = [Closure::fromCallable($condition), false];

        return $this;
    }

    public function description(): string
    {
        return $this->description;
    }

    public function expression(): string
    {
        return implode(' ', $this->fields);
    }

    public function timezoneName(): string
    {
        return $this->timezone;
    }

    /** The lock lifetime in seconds, or null when the task may overlap. */
    public function lockSeconds(): ?int
    {
        return $this->lockSeconds;
    }

    /** How often the task repeats within a due minute, in seconds, or null when it runs once in it. */
    public function repeatSeconds(): ?int
    {
        return $this->repeatSeconds;
    }

    /** @return list<array{Closure, bool}> conditions, with the result each one needs for the task to run */
    public function conditions(): array
    {
        return $this->conditions;
    }

    /** Whether the schedule matches the minute of $now, in the task's time zone. */
    public function isDue(DateTimeInterface $now): bool
    {
        return (new CronExpression($this->expression()))->isDue(DateTimeImmutable::createFromInterface($now), $this->timezone);
    }

    /** The next time the schedule matches after $now, in the task's time zone. */
    public function nextRun(DateTimeInterface $now): DateTimeImmutable
    {
        $next = (new CronExpression($this->expression()))->getNextRunDate(DateTimeImmutable::createFromInterface($now), 0, false, $this->timezone);

        return DateTimeImmutable::createFromMutable($next)->setTimezone(new DateTimeZone($this->timezone));
    }

    /** @internal The lock name for withoutOverlapping(). */
    public function lockKey(): string
    {
        return 'xpsr-schedule-' . sha1($this->kind . "\0" . $this->description . "\0" . $this->expression());
    }

    /** @internal */
    public static function validTimezone(string $timezone, string $option): string
    {
        try {
            return (new DateTimeZone($timezone))->getName();
        } catch (\Exception) {
            throw new InvalidArgumentException("{$option} must be a time zone identifier such as UTC or Africa/Lagos; \"{$timezone}\" is not one.");
        }
    }

    private function at(string $minute, string $hour, string $dayOfMonth, string $month): self
    {
        [$this->fields[0], $this->fields[1], $this->fields[2], $this->fields[3]] = [$minute, $hour, $dayOfMonth, $month];

        return $this->checkDays();
    }

    private function days(string $daysOfWeek): self
    {
        $this->fields[4] = $daysOfWeek;

        return $this->checkDays();
    }

    /** Cron runs a task when either day field matches, which the helpers would make easy to miss. */
    private function checkDays(): self
    {
        if ($this->fields[2] !== '*' && $this->fields[4] !== '*') {
            throw new InvalidArgumentException('A task cannot be limited to both a day of the month and days of the week: cron would run it when either one matches. Add the other condition with when(), or give the exact cron().');
        }

        return $this;
    }

    /** @return array{string, string} hour and minute */
    private static function time(string $time): array
    {
        if (!preg_match('/\A(\d{1,2}):(\d{2})\z/', $time, $match) || (int) $match[1] > 23 || (int) $match[2] > 59) {
            throw new InvalidArgumentException("\"{$time}\" is not a time of day. Use 24-hour HH:MM, such as \"03:00\" or \"17:30\".");
        }

        return [(string) (int) $match[1], (string) (int) $match[2]];
    }

    private static function day(string|int $day): int
    {
        if (is_string($day)) {
            return self::DAYS[strtolower($day)] ?? throw new InvalidArgumentException("\"{$day}\" is not a day of the week. Use a name such as \"monday\", or 0–7.");
        }

        return self::number($day, 0, 7, 'day of the week') % 7;
    }

    private static function number(int $value, int $min, int $max, string $what): int
    {
        if ($value < $min || $value > $max) {
            throw new InvalidArgumentException("{$value} is not a {$what}: it must be from {$min} to {$max}.");
        }

        return $value;
    }

    private static function seconds(string $period): int
    {
        try {
            return (new DateTimeImmutable('@0'))->add(DateInterval::createFromDateString($period))->getTimestamp();
        } catch (\Exception) {
            return 0;
        }
    }
}
