<?php

use App\Exceptions\InvalidProcessingRunTransition;
use App\Http\Middleware\EnsureAccountIsActive;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Session\Middleware\AuthenticateSession;
use Illuminate\Support\Facades\Route;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function (): void {
            Route::middleware('api')
                ->prefix('internal/api/v1')
                ->group(base_path('routes/internal.php'));
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->web(append: [AuthenticateSession::class, EnsureAccountIsActive::class]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*')
                || $request->is('internal/api/*')
                || $request->expectsJson(),
        );

        $exceptions->render(function (
            InvalidProcessingRunTransition $exception,
            Request $request,
        ) {
            if (! $request->is('internal/api/*')) {
                return null;
            }

            return response()->json([
                'message' => $exception->getMessage(),
            ], 409);
        });
    })->create();
