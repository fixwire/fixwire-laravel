# Changelog

All notable changes to Fixwire for Laravel are listed here. Versions follow [Semantic
Versioning](https://semver.org); before 1.0, a minor version may change the
API.

## [0.1.0] - 2026-10-06

First release.

- Laravel 11, 12 and 13, found by package discovery: the SDK from `config/fixwire.php`.
- Exceptions Laravel reports (not 404s, validation or unauthenticated requests), each request as a route-named span with the signed-in user and its session.
- Queue jobs continuing the trace that dispatched them, SQL queries, log records and Artisan commands as breadcrumbs, the `Http` client traced.
- `->fixwireMonitor()` on scheduled tasks: check-ins to a monitor created from the task's schedule.
- Tested on Octane: requests of a long-lived worker don't leak into each other.
