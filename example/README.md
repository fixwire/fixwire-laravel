# Shop (Laravel)

A small Laravel API with Fixwire, installed the way an app would install it:
`fixwire/laravel` in `composer.json`, a DSN in the environment, and a
trimmed `config/fixwire.php`. Nothing in `bootstrap/app.php`: package
discovery registers Fixwire's service provider.

```sh
composer install
FIXWIRE_DSN=https://<key>@<host> DB_CONNECTION=sqlite DB_DATABASE=:memory: CACHE_STORE=array \
  php -S localhost:8080 public/index.php
```

It reserves stock at an inventory service in a queued job (`INVENTORY_URL`,
default `http://localhost:8081`). The API knows its users from an
`X-User-Id` header (a token, in a real app). Then:

```sh
curl -H 'Accept: application/json' localhost:8080/orders/7          # 200, with a query span
curl -H 'Accept: application/json' -H 'X-User-Id: user-2' -H 'Content-Type: application/json' \
  -d '{"sku":"sku_2","card":"4000000000000002"}' localhost:8080/orders # 402: a declined payment
curl -H 'Accept: application/json' localhost:8080/admin/report       # a bug: 500
php artisan schedule:test --name=reports:send                        # the nightly report, now
```

What arrives in Fixwire:

- **The declined payment** as an error of `POST /orders`, captured by the
  route: the chain (`charging order …` caused by `PaymentDeclined`), the
  user `user-2` (from the guard), the order as context, and the breadcrumbs
  (`order received`, the job). A request without a user (401) or with a bad
  body (422) is not reported: Laravel doesn't report those.
- **The bug** in `GET /admin/report` (`DivisionByZeroError`), reported by
  Laravel's exception handler, as a crash.
- **A trace per request**, named after its route, with the SQL query, and
  the `ReserveStock` job under the order: the job continues the request's
  trace, and its call to the inventory service carries the trace on.
- **Release health** for `shop@1.0.0`: each request is a session.
- **The nightly report**: check-ins for the `nightly-report` monitor
  (`in_progress`, then `error`, as one account failed), created from
  the schedule in `routes/console.php` (daily at 03:00, Berlin time), and
  the account's failure, tagged `account: globex`, from `report()`.

How it is wired:

```php
// config/fixwire.php
'release' => env('FIXWIRE_RELEASE', 'shop@1.0.0'),
'traces_sample_rate' => 1.0,
'trace_propagation_targets' => [env('INVENTORY_URL', 'http://localhost:8081')],
'auto_session_tracking' => true,

// routes/console.php
Schedule::command('reports:send')->dailyAt('03:00')->timezone('Europe/Berlin')
    ->fixwireMonitor('nightly-report', checkInMargin: 10);
```
