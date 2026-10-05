<?php

declare(strict_types=1);

namespace Fixwire\Laravel;

use Fixwire\Breadcrumb;
use Fixwire\Hub;
use Fixwire\Span;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Queue as LaravelQueue;

/**
 * @internal queue jobs: each runs in its own scope (its tag and context, its breadcrumbs), as a
 * span that continues the trace of the code that dispatched it, and what it captured is sent when
 * it ends. A job that throws is a crash, with the job.
 */
final class Queue
{
    /** @var list<array{span: ?Span, sync: bool}> the jobs under way, innermost last (sync jobs nest) */
    private static array $running = [];

    private static bool $breadcrumbs = true;

    public static function register(Dispatcher $events, bool $breadcrumbs, bool $tracing): void
    {
        self::$breadcrumbs = $breadcrumbs;
        if ($tracing) {
            // The dispatching code's trace travels in the job's payload.
            LaravelQueue::createPayloadUsing(static function (): array {
                $span = Hub::current()->getSpan();

                return $span === null ? [] : ['fixwire' => array_filter([
                    'traceparent' => $span->traceparent(),
                    'tracestate' => $span->tracestate,
                    'baggage' => $span->baggage,
                ], static fn(?string $v): bool => $v !== null)];
            });
        }
        $events->listen(JobProcessing::class, static fn(JobProcessing $e) => self::started($e->job, $e->connectionName, $tracing));
        $events->listen(JobProcessed::class, static fn() => self::ended(null));
        $events->listen(JobExceptionOccurred::class, static fn(JobExceptionOccurred $e) => self::ended($e->exception));
    }

    private static function started(Job $job, string $connection, bool $tracing): void
    {
        $hub = Hub::current();
        $name = $job->resolveName();
        $sync = $connection === 'sync';
        $crumb = new Breadcrumb('queue', "processing {$name}", data: ['queue' => $job->getQueue(), 'attempts' => $job->attempts()]);
        if (self::$breadcrumbs && $sync) {
            $hub->addBreadcrumb($crumb); // the code that dispatched it sees that it ran
        }
        $scope = $hub->pushScope();
        $scope->setTag('queue', $job->getQueue());
        $scope->setContext('job', ['name' => $name, 'connection' => $connection, 'id' => $job->getJobId(), 'attempts' => $job->attempts()]);
        $scope->setTransaction($name);
        if (self::$breadcrumbs && !$sync) {
            $hub->addBreadcrumb($crumb);
        }
        $span = null;
        if ($tracing) {
            $trace = $job->payload()['fixwire'] ?? [];
            $trace = \is_array($trace) ? $trace : [];
            $span = $hub->continueTrace(
                \is_string($trace['traceparent'] ?? null) ? $trace['traceparent'] : null,
                \is_string($trace['tracestate'] ?? null) ? $trace['tracestate'] : null,
                \is_string($trace['baggage'] ?? null) ? $trace['baggage'] : null,
                $name,
                'queue.process',
                ['messaging.system' => 'laravel', 'messaging.destination.name' => $job->getQueue(), 'messaging.message.id' => $job->getJobId()],
            );
        }
        self::$running[] = ['span' => $span, 'sync' => $sync];
    }

    private static function ended(?\Throwable $error): void
    {
        $job = array_pop(self::$running);
        if ($job === null) {
            return;
        }
        $hub = Hub::current();
        if ($error !== null) {
            // Sent here, in the job's scope; Laravel's report of it afterwards is skipped.
            $hub->captureException($error, 'laravel.queue', false);
            $job['span']?->setError($error);
        }
        $job['span']?->finish();
        $hub->popScope();
        if (!$job['sync']) {
            $hub->flush(); // a worker runs for hours; a sync job is sent with its request
        }
    }
}
