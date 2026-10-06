<?php

declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\TestCase;

/**
 * Runs the app as it runs for real (`php -S` in front of public/index.php, `php artisan`) against a
 * fake Fixwire and a fake inventory service, and checks what Fixwire receives.
 */
final class ExampleTest extends TestCase
{
    use FakeFixwire;

    private string $inventoryLog;

    /** @var array<string, string> */
    private array $env;

    protected function setUp(): void
    {
        $this->ingest = $this->tempFile();
        $this->inventoryLog = $this->tempFile();
        $ingest = $this->serve(['-S', '127.0.0.1:%d', __DIR__ . '/ingest.php'], ['FAKE_INGEST_LOG' => $this->ingest]);
        $inventory = $this->serve(['-S', '127.0.0.1:%d', __DIR__ . '/inventory.php'], ['INVENTORY_LOG' => $this->inventoryLog]);
        $this->env = [
            'FIXWIRE_DSN' => "http://examplekey@127.0.0.1:{$ingest}",
            'INVENTORY_URL' => "http://127.0.0.1:{$inventory}",
            'APP_ENV' => 'production',
            'LOG_CHANNEL' => 'stderr',
            'DB_CONNECTION' => 'sqlite',
            'DB_DATABASE' => ':memory:',
            'CACHE_STORE' => 'array',
            'SESSION_DRIVER' => 'array',
            'QUEUE_CONNECTION' => 'sync',
        ];
    }

    protected function tearDown(): void
    {
        $this->stopServers();
    }

    public function testTheApi(): void
    {
        $port = $this->serve(['-S', '127.0.0.1:%d', \dirname(__DIR__) . '/public/index.php'], $this->env);
        $base = "http://127.0.0.1:{$port}";
        $json = ['Accept: application/json', 'Content-Type: application/json'];

        $this->assertAnswered(200, $this->http('GET', "{$base}/orders/7", null, $json));
        $this->assertAnswered(401, $this->http('POST', "{$base}/orders", '{"sku":"sku_1","card":"4242424242424242"}', $json));
        $this->assertAnswered(422, $this->http('POST', "{$base}/orders", '{"sku":"sku_1"}', [...$json, 'X-User-Id: user-1']));
        $this->assertAnswered(201, $this->http('POST', "{$base}/orders", '{"sku":"sku_1","card":"4242424242424242"}', [...$json, 'X-User-Id: user-1']));
        $this->assertAnswered(402, $this->http('POST', "{$base}/orders", '{"sku":"sku_2","card":"4000000000000002"}', [...$json, 'X-User-Id: user-2']));
        $this->assertAnswered(500, $this->http('GET', "{$base}/admin/report", null, $json));
        $requests = $this->received(14); // a trace and a session per request, the two errors

        $events = $this->events($requests);
        self::assertSame(['RuntimeException', 'DivisionByZeroError'], array_keys($events), 'not the 401 or the 422');
        $declined = $events['RuntimeException'];
        self::assertSame(['RuntimeException', 'App\Exceptions\PaymentDeclined'], array_column($declined['fixwire.exceptions'], 'type'));
        self::assertSame('POST /orders', $declined['fixwire.transaction']);
        self::assertSame('user-2', $declined['user.id']);
        self::assertSame('sku_2', $declined['fixwire.contexts']['order']['sku']);
        // The log line and the job (what the job did stayed in its own scope).
        self::assertSame(['log', 'queue'], array_column($declined['fixwire.breadcrumbs'], 'category'));
        self::assertStringNotContainsString('4000000000000002', json_encode($requests, \JSON_THROW_ON_ERROR), 'the card stays in the app');

        $crash = $events['DivisionByZeroError'];
        self::assertSame(['laravel', false], [$crash['fixwire.exceptions'][0]['mechanism']['type'], $crash['fixwire.handled']]);
        self::assertSame('GET /admin/report', $crash['fixwire.transaction']);
        $frames = $crash['fixwire.exceptions'][0]['frames'];
        self::assertSame(['routes/api.php', true], [$frames[array_key_last($frames)]['file'], $frames[array_key_last($frames)]['in_app']]);

        $spans = $this->spans($requests);
        $order = $this->one($spans, static fn(array $s): bool => $s['name'] === 'GET /orders/{id}');
        $query = $this->one($spans, static fn(array $s): bool => ($s['parentSpanId'] ?? null) === $order['spanId']);
        self::assertSame("select ? as id, 'paid' as status", $query['name']);
        $checkout = $this->one($spans, static fn(array $s): bool => $s['name'] === 'POST /orders' && $s['traceId'] === $declined['traceId']);
        $job = $this->one($spans, static fn(array $s): bool => $s['name'] === 'App\Jobs\ReserveStock' && $s['traceId'] === $declined['traceId']);
        self::assertSame($checkout['spanId'], $job['parentSpanId'], 'the job continues the request');
        $call = $this->one($spans, static fn(array $s): bool => ($s['parentSpanId'] ?? null) === $job['spanId']);
        self::assertStringStartsWith('POST http://127.0.0.1:', $call['name']);
        $reservations = array_map(static fn(string $l): array => json_decode($l, true), array_values(array_filter(explode("\n", (string) file_get_contents($this->inventoryLog)))));
        self::assertContains('00-' . $declined['traceId'] . '-' . $call['spanId'] . '-01', array_column($reservations, 'traceparent'));

        self::assertSame(['exited' => 4, 'errored' => 1, 'crashed' => 1], $this->sessions($requests));
    }

    public function testTheScheduledReport(): void
    {
        [$code, $out] = $this->artisan(['schedule:test', '--name=reports:send']);
        self::assertSame(0, $code, $out);
        $requests = $this->received(3);

        $checkIns = array_values(array_filter($requests, static fn(array $r): bool => $r['path'] === '/v1/check-ins/nightly-report'));
        self::assertSame(['in_progress', 'error'], array_map(static fn(array $r): string => $r['body']['status'], $checkIns));
        self::assertSame(['schedule' => ['type' => 'crontab', 'value' => '0 3 * * *'], 'checkin_margin' => 10, 'timezone' => 'Europe/Berlin'], $checkIns[0]['body']['monitor_config']);

        $failure = $this->events($requests)['RuntimeException'];
        self::assertSame('building the report for globex', $failure['exception.message']);
        self::assertSame(['account' => 'globex'], $failure['fixwire.tags']);
        self::assertSame(['RuntimeException', 'LengthException'], array_column($failure['fixwire.exceptions'], 'type'));
    }

    /**
     * @param list<string> $args
     *
     * @return array{int, string}
     */
    private function artisan(array $args): array
    {
        $process = proc_open([\PHP_BINARY, \dirname(__DIR__) . '/artisan', ...$args], [1 => ['pipe', 'w'], 2 => ['redirect', 1]], $pipes, \dirname(__DIR__), $this->env + getenv());
        self::assertIsResource($process);
        $out = (string) stream_get_contents($pipes[1]);

        return [proc_close($process), $out];
    }
}
