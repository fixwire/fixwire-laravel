<?php

declare(strict_types=1);

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/** Holds one item at the inventory service: a job, traced as part of the request that dispatched it. */
final class ReserveStock implements ShouldQueue
{
    use Dispatchable;
    use Queueable;

    public function __construct(public string $sku) {}

    public function handle(): void
    {
        $answer = Http::timeout(5)->post(config('services.inventory') . '/reservations', ['sku' => $this->sku]);
        if ($answer->failed()) {
            throw new \RuntimeException("reserving {$this->sku}: inventory answered {$answer->status()}");
        }
        Log::info('stock reserved', ['sku' => $this->sku]);
    }
}
