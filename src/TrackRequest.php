<?php

declare(strict_types=1);

namespace Fixwire\Laravel;

use Fixwire\Hub;
use Fixwire\ServerRequest;
use Illuminate\Contracts\Auth\Factory as Auth;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Symfony\Component\HttpFoundation\Response;

/**
 * The first global middleware (added by the service provider): the request's own scope with its
 * signed-in user, a server span named after its route (GET /orders/{id}) that continues the
 * caller's trace, and its session. What was captured is sent once the response has gone out.
 */
final class TrackRequest
{
    private bool $pushed = false;

    public function __construct(private Auth $auth) {}

    public function handle(Request $request, \Closure $next): mixed
    {
        $hub = Hub::current();
        $client = $hub->getClient();
        if ($client === null || !$client->isEnabled()) {
            return $next($request);
        }
        $scope = $hub->pushScope();
        $this->pushed = true;
        // Whoever the app's authentication finds, with any guard that remembers its user.
        $auth = $this->auth;
        $pii = $client->options()->sendDefaultPii;
        $scope->userProvider = static fn() => Users::signedIn($auth, $pii);
        $headers = [];
        foreach ($request->headers->all() as $name => $values) {
            $headers[(string) $name] = implode(', ', array_filter((array) $values, 'is_string'));
        }
        $tracked = ServerRequest::start($hub, $request->getMethod(), $request->fullUrl(), $headers, $request->ip());
        // Known once the router matched it: events captured in a controller name it already.
        $tracked->request->routeProvider = static fn(): ?string => self::route($request);

        try {
            $response = $next($request);
        } catch (\Throwable $e) {
            // Laravel turns exceptions into responses; one that gets here anyway ends the request.
            $tracked->setRoute(self::route($request))->end(500);

            throw $e;
        }
        $tracked->setRoute(self::route($request))->end($response instanceof Response ? $response->getStatusCode() : 200);

        return $response;
    }

    /** After the response was sent: the request's scope ends, and what was captured goes. */
    public function terminate(): void
    {
        if (!$this->pushed) {
            return;
        }
        $this->pushed = false;
        $hub = Hub::current();
        $hub->popScope();
        $hub->flush();
    }

    private static function route(Request $request): ?string
    {
        // Not $request->route(): it is null before routing and for a 404, whatever its type says.
        $route = \call_user_func($request->getRouteResolver());

        return $route instanceof Route ? '/' . ltrim($route->uri(), '/') : null;
    }
}
