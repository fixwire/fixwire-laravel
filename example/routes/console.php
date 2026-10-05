<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

// Builds a report per account; globex has no invoices, which the report can't handle.
Artisan::command('reports:send', function (): int {
    $accounts = ['acme' => [1200, 800], 'globex' => [], 'initech' => [4300]];
    $failed = 0;
    foreach ($accounts as $account => $invoices) {
        try {
            if ($invoices === []) {
                throw new RuntimeException("building the report for {$account}", 0, new LengthException('no invoices'));
            }
            $this->info(sprintf('%s: %.2f EUR', $account, array_sum($invoices) / 100));
        } catch (RuntimeException $e) {
            $failed++;
            Fixwire\withScope(static function (Fixwire\Scope $scope) use ($account, $e): void {
                $scope->setTag('account', $account);
                report($e); // Laravel's report() goes to Fixwire too; then carry on with the next account
            });
        }
    }

    return $failed > 0 ? 1 : 0;
})->purpose('Send the nightly reports');

// Check-ins to the nightly-report monitor around each run: Fixwire creates the monitor from this
// schedule, and notices a night the task does not run, or fails.
Schedule::command('reports:send')->dailyAt('03:00')->timezone('Europe/Berlin')->fixwireMonitor('nightly-report', checkInMargin: 10);
