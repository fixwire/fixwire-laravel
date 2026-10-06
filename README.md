# Fixwire for Laravel

[![CI](https://github.com/fixwire/fixwire-laravel/actions/workflows/ci.yml/badge.svg)](https://github.com/fixwire/fixwire-laravel/actions/workflows/ci.yml)

Fixwire in a Laravel 11, 12 or 13 app (PHP 8.2+): the exceptions Laravel
reports, each request as a trace named after its route, the signed-in user,
queue jobs, scheduled tasks as cron monitors, and release health. It builds
on the [PHP SDK](https://github.com/fixwire/fixwire-php), which comes with it.

```sh
composer require fixwire/laravel
```

```sh
# .env
FIXWIRE_DSN=https://fw_pk_live_…@ingest.eu.fixwire.io
FIXWIRE_RELEASE=shop@1.4.0
FIXWIRE_TRACES_SAMPLE_RATE=0.2
```

That's all: package discovery registers the service provider. Without a DSN
(local development, your test suite) it does nothing. To change more, publish
the configuration:

```sh
php artisan vendor:publish --tag=fixwire-config   # config/fixwire.php
```

## What it does

- **Exceptions Laravel reports** go to Fixwire as crashes, with the request,
  the route (`GET /orders/{id}`) and the user, and the breadcrumbs that led
  to them: log records, SQL queries (without their values), queue jobs,
  Artisan commands, HTTP calls. What Laravel doesn't report (404s,
  validation errors, unauthenticated requests, your `dontReport` list)
  isn't sent either. An exception you capture yourself and also `report()`
  is sent once.
- **The signed-in user**, from the default guard once your authentication
  ran (any guard: session, token, Sanctum, `Auth::viaRequest`), without an
  extra lookup. Its id only; the email too with `send_default_pii`.
- **Each request** gets its own scope and, with tracing on, is a server span
  named after its route that continues the caller's trace, with its queries
  and HTTP calls under it.
- **Queue jobs** run in their own scope (the `queue` tag, a `job` context)
  as a span that continues the trace of the code that dispatched them: the
  trace travels in the job's payload. A job that throws is a crash, with
  the job. A worker sends after each job.
- **The `Http` facade's requests** are client spans and breadcrumbs, with
  trace headers sent only to `trace_propagation_targets`.
- **Release health**, with `FIXWIRE_AUTO_SESSION_TRACKING=true`: each
  request is a session, ended well, with an error, or crashed. It costs one
  more request to Fixwire per request, so it is off.

What was captured is sent at the end of the request, in the middleware's
`terminate`: under PHP-FPM, after the response has gone out. Under Octane,
where one worker serves request after request, each request still has its
own scope, user and breadcrumbs, and is sent when it ends. The example's
`OctaneTest` drives Octane's own worker loop to check that.

## Scheduled tasks

```php
// routes/console.php
Schedule::command('reports:send')->dailyAt('03:00')->timezone('Europe/Berlin')
    ->fixwireMonitor('nightly-report', checkInMargin: 10);
```

Each run sends a check-in when it starts and one when it ends, ok or
failed. The first creates the monitor from the task's own schedule and
timezone, so Fixwire also notices a night the task doesn't run. Without a
name, the monitor is named after the task's description or command.

## The rest of the SDK

Everything in the PHP SDK works as it does there:
`Fixwire\captureException()`, `Fixwire\withScope()`,
`Fixwire\setTag()`, `Fixwire\trace()`, `Fixwire\captureFeedback()`, … Any
of its options can go in `config/fixwire.php` (`before_send`,
`error_budget`, `sensitive_keys`, …). Log records become breadcrumbs; to
send error log records without an exception as events too, add a channel:

```php
// config/logging.php
'fixwire' => ['driver' => 'monolog', 'handler' => Fixwire\Monolog\Handler::class],
```

## Example

[example](example) is a Laravel app (an API, a queued job, a scheduled
report) whose test runs it with `php -S` and `php artisan` against a fake
ingest.

## Building

```sh
composer install
composer test && composer analyse && composer check-format
(cd example && composer install && vendor/bin/phpunit)
```

## License

MIT.
