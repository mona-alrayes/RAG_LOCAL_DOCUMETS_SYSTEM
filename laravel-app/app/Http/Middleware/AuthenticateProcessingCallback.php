<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateProcessingCallback
{
    public const HEADER = 'X-Processing-Callback-Secret';

    public function handle(Request $request, Closure $next): Response
    {
        $expectedSecret = config('services.processing_callback.secret');

        if (! is_string($expectedSecret) || trim($expectedSecret) === '') {
            abort(Response::HTTP_SERVICE_UNAVAILABLE);
        }

        $providedSecret = $request->header(self::HEADER);

        if (
            ! is_string($providedSecret)
            || ! hash_equals($expectedSecret, $providedSecret)
        ) {
            abort(Response::HTTP_UNAUTHORIZED);
        }

        return $next($request);
    }
}
