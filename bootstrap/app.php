<?php

use App\Http\Middleware\SetLocale;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        channels: __DIR__.'/../routes/channels.php',
        health: '/up',
    )
    // Channel authorisation for the app: same Sanctum bearer token as the API,
    // reachable at POST /api/broadcasting/auth.
    ->withBroadcasting(
        __DIR__.'/../routes/channels.php',
        ['prefix' => 'api', 'middleware' => ['api', 'auth:sanctum']],
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->api(prepend: [SetLocale::class]);

        // The API has no login page: an unauthenticated call (even without an
        // Accept header) must be a 401 JSON, not a redirect to a missing route.
        $middleware->redirectGuestsTo(fn (Request $request) => $request->is('api/*') ? null : '/');
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        // Gate::authorize throws AuthorizationException, which the framework has
        // already wrapped in AccessDeniedHttpException by the time it gets here.
        $exceptions->render(function (AuthorizationException|AccessDeniedHttpException $e, Request $request) {
            if ($request->is('api/*')) {
                return response()->json(['message' => __('messages.unauthorized')], 403);
            }
        });

        $exceptions->render(function (ModelNotFoundException|NotFoundHttpException $e, Request $request) {
            if ($request->is('api/*')) {
                return response()->json(['message' => __('messages.not_found')], 404);
            }
        });

        // Anything unexpected on the API is a calm, translated 500 for the
        // apps — never an exception message or a trace, even with APP_DEBUG.
        // The detail goes to the log (and to a `debug` key locally only).
        $exceptions->render(function (\Throwable $e, Request $request) {
            if (! $request->is('api/*') || $e instanceof HttpExceptionInterface
                || $e instanceof \Illuminate\Validation\ValidationException
                || $e instanceof \Illuminate\Auth\AuthenticationException) {
                return null;
            }

            report($e);

            return response()->json([
                'message' => __('messages.server_error'),
                ...(config('app.debug') ? ['debug' => $e->getMessage()] : []),
            ], 500);
        });
    })->create();
