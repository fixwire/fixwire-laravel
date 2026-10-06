<div align="center">

_Bugs reach production. Fixwire finds them first: errors, traces, logs and
AI agent runs in one place, an AI debugger on every plan, and your data
kept in Europe._

[![Discord](https://img.shields.io/badge/Discord-join%20us-5865F2?logo=discord&logoColor=white)](https://fixwire.io/discord)
[![Slack](https://img.shields.io/badge/Slack-community-4A154B?logo=slack&logoColor=white)](https://fixwire.io/slack)
[![X](https://img.shields.io/badge/X-follow%20us-000000?logo=x&logoColor=white)](https://fixwire.io/x)
[![Release](https://img.shields.io/github/v/release/fixwire/fixwire-laravel?label=release)](https://github.com/fixwire/fixwire-laravel/releases)
[![PHP](https://img.shields.io/badge/php-8.2%20%7C%208.3%20%7C%208.4%20%7C%208.5-blue?logo=php&logoColor=white)](https://github.com/fixwire/fixwire-laravel/actions/workflows/ci.yml)
[![Laravel](https://img.shields.io/badge/laravel-11%20%7C%2012%20%7C%2013-FF2D20?logo=laravel&logoColor=white)](https://github.com/fixwire/fixwire-laravel/actions/workflows/ci.yml)
[![CI](https://github.com/fixwire/fixwire-laravel/actions/workflows/ci.yml/badge.svg)](https://github.com/fixwire/fixwire-laravel/actions/workflows/ci.yml)
[![License: MIT](https://img.shields.io/badge/license-MIT-blue.svg)](https://github.com/fixwire/fixwire-laravel/blob/main/LICENSE)

<br/>

</div>

# Fixwire SDK for Laravel

Welcome to the official Laravel SDK for **[Fixwire](https://fixwire.io)**.
It captures the exceptions Laravel reports, each request as a trace named
after its route, the signed-in user, queue jobs, scheduled tasks as cron
monitors, and release health.

## 📦 Getting started

### Prerequisites

- A Fixwire account and project: sign up at [fixwire.io](https://fixwire.io).
- Laravel 11, 12 or 13 on PHP 8.2 or later. The package builds on the
  [PHP SDK](https://github.com/fixwire/fixwire-php), which comes with it.

### Installation

```sh
composer require fixwire/laravel
```

Package discovery registers the service provider; there is nothing to add
to `bootstrap/app.php`.

### Basic configuration

```sh
# .env
FIXWIRE_DSN=https://fw_pk_live_…@ingest.eu.fixwire.io
FIXWIRE_RELEASE=shop@1.4.0
FIXWIRE_ENVIRONMENT=production   # else the app's environment (APP_ENV)
FIXWIRE_TRACES_SAMPLE_RATE=0.2   # keep 20% of new traces
```

The DSN is your project's publishable key and its ingest host:
`https://<publishable key>@<host>`. Without `FIXWIRE_DSN` (local
development, your test suite) Fixwire does nothing.

That's all you need. To change more, publish the configuration:

```sh
php artisan vendor:publish --tag=fixwire-config   # config/fixwire.php
```

```php
// config/fixwire.php (an excerpt)
return [
    'dsn' => env('FIXWIRE_DSN'),
    'release' => env('FIXWIRE_RELEASE'),
    'traces_sample_rate' => (float) env('FIXWIRE_TRACES_SAMPLE_RATE', 0.0),
    // 'send_default_pii' => true, // send the user's email, IP address and identifying headers
    // 'redact' => false,          // turn off on-device masking of secrets and personal data
];
```

A malformed DSN or an option that doesn't exist never stops the app from
booting: it is said on PHP's error log and Fixwire stays off.

### Quick usage example

Exceptions Laravel reports are sent by themselves. Anywhere in your app:

```php
// A message: an issue with its text, its level and the breadcrumbs before it.
Fixwire\captureMessage('Hello Fixwire!');

try {
    $payments->charge($order);
} catch (PaymentException $e) {
    // An error with the request, the route, the user and the breadcrumbs before it.
    report($e); // or Fixwire\captureException($e)
}
```

An exception you capture yourself and also `report()` is sent once.

## ✨ Why Fixwire

- **Secrets and personal data are masked on the device**, with the same
  rules as the Fixwire server, before anything leaves the app. SQL
  breadcrumbs leave out their bindings.
- **A crash loop costs a few events and a count**, not your quota: the
  error budget sends an issue's first 10 errors, then one a minute with the
  number held back.
- **It never gets in your app's way.** Nothing it does throws into your
  code, a broken configuration leaves it off rather than the app down,
  memory and the time a flush takes are bounded, and every string, value,
  stack and request has a strict size limit.
- **OpenTelemetry-native.** It speaks the Fixwire protocol (OpenTelemetry's
  OTLP/HTTP plus a few small JSON endpoints).
- **Trace headers only where you allow**: outgoing requests carry them only
  to `trace_propagation_targets`.
- **Your data is kept in Europe.**
- **Made for Laravel**: under PHP-FPM data is sent after the response has
  gone out, and under Octane each request keeps its own scope, user and
  breadcrumbs.

## 🧩 Integrations

| Integration | What it does | How to use |
| --- | --- | --- |
| Exceptions | What Laravel reports goes to Fixwire as a crash, with the request, the route (`GET /orders/{id}`), the user and the breadcrumbs. What Laravel doesn't report (404s, validation errors, unauthenticated requests, your `dontReport` list) isn't sent either | Automatic |
| Requests | Each request gets its own scope and, with tracing on, is a server span named after its route that continues the caller's trace, with its queries and HTTP calls under it | Automatic; `FIXWIRE_TRACES_SAMPLE_RATE` |
| The signed-in user | From the default guard once your authentication ran (session, token, Sanctum, `Auth::viaRequest`), without an extra lookup. Its id only; the email too with `send_default_pii` | Automatic |
| Queues | A job runs in its own scope (the `queue` tag, a `job` context) as a span that continues the trace of the code that dispatched it: the trace travels in the job's payload. A job that throws is a crash, with the job. A worker sends after each job | Automatic |
| HTTP client | The `Http` facade's requests are client spans and breadcrumbs, with trace headers sent only to `trace_propagation_targets` | Automatic |
| Scheduler | Each run of a task sends check-ins to a monitor created from the task's schedule | [`->fixwireMonitor()`](https://github.com/fixwire/fixwire-laravel#scheduled-tasks) |
| Octane | One worker serves request after request; each still has its own scope, user and breadcrumbs, and is sent when it ends | Automatic |
| Logs (Monolog) | Log records become breadcrumbs; with a channel, error records become events too | [A logging channel](https://github.com/fixwire/fixwire-laravel#logs) |
| Breadcrumbs | Log records, SQL queries (without their values), queue jobs, Artisan commands and HTTP calls lead up to each error | `breadcrumbs` in `config/fixwire.php` |
| Release health | Each request is a session, ended well, with an error, or crashed | `FIXWIRE_AUTO_SESSION_TRACKING=true` |

### Scheduled tasks

```php
// routes/console.php
Schedule::command('reports:send')->dailyAt('03:00')->timezone('Europe/Berlin')
    ->fixwireMonitor('nightly-report', checkInMargin: 10);
```

Each run sends a check-in when it starts and one when it ends, ok or
failed. The first creates the monitor from the task's own schedule and
timezone, so Fixwire also notices a night the task doesn't run. Without a
name, the monitor is named after the task's description or command.

### Logs

Log records become breadcrumbs (from INFO). To send error records without
an exception as events too, add a channel and put it in your stack:

```php
// config/logging.php
'fixwire' => ['driver' => 'monolog', 'handler' => Fixwire\Monolog\Handler::class],
```

### When data is sent

What was captured is sent at the end of the request, in the middleware's
`terminate`: under PHP-FPM, after the response has gone out. Under Octane
each request is sent when it ends; the example's `OctaneTest` drives
Octane's own worker loop to check that requests don't leak into each
other.

### The rest of the PHP SDK

Everything in the [PHP SDK](https://github.com/fixwire/fixwire-php) works
as it does there: `Fixwire\captureException()`, `Fixwire\withScope()`,
`Fixwire\setTag()`, `Fixwire\trace()`, `Fixwire\captureFeedback()`, …

<a name="configuration"></a>

## ⚙️ Configuration

`config/fixwire.php` holds the options. Any option of the
[PHP SDK](https://github.com/fixwire/fixwire-php#configuration) can go
there too, in its snake_case name (`before_send`, `error_budget`,
`sensitive_keys`, `max_value_length`, `max_stack_frames`, …).

| Option | Default | What it does |
| --- | --- | --- |
| `dsn` | `FIXWIRE_DSN` | Where to send; nothing is sent without one |
| `release` | `FIXWIRE_RELEASE` | The app's version, such as `shop@1.4.0`; release health needs one |
| `environment` | `FIXWIRE_ENVIRONMENT`, else the app's environment | Where the app runs |
| `service_name` | `OTEL_SERVICE_NAME`, else `api` of `api@1.4.0`, else the slug of `app.name` | The service's name |
| `traces_sample_rate` | `FIXWIRE_TRACES_SAMPLE_RATE`, else 0 | Share of new traces kept; continued traces follow the caller |
| `trace_propagation_targets` | none | Where outgoing requests carry trace headers (see below) |
| `auto_session_tracking` | `FIXWIRE_AUTO_SESSION_TRACKING`, else off | A session per request, for crash-free sessions and users per release (one more request to Fixwire per request) |
| `send_default_pii` | off | Send the user's email and IP address and identifying request headers |
| `breadcrumbs.logs`, `.queries`, `.queue`, `.commands` | on | What leaves breadcrumbs: log records from INFO, SQL without its bindings, jobs processed, Artisan commands |
| `tracing.queries`, `.queue`, `.http_client` | on | What becomes spans of a sampled trace |

Files in stack traces are named relative to the app's base path.

### Trace propagation targets

A target with `://` is a URL prefix (`https://api.example.com/v2`); any
other is a host, with a port if it has one, and matches that host and its
subdomains (`example.com` matches `api.example.com`, not `badexample.com`).
URLs are compared without their user info, query and fragment.

### Changing or dropping events

`before_send` takes the event and returns it (changed or not), or `null`
to drop it. A static method keeps `php artisan config:cache` working:

```php
// config/fixwire.php
'before_send' => [App\Support\FixwireFilter::class, 'beforeSend'],

// app/Support/FixwireFilter.php
final class FixwireFilter
{
    public static function beforeSend(Fixwire\Event $event): ?Fixwire\Event
    {
        return str_contains((string) $event->transaction, '/health') ? null : $event;
    }
}
```

### Sampling and redaction

`traces_sample_rate` keeps a share of new traces; a trace continued from a
caller follows the caller's decision. Secrets and personal data are masked
on the device with the server's rules; `sensitive_keys` replaces the list
of key fragments whose values are filtered whole, and `'redact' => false`
turns masking off.

## 🧪 Examples

- [example](https://github.com/fixwire/fixwire-laravel/tree/main/example):
  a Laravel app (an API, a queued job, a scheduled report) whose tests run
  it with `php -S`, `php artisan` and Octane's worker loop against a fake
  ingest.

## 📚 Documentation

The full guide lives in this README and the examples.

- [Configuration](https://github.com/fixwire/fixwire-laravel#configuration)
- [Examples](https://github.com/fixwire/fixwire-laravel/tree/main/example)
- [Changelog](https://github.com/fixwire/fixwire-laravel/blob/main/CHANGELOG.md)
- [Security policy](https://github.com/fixwire/fixwire-laravel/blob/main/SECURITY.md)
- [Contributing guide](https://github.com/fixwire/fixwire-laravel/blob/main/CONTRIBUTING.md)
- [The PHP SDK](https://github.com/fixwire/fixwire-php)

## 🚧 Coming from another error tracker?

The API follows the shape most error-tracking SDKs share: `init`, capture
an exception or a message, the user, tags, breadcrumbs and spans. Moving
over is mostly a change of package and DSN: remove the old package, then
`composer require fixwire/laravel` and set `FIXWIRE_DSN`. There is no
`init` call to write: the service provider does it from
`config/fixwire.php`, and `report()` goes on working as it did.

## 🙌 Want to contribute?

We'd love your help, whether it's a bug report, a fix or a new
integration. Read the
[contributing guide](https://github.com/fixwire/fixwire-laravel/blob/main/CONTRIBUTING.md),
browse the [open issues](https://github.com/fixwire/fixwire-laravel/issues),
or pick one of the
[good first issues](https://github.com/fixwire/fixwire-laravel/issues?q=is%3Aopen+label%3A%22good+first+issue%22).

```sh
composer install
composer check-format && composer analyse && composer test
(cd example && composer install && vendor/bin/phpunit)
```

## 🛟 Need help?

- Questions: join us on [Discord](https://fixwire.io/discord) or
  [Slack](https://fixwire.io/slack).
- Bugs: open a [GitHub issue](https://github.com/fixwire/fixwire-laravel/issues).
- Found a security issue? Please don't open an issue; follow the
  [security policy](https://github.com/fixwire/fixwire-laravel/blob/main/SECURITY.md).

## 🔗 Resources

- [Website](https://fixwire.io)
- [Pricing](https://fixwire.io/pricing)
- [Discord](https://fixwire.io/discord)
- [Slack](https://fixwire.io/slack)
- [X](https://fixwire.io/x)
- [Changelog](https://github.com/fixwire/fixwire-laravel/blob/main/CHANGELOG.md)
- [Examples](https://github.com/fixwire/fixwire-laravel/tree/main/example)
- [Security policy](https://github.com/fixwire/fixwire-laravel/blob/main/SECURITY.md)

## 📃 License

The SDK is open source under the MIT license; see
[LICENSE](https://github.com/fixwire/fixwire-laravel/blob/main/LICENSE).

## 😘 Contributors

Thanks to everyone who helps make Fixwire better!

<a href="https://github.com/fixwire/fixwire-laravel/graphs/contributors"><img src="https://contrib.rocks/image?repo=fixwire/fixwire-laravel" alt="Contributors" /></a>
