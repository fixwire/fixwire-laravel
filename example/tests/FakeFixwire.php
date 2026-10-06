<?php

declare(strict_types=1);

namespace Tests;

/**
 * A fake Fixwire (and services) behind PHP's built-in server, and reading what it got. The using
 * test keeps the servers and files in $servers and $files, and the ingest's log in $ingest.
 */
trait FakeFixwire
{
    /** @var list<resource> */
    private array $servers = [];

    /** @var list<string> */
    private array $files = [];

    private string $ingest = '';

    private function stopServers(): void
    {
        foreach ($this->servers as $server) {
            proc_terminate($server);
            proc_close($server);
        }
        foreach ($this->files as $file) {
            @unlink($file);
        }
    }

    /**
     * @param list<array<string, mixed>>           $spans
     * @param \Closure(array<string, mixed>): bool $match
     *
     * @return array<string, mixed>
     */
    private function one(array $spans, \Closure $match): array
    {
        $found = array_values(array_filter($spans, $match));
        self::assertCount(1, $found, 'spans: ' . implode(', ', array_column($spans, 'name')));

        return $found[0];
    }

    /**
     * @param list<array<string, mixed>> $requests
     *
     * @return list<array<string, mixed>>
     */
    private function allEvents(array $requests): array
    {
        $out = [];
        foreach ($requests as $r) {
            foreach ($r['body']['resourceLogs'] ?? [] as $rl) {
                foreach ($rl['scopeLogs'] as $sl) {
                    foreach ($sl['logRecords'] as $rec) {
                        $out[] = self::kv($rec['attributes']);
                    }
                }
            }
        }

        return $out;
    }

    /**
     * @param list<array<string, mixed>> $requests
     *
     * @return array<string, array<string, mixed>> by exception type
     */
    private function events(array $requests): array
    {
        $out = [];
        foreach ($requests as $r) {
            foreach ($r['body']['resourceLogs'] ?? [] as $rl) {
                foreach ($rl['scopeLogs'] as $sl) {
                    foreach ($sl['logRecords'] as $rec) {
                        $a = self::kv($rec['attributes']) + ['traceId' => $rec['traceId'] ?? null];
                        $out[$a['exception.type'] ?? $rec['eventName']] = $a;
                    }
                }
            }
        }

        return $out;
    }

    /**
     * @param list<array<string, mixed>> $requests
     *
     * @return list<array<string, mixed>>
     */
    private function spans(array $requests): array
    {
        $out = [];
        foreach ($requests as $r) {
            foreach ($r['body']['resourceSpans'] ?? [] as $rs) {
                foreach ($rs['scopeSpans'] as $ss) {
                    foreach ($ss['spans'] as $s) {
                        $out[] = $s;
                    }
                }
            }
        }

        return $out;
    }

    /**
     * @param list<array<string, mixed>> $requests
     *
     * @return array{exited: int, errored: int, crashed: int}
     */
    private function sessions(array $requests): array
    {
        $sum = ['exited' => 0, 'errored' => 0, 'crashed' => 0];
        foreach ($requests as $r) {
            foreach ($r['path'] === '/v1/sessions' ? $r['body']['aggregates'] : [] as $agg) {
                foreach ($sum as $k => $n) {
                    $sum[$k] = $n + ($agg[$k] ?? 0);
                }
            }
        }

        return $sum;
    }

    /**
     * @param list<array{key: string, value: array<string, mixed>}> $list
     *
     * @return array<string, mixed>
     */
    private static function kv(array $list): array
    {
        $out = [];
        foreach ($list as $kv) {
            $out[$kv['key']] = self::value($kv['value']);
        }

        return $out;
    }

