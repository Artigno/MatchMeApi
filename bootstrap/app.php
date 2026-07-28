<?php

use App\Http\Middleware\EnsureAppKey;
use App\Http\Middleware\ForceJsonResponse;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Routing\Middleware\ThrottleRequests;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'app.key' => EnsureAppKey::class,
        ]);

        // Treat every API request as JSON so validation failures return 422 JSON
        // even when the client omits `Accept: application/json` (e.g. raw multipart).
        $middleware->prependToGroup('api', ForceJsonResponse::class);

        // Behind API Gateway / Lambda (Bref) the gateway is the only ingress and
        // sets X-Forwarded-For. Trust it so $request->ip() is the real client —
        // otherwise rate limiters (e.g. throttle on /classify) key on the proxy
        // IP and collapse to a single global bucket.
        $middleware->trustProxies(at: '*');

        // EnsureAppKey isn't in Laravel's default middlewarePriority list, so
        // ThrottleRequests (which is) runs first regardless of the route's
        // declared ['app.key', 'throttle:classify'] order — a keyless flood
        // would burn the shared /classify rate-limit budget. Force the gate
        // ahead of the throttle so a bad key never touches the limiter.
        $middleware->prependToPriorityList(
            before: ThrottleRequests::class,
            prepend: EnsureAppKey::class,
        );
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
