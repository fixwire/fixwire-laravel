<?php

declare(strict_types=1);

namespace Fixwire\Laravel\Tests;

use Fixwire\Client;
use Fixwire\Hub;
use Fixwire\Laravel\FixwireServiceProvider;
use Illuminate\Auth\GenericUser;
use Illuminate\Bus\Queueable;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Orchestra\Testbench\TestCase;

final class ReserveStock implements ShouldQueue
{
    use Dispatchable;
    use Queueable;

    public function handle(): void
    {
        Log::info('stock reserved');
    }
}

final class SendInvoice implements ShouldQueue
{
    use Dispatchable;
    use Queueable;

    public function handle(): void
    {
        throw new \RuntimeException('the mail server refused it');
    }
}

final class LaravelTest extends TestCase
{
    private FakeIngest $ingest;

    protected function setUp(): void
    {
        Hub::setCurrent(new Hub()); // a fresh scope for each app
        Client::resetRateLimits();
        $this->ingest = new FakeIngest();
        parent::setUp();
    }

    protected function getPackageProviders($app): array
    {
        return [FixwireServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('app.name', 'Shop API');
        $app['config']->set('fixwire.dsn', 'http://publickey@ingest.test');
        $app['config']->set('fixwire.release', 'shop@1.0.0');
        $app['config']->set('fixwire.traces_sample_rate', 1.0);
        $app['config']->set('fixwire.auto_session_tracking', true);
        $app['config']->set('fixwire.trace_propagation_targets', ['inventory.test']);
        $app['config']->set('fixwire.transport', $this->ingest);
        $app['config']->set('fixwire.project_root', \dirname(__DIR__));
        // An API guard that knows the user from a header (a token, in a real app).
        $app['config']->set('auth.guards.api', ['driver' => 'header']);
        $app['auth']->viaRequest('header', static fn($request) => $request->header('X-User-Id') === null ? null : new GenericUser(['id' => $request->header('X-User-Id')]));
    }

    /** @param Router $router */
    protected function defineRoutes($router): void
    {
        $router->get('/orders/{id}', static function (string $id) {
            DB::select('select ? as id', [$id]);
            Log::info('order loaded', ['id' => $id]);

            throw new \RuntimeException("order {$id} has no lines");
        });
        $router->get('/missing', static fn() => abort(404));
        $router->get('/api/refunds', static fn() => throw new \LogicException('refunds are off'))->middleware('auth:api');
        $router->post('/signup', static fn() => throw ValidationException::withMessages(['email' => 'taken']));
        $router->get('/handled', static function () {
            try {
                throw new \DomainException('coupon expired');
            } catch (\DomainException $e) {
                \Fixwire\captureException($e);
                report($e); // once, not twice
            }

            return 'ok';
        });
        $router->get('/checkout', static function () {
            ReserveStock::dispatch();
            $stock = Http::get('https://inventory.test/stock/sku_1')->json('count');
            Http::get('https://rates.example.com/eur');

            return ['stock' => $stock];
        });
    }

    public function testReportsTheExceptionsLaravelReports(): void
    {
        $this->actingAs(new GenericUser(['id' => 42, 'email' => 'ada@example.com']));
        $this->get('/orders/7', ['traceparent' => '00-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-01'])->assertStatus(500);

        $events = $this->ingest->events();
        self::assertCount(1, $events);
        $e = $events[0];
        self::assertSame(['RuntimeException', 'order 7 has no lines', false], [$e['exception.type'], $e['exception.message'], $e['fixwire.handled']]);
        self::assertSame('laravel', $e['fixwire.exceptions'][0]['mechanism']['type']);
        self::assertSame('GET /orders/{id}', $e['fixwire.transaction']);
        self::assertSame('/orders/{id}', $e['http.route']);
        self::assertSame('42', $e['user.id']);
        self::assertArrayNotHasKey('user.email', $e, 'without send_default_pii');
        self::assertSame(['db.query', 'log'], array_slice(array_column($e['fixwire.breadcrumbs'], 'category'), -2), 'the query, then the log line');
        self::assertSame('select ? as id', $e['fixwire.breadcrumbs'][array_key_last($e['fixwire.breadcrumbs']) - 1]['message']);
        $frames = $e['fixwire.exceptions'][0]['frames'];
        self::assertSame('tests/LaravelTest.php', $frames[array_key_last($frames)]['file'], 'relative to the app');

        $request = $this->ingest->span('GET /orders/{id}');
        self::assertNotNull($request);
        self::assertSame('4bf92f3577b34da6a3ce929d0e0e4736', $request['traceId']);
        self::assertSame('00f067aa0ba902b7', $request['parentSpanId']);
        self::assertSame(500, $request['attributes']['http.response.status_code']);
        self::assertSame($request['spanId'], $e['spanId']);
        $query = $this->ingest->span('select ? as id');
        self::assertNotNull($query);
        self::assertSame($request['spanId'], $query['parentSpanId']);
        self::assertSame('sqlite', $query['attributes']['db.system.name']);

        $resource = $this->ingest->received[0]['body']['resourceLogs'][0]['resource']['attributes'];
        self::assertContains(['key' => 'service.name', 'value' => ['stringValue' => 'shop']], $resource, 'of the release');
        self::assertContains(['key' => 'deployment.environment.name', 'value' => ['stringValue' => 'testing']], $resource);

        $sessions = $this->ingest->bodies('/v1/sessions');
        self::assertSame(1, $sessions[0]['aggregates'][0]['crashed'] ?? 0);
        self::assertNotEmpty($sessions[0]['aggregates'][0]['did'] ?? null, 'the signed-in user');
    }

    public function testKnowsTheUserOfAnApiGuard(): void
    {
        $this->getJson('/api/refunds', ['X-User-Id' => 'user-5'])->assertStatus(500);
        $this->app->make('auth')->forgetGuards(); // as each request of a real app starts afresh
        $this->getJson('/api/refunds')->assertUnauthorized(); // not reported

        $events = $this->ingest->events();
        self::assertCount(1, $events);
        self::assertSame(['refunds are off', 'user-5'], [$events[0]['exception.message'], $events[0]['user.id']]);
    }

    public function testLeavesOutWhatLaravelDoesNotReport(): void
    {
        $this->get('/missing')->assertNotFound();
        $this->get('/no-such-route')->assertNotFound();
        $this->post('/signup')->assertStatus(302);
        self::assertSame([], $this->ingest->events());
        self::assertSame(404, $this->ingest->span('GET /missing')['attributes']['http.response.status_code'] ?? null);
    }

    public function testSendsAnExceptionOnce(): void
    {
        $this->get('/handled')->assertOk();
        $events = $this->ingest->events();
        self::assertCount(1, $events);
        self::assertTrue($events[0]['fixwire.handled'] ?? true, 'captured by the app, so handled');
        self::assertSame('GET /handled', $events[0]['fixwire.transaction']);
    }

    public function testTracesJobsAndOutgoingRequests(): void
    {
        Http::fake(['inventory.test/*' => Http::response(['count' => 3]), 'rates.example.com/*' => Http::response(['rate' => 1.1])]);
        $this->get('/checkout')->assertOk()->assertJson(['stock' => 3]);

        $request = $this->ingest->span('GET /checkout');
        $job = $this->ingest->span(ReserveStock::class);
        $call = $this->ingest->span('GET https://inventory.test/stock/sku_1');
        self::assertNotNull($request);
        self::assertNotNull($job);
        self::assertNotNull($call);
        self::assertSame([$request['traceId'], $request['spanId']], [$job['traceId'], $job['parentSpanId']], 'the job continues the request');
        self::assertSame('queue.process', $job['attributes']['fixwire.op']);
        self::assertSame($request['spanId'], $call['parentSpanId']);
        self::assertSame(200, $call['attributes']['http.response.status_code']);
        Http::assertSent(static fn($r) => str_contains($r->url(), 'inventory.test') && preg_match('/^00-' . $request['traceId'] . '-' . $call['spanId'] . '-01$/', $r->header('traceparent')[0] ?? '') === 1);
        Http::assertSent(static fn($r) => str_contains($r->url(), 'rates.example.com') && !$r->hasHeader('traceparent'));
    }

    public function testReportsFailedJobsWithTheJob(): void
    {
        try {
            Bus::dispatchSync(new SendInvoice());
            self::fail('swallowed');
        } catch (\RuntimeException) {
        }
        Hub::current()->flush();

        $events = $this->ingest->events();
        self::assertCount(1, $events);
        $e = $events[0];
        self::assertSame('the mail server refused it', $e['exception.message']);
        self::assertSame('laravel.queue', $e['fixwire.exceptions'][0]['mechanism']['type']);
        self::assertSame(SendInvoice::class, $e['fixwire.transaction']);
        self::assertSame(['queue' => 'sync'], $e['fixwire.tags']);
        self::assertSame(SendInvoice::class, $e['fixwire.contexts']['job']['name']);
        self::assertSame(2, $this->ingest->span(SendInvoice::class)['status']['code'] ?? null);
        self::assertNull(Hub::current()->getScope()->getTransaction(), "the job's scope ended");
    }

    public function testMonitorsScheduledTasks(): void
    {
        $schedule = $this->app->make(Schedule::class);
        $ok = $schedule->call(static fn() => null)->dailyAt('03:00')->timezone('Europe/Berlin')->fixwireMonitor('nightly-report', checkInMargin: 10);
        $ok->run($this->app);
        $failing = $schedule->call(static fn() => throw new \RuntimeException('no data'))->hourly()->fixwireMonitor('hourly-sync');
        try {
            $failing->run($this->app);
        } catch (\RuntimeException) {
        }

        $nightly = $this->ingest->bodies('/v1/check-ins/nightly-report');
        self::assertSame(['in_progress', 'ok'], array_column($nightly, 'status'));
        self::assertSame(['schedule' => ['type' => 'crontab', 'value' => '0 3 * * *'], 'checkin_margin' => 10, 'timezone' => 'Europe/Berlin'], $nightly[0]['monitor_config']);
        self::assertSame($nightly[0]['check_in_id'], $nightly[1]['check_in_id']);
        self::assertSame(['in_progress', 'error'], array_column($this->ingest->bodies('/v1/check-ins/hourly-sync'), 'status'));
    }
}
