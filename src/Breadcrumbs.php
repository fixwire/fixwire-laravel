<?php

declare(strict_types=1);

namespace Fixwire\Laravel;

use Fixwire\Breadcrumb;
use Fixwire\Hub;
use Fixwire\Level;
use Fixwire\Span;
use Fixwire\SpanKind;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Log\Events\MessageLogged;

/**
 * @internal log records, SQL queries and Artisan commands as breadcrumbs; queries also as spans
 * of a sampled trace. SQL goes without its bindings (the values), and is redacted anyway.
 */
final class Breadcrumbs
{
    /** @param array<array-key, mixed> $on */
    public static function register(Dispatcher $events, array $on, bool $querySpans): void
    {
        if ((bool) ($on['logs'] ?? true)) {
            $events->listen(MessageLogged::class, static function (MessageLogged $e): void {
                $level = match ($e->level) {
                    'emergency', 'alert', 'critical' => Level::Fatal,
                    'error' => Level::Error,
                    'warning' => Level::Warning,
                    'notice', 'info' => Level::Info,
                    default => null,
                };
                // Debug records are left out; one with an exception is that exception's report.
                if ($level === null || isset($e->context['exception'])) {
                    return;
                }
                Hub::current()->addBreadcrumb(new Breadcrumb('log', $e->message, $level, 'log', $e->context));
            });
        }
        $queries = (bool) ($on['queries'] ?? true);
        if ($queries || $querySpans) {
            $events->listen(QueryExecuted::class, static fn(QueryExecuted $e) => self::query($e, $queries, $querySpans));
        }
        if ((bool) ($on['commands'] ?? true)) {
            $events->listen(CommandStarting::class, static function (CommandStarting $e): void {
                Hub::current()->addBreadcrumb(new Breadcrumb('command', 'artisan ' . $e->command));
            });
        }
    }

    private static function query(QueryExecuted $e, bool $breadcrumb, bool $span): void
    {
        $hub = Hub::current();
        $sql = $e->sql;
        $ms = (float) $e->time;
        if ($breadcrumb) {
            $hub->addBreadcrumb(new Breadcrumb('db.query', $sql, type: 'query', data: ['connection' => $e->connectionName, 'duration_ms' => $ms]));
        }
        $parent = $hub->getSpan();
        if ($span && $parent !== null && $parent->sampled) {
            // The query ran already: its span is dated back by its duration.
            $end = microtime(true);
            Span::start($hub, mb_substr($sql, 0, 200), 'db.query', [
                'db.system.name' => $e->connection->getDriverName(),
                'db.namespace' => $e->connection->getDatabaseName(),
                'db.query.text' => $sql,
            ], SpanKind::Client, current: false, startTime: $end - $ms / 1000)->finish($end);
        }
    }
}
