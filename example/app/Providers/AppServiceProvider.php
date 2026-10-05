<?php

declare(strict_types=1);

namespace App\Providers;

use Illuminate\Auth\GenericUser;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\ServiceProvider;

final class AppServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        // The signed-in user comes from a header here; Fixwire reads it from the guard.
        Auth::viaRequest('header', static function (Request $request): ?GenericUser {
            $id = $request->header('X-User-Id');

            return \is_string($id) && $id !== '' ? new GenericUser(['id' => $id]) : null;
        });
    }
}
