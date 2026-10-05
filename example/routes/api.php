<?php

declare(strict_types=1);

use App\Exceptions\PaymentDeclined;
use App\Jobs\ReserveStock;
use Fixwire\Scope;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;

Route::get('/orders/{id}', static function (string $id) {
    // A query: a breadcrumb, and a span under the request's.
    $order = DB::selectOne("select ? as id, 'paid' as status", [$id]);

    return response()->json($order);
});

Route::post('/orders', static function (Request $request) {
    $order = $request->validate(['sku' => 'required|string', 'card' => 'required|string']); // a 422 is not reported
    Log::info('order received', ['sku' => $order['sku']]);
    ReserveStock::dispatch($order['sku']);
    $id = 'ord_' . bin2hex(random_bytes(4));

    if ($order['card'] === '4000000000000002') { // the test card that is always declined
        // Handled: the customer gets an answer, Fixwire gets the error with the order.
        Fixwire\withScope(static function (Scope $scope) use ($id, $order): void {
            $scope->setContext('order', ['id' => $id, 'sku' => $order['sku']]);
            Fixwire\captureException(new RuntimeException("charging order {$id}", 0, new PaymentDeclined('card_declined')));
        });

        return response()->json(['error' => 'payment declined'], 402);
    }

    return response()->json(['id' => $id], 201);
})->middleware('auth:api');

Route::get('/admin/report', static function () {
    $cents = []; // today's orders: none yet
    // A bug: with no orders this divides by zero. Laravel answers 500 and reports it; so does Fixwire.
    return ['average_cents' => intdiv(array_sum($cents), count($cents))];
});
