# crystoline/xpsr-scheduler

Scheduled tasks for XPSR applications: console commands, callbacks, and queued jobs on cron schedules, all run from one cron entry.

```sh
composer require crystoline/xpsr-scheduler
```

Add the provider to `app.registry` in `config/app.php`:

```php
Crystoline\Xpsr\Scheduler\SchedulerServiceRegistry::class,
```

and one entry to the server's crontab, which runs the tasks that are due each minute:

```cron
* * * * * cd /var/www/app && php xpsr schedule:run > /dev/null
```

Failures are written to stderr, so cron mails them, and to the application log. Without cron (in development, or in a container), `php xpsr schedule:work` does the same in the foreground.

## Tasks

Tasks are defined in `routes/schedule.php`:

```php
<?php

use App\Job\PruneUploads;
use App\Repository\TaskRepository;
use Crystoline\Xpsr\Scheduler\Schedule;

return static function (Schedule $schedule): void {
    $schedule->command('session:prune')->daily('03:00');
    $schedule->command('report:send --weekly')->weekly('monday', '08:00')->timezone('Africa/Lagos');
    $schedule->job(new PruneUploads())->hourly();
    $schedule->call(static function (TaskRepository $tasks): void {
        $tasks->archiveFinished();
    }, 'archive finished tasks')->everyMinutes(30)->weekdays();
};
```

| Task | Runs |
|---|---|
| `command('name --option argument')` | A console command, in the `schedule:run` process. A non-zero exit code is a failure. |
| `call($callable, ?$name)` | A function or closure, in the `schedule:run` process. Its parameters are services from the container, by type. |
| `job($job, ?$queue)` | Queues a [`crystoline/xpsr-queue`](../queue/README.md) job, so a worker runs it. Needs the queue provider. |

The due tasks run one after another. Move long work into a queued job, so it doesn't hold up the tasks after it.

## Frequencies

| Method | Cron |
|---|---|
| (none) | `* * * * *`, every minute |
| `everyMinute()` | `* * * * *` |
| `everyMinutes(15)` | `*/15 * * * *` (1, 2, 3, 4, 5, 6, 10, 12, 15, 20, or 30) |
| `hourly()`, `hourly(30)` | `0 * * * *`, `30 * * * *` |
| `everyHours(6)`, `everyHours(6, 30)` | `0 */6 * * *`, `30 */6 * * *` (1, 2, 3, 4, 6, 8, or 12) |
| `daily()`, `daily('03:00')` | `0 0 * * *`, `0 3 * * *` |
| `weekly()`, `weekly('monday', '08:00')` | `0 0 * * 0`, `0 8 * * 1` |
| `monthly()`, `monthly(15, '12:00')` | `0 0 1 * *`, `0 12 15 * *` (months without that day are skipped) |
| `yearly()`, `yearly(7, 4, '09:00')` | `0 0 1 1 *`, `0 9 4 7 *` |
| `cron('30 2 * * 1-5')`, `cron('@daily')` | as given |

`weekdays()`, `weekends()`, and `on('monday', 'thursday')` limit any of them to days of the week, in either order: `daily('08:00')->weekdays()` is `0 8 * * 1-5`. They cannot be combined with a day of the month, because cron would run the task when either one matches; use `when()` for that.

Times are 24-hour `HH:MM`. Schedules are read in `app.timezone` (or `scheduler.timezone`), and `->timezone('Africa/Lagos')` gives a task its own. Across daylight-saving changes, a time that clocks skip when they go forward runs an hour later, and one that happens twice when they go back runs twice. For tasks that must run exactly once a day, schedule them in UTC or outside the hours clocks change.

## Conditions and overlap

```php
$schedule->command('backup:run')->daily('01:00')->withoutOverlapping();
$schedule->command('invoices:remind')->daily('09:00')->when(static fn(Settings $settings): bool => $settings->remindersOn());
$schedule->call($sync, 'sync')->everyMinutes(5)->skip(static fn(Maintenance $maintenance): bool => $maintenance->active());
```

`when()` and `skip()` take callables whose parameters are services. `withoutOverlapping()` skips a run while the previous one is still going. Its lock is released when the task ends, or when the process holding it dies. On a lock store that expires locks, it lasts at most the period given (`'1 day'` by default, or seconds).

`name('nightly cleanup')` sets how a task appears in `schedule:list` and the log. Commands are named by their command line, jobs by their class, and closures by their file and line.

## Commands

```sh
php xpsr schedule:run                     # the tasks due this minute; from cron
php xpsr schedule:list                    # every task, its cron expression, time zone, and next run
php xpsr schedule:work                    # schedule:run at the start of each minute, until stopped
php xpsr schedule:work --time-limit=3600
```

`schedule:work` starts `php xpsr schedule:run` as a new process each minute, so it picks up code changes. SIGTERM and SIGINT stop it after the current run.

## Configuration

`config/scheduler.php` is optional:

```php
return [
    'files' => ['routes/schedule.php'],    // the files that define tasks
    'timezone' => 'UTC',                   // the default: app.timezone
    'lock' => env('SCHEDULER_LOCK'),
];
```

`lock` is where `withoutOverlapping()` keeps its locks. Unset, the scheduler uses the application's locks from [`crystoline/xpsr-lock`](../lock/README.md) when `LockServiceRegistry` is in `app.registry`, and `flock` otherwise. `flock` uses files in `var/locks`, which works on one server. With several servers running `schedule:run`, use a store they share. Either configure the application's locks (Redis, Redlock, or the database), or set `lock` to a [Symfony Lock](https://symfony.com/doc/current/components/lock.html#available-stores) DSN for the scheduler alone, such as `redis://localhost:6379` (which needs `symfony/cache`), `postgresql+advisory://...`, or a PDO DSN.

## Services

| Service | Provides |
|---|---|
| `Crystoline\Xpsr\Scheduler\Schedule` | The tasks from `scheduler.files`: `tasks()`, `due($time)`. Shared. |

Requires PHP 8.4+.
