<?php

declare(strict_types=1);

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

// Nothing here for Fixwire: package discovery registers its service provider.
return Application::configure(basePath: \dirname(__DIR__))
    ->withRouting(api: __DIR__ . '/../routes/api.php', commands: __DIR__ . '/../routes/console.php', apiPrefix: '')
    ->withMiddleware(static function (Middleware $middleware): void {})
    ->withExceptions(static function (Exceptions $exceptions): void {})
    ->create();
