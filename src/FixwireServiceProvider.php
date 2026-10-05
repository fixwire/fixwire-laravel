<?php

declare(strict_types=1);

namespace Fixwire\Laravel;

use Fixwire\Guzzle\Middleware as GuzzleMiddleware;
use Fixwire\Hub;
use Illuminate\Auth\Events\Authenticated;
use Illuminate\Console\Scheduling\Event as ScheduledEvent;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Symfony\Component\ErrorHandler\Error\FatalError;

/**
 * Sets Fixwire up in a Laravel app (found by package discovery): the SDK from config/fixwire.php,
 * exceptions Laravel reports, each request, the signed-in user, queue jobs, breadcrumbs, the Http
 * client and the fixwireMonitor() method of scheduled tasks.
 */
final class FixwireServiceProvider extends ServiceProvider
{
    /** Keys of config/fixwire.php that are this package's, not the SDK's. */
    private const OWN = ['breadcrumbs', 'tracing'];

    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/fixwire.php', 'fixwire');
        $this->app->scoped(TrackRequest::class);

        // Before any provider boots, so that what fails while the app boots is reported (and
        // after every provider registered, so that the configuration is complete).
        $this->app->booting(fn() => $this->init());
    }

    private function init(): void
    {
        /** @var array<string, mixed> $config */
        $config = (array) $this->app->make('config')->get('fixwire', []);
        $options = array_diff_key($config, array_flip(self::OWN));
        $options['environment'] ??= $this->app->environment();
        $options['project_root'] ??= $this->app->basePath();
        $options['track_request'] = false; // TrackRequest does it, with the route
        $release = \is_string($options['release'] ?? null) ? $options['release'] : (string) getenv('FIXWIRE_RELEASE');
        if (($options['service_name'] ?? null) === null && !str_contains($release, '@') && getenv('OTEL_SERVICE_NAME') === false) {
            $options['service_name'] = Str::slug((string) $this->app->make('config')->get('app.name', 'laravel'));
        }
        \Fixwire\init(array_filter($options, static fn(mixed $v): bool => $v !== null));
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([__DIR__ . '/../config/fixwire.php' => $this->app->configPath('fixwire.php')], 'fixwire-config');
        }
        ScheduledEvent::macro('fixwireMonitor', ScheduleMonitor::macro());

        $client = Hub::current()->getClient();
        if ($client === null || !$client->isEnabled()) {
            return;
        }
        $config = $this->app->make('config');
        $this->reportExceptions();
        $kernel = $this->app->bound(HttpKernel::class) ? $this->app->make(HttpKernel::class) : null;
        if ($kernel !== null && method_exists($kernel, 'prependMiddleware')) {
            $kernel->prependMiddleware(TrackRequest::class);
        }

        /** @var Dispatcher $events */
        $events = $this->app->make('events');
        // A session guard says whom it signed in (TrackRequest asks the default guard).
        $events->listen(Authenticated::class, static function (Authenticated $event) use ($client): void {
            Hub::current()->getScope()->setUser(Users::of($event->user, $client->options()->sendDefaultPii));
        });
        Breadcrumbs::register($events, (array) $config->get('fixwire.breadcrumbs', []), (bool) $config->get('fixwire.tracing.queries', true));
        Queue::register($events, (bool) $config->get('fixwire.breadcrumbs.queue', true), (bool) $config->get('fixwire.tracing.queue', true));
        if ((bool) $config->get('fixwire.tracing.http_client', true)) {
            Http::globalMiddleware(GuzzleMiddleware::trace());
        }
    }

    /** Exceptions Laravel's handler reports (not those it doesn't: 404s, validation, …) are crashes. */
    private function reportExceptions(): void
    {
        $hook = static function (object $handler): void {
            if (!method_exists($handler, 'reportable')) {
                return;
            }
            $handler->reportable(static function (\Throwable $e): void {
                if ($e instanceof FatalError) {
                    return; // the SDK's shutdown function sends it, from PHP's own error
                }
                $hub = Hub::current();
                if ($hub->getClient()?->isCaptured($e) !== true) {
                    $hub->captureException($e, 'laravel', false);
                }
            });
        };
        if ($this->app->resolved(ExceptionHandler::class)) {
            $hook($this->app->make(ExceptionHandler::class));
        } else {
            $this->app->afterResolving(ExceptionHandler::class, $hook);
        }
    }
}
