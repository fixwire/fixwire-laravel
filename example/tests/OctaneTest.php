<?php

declare(strict_types=1);

namespace Tests;

use Fixwire\Hub;
use Illuminate\Http\Request;
use Laravel\Octane\ApplicationFactory;
use Laravel\Octane\Testing\Fakes\FakeClient;
use Laravel\Octane\Testing\Fakes\FakeWorker;
use PHPUnit\Framework\TestCase;

/**
 * The app under Laravel Octane: one worker boots it once and serves request after request from it
 * (Octane's own worker loop, driven in this process instead of by Swoole, RoadRunner or
 * FrankenPHP). Nothing of a request may leak into the next, and each is sent when it ends.
 */
final class OctaneTest extends TestCase
{
    use FakeFixwire;

    /** @var array<string, string> */
    private array $env = [];

    private mixed $errorHandler = null;

    private mixed $exceptionHandler = null;

    protected function setUp(): void
    {
        $this->errorHandler = self::currentHandler('error');
        $this->exceptionHandler = self::currentHandler('exception');
        $this->ingest = $this->tempFile();
        $ingest = $this->serve(['-S', '127.0.0.1:%d', __DIR__ . '/ingest.php'], ['FAKE_INGEST_LOG' => $this->ingest]);
        $inventory = $this->serve(['-S', '127.0.0.1:%d', __DIR__ . '/inventory.php'], ['INVENTORY_LOG' => $this->tempFile()]);
        $this->env = [
            'FIXWIRE_DSN' => "http://examplekey@127.0.0.1:{$ingest}",
            'INVENTORY_URL' => "http://127.0.0.1:{$inventory}",
            'APP_ENV' => 'production',
            'APP_KEY' => 'base64:' . base64_encode(random_bytes(32)), // Octane warms the encrypter
            'LOG_CHANNEL' => 'single', // storage/logs
            'DB_CONNECTION' => 'sqlite',
            'DB_DATABASE' => ':memory:',
            'CACHE_STORE' => 'array',
            'SESSION_DRIVER' => 'array',
            'QUEUE_CONNECTION' => 'sync',
        ];
        foreach ($this->env as $name => $value) {
            putenv("{$name}={$value}");
            $_ENV[$name] = $_SERVER[$name] = $value;
        }
        Hub::setCurrent(new Hub());
    }

    protected function tearDown(): void
    {
        foreach (array_keys($this->env) as $name) {
            putenv($name);
            unset($_ENV[$name], $_SERVER[$name]);
        }
        $this->stopServers();
        // The app and the SDK installed handlers in this process: back to PHPUnit's.
        while (self::currentHandler('error') !== $this->errorHandler && restore_error_handler()) {
        }
        while (self::currentHandler('exception') !== $this->exceptionHandler && restore_exception_handler()) {
        }
    }

    public function testRequestsDoNotLeakIntoEachOther(): void
    {
        $json = ['HTTP_ACCEPT' => 'application/json', 'CONTENT_TYPE' => 'application/json'];
        $client = new FakeClient([
            Request::create('/orders', 'POST', server: $json + ['HTTP_X_USER_ID' => 'user-2'], content: '{"sku":"sku_2","card":"4000000000000002"}'),
            Request::create('/admin/report', 'GET', server: $json),
            Request::create('/orders', 'POST', server: $json + ['HTTP_X_USER_ID' => 'user-1'], content: '{"sku":"sku_1","card":"4242424242424242"}'),
            Request::create('/orders/7', 'GET', server: $json),
        ]);
        $worker = new FakeWorker(new ApplicationFactory(\dirname(__DIR__)), $client);
        $worker->boot();
        \Fixwire\addBreadcrumb('worker', 'booted'); // the worker's, before any request
        $worker->run();

        self::assertSame([402, 500, 201, 200], array_map(static fn($r): int => $r->getStatusCode(), $client->responses));
        // Sent as each request ended, not when the worker stops.
        $requests = $this->received(10); // a trace and a session per request, the two errors
        $worker->terminate();

        $events = $this->events($requests);
        self::assertSame(['RuntimeException', 'DivisionByZeroError'], array_keys($events));
        $declined = $events['RuntimeException'];
        self::assertSame(['POST /orders', 'user-2'], [$declined['fixwire.transaction'], $declined['user.id']]);
        self::assertSame(['worker', 'log', 'queue'], array_column($declined['fixwire.breadcrumbs'], 'category'), 'the first request has what came before it');
        $crash = $events['DivisionByZeroError'];
        self::assertSame('GET /admin/report', $crash['fixwire.transaction']);
        self::assertArrayNotHasKey('user.id', $crash, 'not the previous request\'s user');
        self::assertArrayNotHasKey('fixwire.breadcrumbs', $crash, 'nor its breadcrumbs');
        self::assertArrayNotHasKey('fixwire.contexts', $crash);

        $servers = array_values(array_filter($this->spans($requests), static fn(array $s): bool => !isset($s['parentSpanId'])));
        self::assertCount(4, $servers, 'a trace per request');
        self::assertCount(4, array_unique(array_column($servers, 'traceId')));
        self::assertSame(['exited' => 2, 'errored' => 1, 'crashed' => 1], $this->sessions($requests));

        // The worker's own scope is as it was: nothing of the requests stayed in it.
        $scope = Hub::current()->getScope();
        self::assertNull($scope->getUser());
        self::assertNull($scope->getTransaction());
    }

    /** The current error or exception handler (set and restore, to read it). */
    private static function currentHandler(string $kind): mixed
    {
        if ($kind === 'error') {
            $current = set_error_handler(static fn(): bool => false);
            restore_error_handler();
        } else {
            $current = set_exception_handler(static function (): void {});
            restore_exception_handler();
        }

        return $current;
    }
}
