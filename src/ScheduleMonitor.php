<?php

declare(strict_types=1);

namespace Fixwire\Laravel;

use Fixwire\CheckIn;
use Fixwire\CheckInStatus;
use Fixwire\MonitorConfig;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Support\Str;

/**
 * @internal the fixwireMonitor() method of scheduled tasks: check-ins to a monitor around each
 * run, the monitor created from the task's own schedule and timezone.
 *
 *     Schedule::command('reports:send')->dailyAt('03:00')->fixwireMonitor('nightly-report');
 */
final class ScheduleMonitor
{
    private ?string $run = null;

    private float $started = 0.0;

    private function __construct(private string $monitor, private MonitorConfig $config) {}

    /** The macro: Laravel calls it bound to the task. */
    public static function macro(): \Closure
    {
        return self::boundToTask(function (?string $monitor = null, int $checkInMargin = 5, int $maxRuntime = 0): Event {
            return ScheduleMonitor::attach($this, $monitor, $checkInMargin, $maxRuntime);
        });
    }

    /** @param-closure-this Event $macro */
    private static function boundToTask(\Closure $macro): \Closure
    {
        return $macro;
    }

    public static function attach(Event $event, ?string $monitor, int $checkInMargin, int $maxRuntime): Event
    {
        $monitor ??= Str::slug((string) ($event->description ?? $event->command ?? 'scheduled-task'));
        $timezone = $event->timezone instanceof \DateTimeZone ? $event->timezone->getName() : $event->timezone; // or null: the app's
        $run = new self($monitor, MonitorConfig::crontab($event->expression, $checkInMargin, $maxRuntime, $timezone));

        return $event
            ->before($run->start(...))
            ->onSuccess(static fn() => $run->end(CheckInStatus::Ok))
            ->onFailure(static fn() => $run->end(CheckInStatus::Error));
    }

    private function start(): void
    {
        $this->run = \Fixwire\captureCheckIn(new CheckIn($this->monitor, CheckInStatus::InProgress, config: $this->config));
        $this->started = microtime(true);
    }

    private function end(CheckInStatus $status): void
    {
        // A task run in the background ends in another process: then without the start's id.
        $duration = $this->run === null ? null : microtime(true) - $this->started;
        \Fixwire\captureCheckIn(new CheckIn($this->monitor, $status, $this->run, $duration));
        $this->run = null;
    }
}
