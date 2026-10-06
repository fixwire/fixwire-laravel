# Changelog

All notable changes to Fixwire for Laravel are listed here. Versions follow [Semantic
Versioning](https://semver.org); before 1.0, a minor version may change the
API.

## [Unreleased]

- A malformed DSN or an unknown option in `config/fixwire.php` no longer stops the app from booting: it is said on PHP's error log and Fixwire stays off.
- `trace_propagation_targets` in `config/fixwire.php` are URL prefixes or hosts with their subdomains (fixwire/fixwire's rules), and the SDK's limits (`max_value_length`, `max_stack_frames`) can be set there.

## [0.1.0] - 2026-10-06

First release.

- Laravel 11, 12 and 13, found by package discovery: the SDK from `config/fixwire.php`.
- Exceptions Laravel reports (not 404s, validation or unauthenticated requests), each request as a route-named span with the signed-in user and its session.
- Queue jobs continuing the trace that dispatched them, SQL queries, log records and Artisan commands as breadcrumbs, the `Http` client traced.
- `->fixwireMonitor()` on scheduled tasks: check-ins to a monitor created from the task's schedule.
- Tested on Octane: requests of a long-lived worker don't leak into each other.