    /** @param array<string, mixed> $v */
    private static function value(array $v): mixed
    {
        return match (true) {
            \array_key_exists('stringValue', $v) => $v['stringValue'],
            \array_key_exists('boolValue', $v) => $v['boolValue'],
            \array_key_exists('intValue', $v) => (int) $v['intValue'],
            \array_key_exists('doubleValue', $v) => (float) $v['doubleValue'],
            \array_key_exists('arrayValue', $v) => array_map(self::value(...), $v['arrayValue']['values'] ?? []),
            \array_key_exists('kvlistValue', $v) => self::kv($v['kvlistValue']['values'] ?? []),
            default => null,
        };
    }

    /**
     * Fails with what the app answered and what it reported, when the status isn't the one expected.
     *
     * @param array{int, string} $answer
     */
    private function assertAnswered(int $status, array $answer): void
    {
        if ($answer[0] === $status) {
            $this->addToAssertionCount(1);

            return;
        }
        usleep(500_000); // the app sends what it captured once it has answered
        $reported = [];
        foreach ($this->allEvents($this->received(0)) as $event) {
            $reported[] = ($event['exception.type'] ?? 'message') . ': ' . ($event['exception.message'] ?? '');
        }
        self::fail("answered {$answer[0]}, not {$status}: " . substr($answer[1], 0, 500) . "\nreported: " . implode("\n          ", $reported));
    }

    /**
     * @param list<string> $headers
     *
     * @return array{int, string}
     */
    private function http(string $method, string $url, ?string $body, array $headers): array
    {
        $context = stream_context_create(['http' => [
            'method' => $method,
            'header' => implode("\r\n", $headers),
            'content' => $body ?? '',
            'ignore_errors' => true,
            'timeout' => 15,
        ]]);
        $stream = fopen($url, 'rb', false, $context);
        self::assertNotFalse($stream, "{$method} {$url}");
        $status = 0;
        foreach (stream_get_meta_data($stream)['wrapper_data'] as $line) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', (string) $line, $m) === 1) {
                $status = (int) $m[1];
            }
        }
        $answer = (string) stream_get_contents($stream);
        fclose($stream);

        return [$status, $answer];
    }

    /**
     * Starts PHP's built-in server on a free port (%d in the arguments).
     *
     * @param list<string>          $args
     * @param array<string, string> $env
     */
    private function serve(array $args, array $env): int
    {
        $probe = stream_socket_server('tcp://127.0.0.1:0');
        self::assertNotFalse($probe);
        $port = (int) substr((string) strrchr((string) stream_socket_get_name($probe, false), ':'), 1);
        fclose($probe);
        $null = \PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';
        $server = proc_open(
            [\PHP_BINARY, ...array_map(static fn(string $a): string => \sprintf($a, $port), $args)],
            [0 => ['file', $null, 'r'], 1 => ['file', $null, 'w'], 2 => ['file', $null, 'w']],
            $pipes,
            \dirname(__DIR__),
            $env + getenv(),
        );
        self::assertIsResource($server);
        $this->servers[] = $server;
        for ($i = 0; $i < 100; $i++) {
            $up = @fsockopen('127.0.0.1', $port, $errno, $error, 0.1);
            if ($up !== false) {
                fclose($up);

                return $port;
            }
            usleep(50_000);
        }
        self::fail("php -S did not start on {$port}");
    }

    private function tempFile(): string
    {
        $file = (string) tempnam(sys_get_temp_dir(), 'fixwire-laravel-example');
        $this->files[] = $file;

        return $file;
    }

    /**
     * What the fake Fixwire got, once it got at least $count requests (or 15 seconds passed).
     *
     * @return list<array<string, mixed>>
     */
    private function received(int $count): array
    {
        $read = fn(): array => array_map(
            static fn(string $l): array => json_decode($l, true, 512, \JSON_THROW_ON_ERROR),
            array_values(array_filter(explode("\n", (string) file_get_contents($this->ingest)))),
        );
        for ($i = 0; $i < 300 && \count($read()) < $count; $i++) {
            usleep(50_000);
        }
        usleep(300_000); // anything after it, too

        return $read();
    }
}
