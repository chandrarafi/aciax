<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckSecretKey
{
    public function handle(Request $request, Closure $next): Response
    {
        $secretKey = $request->header('SECRET-KEY') ?? $request->header('SECRET_KEY') ?? $request->query('secret_key');

        if (!$secretKey || $secretKey !== config('app.secret_key')) {
            return response()->json([
                'message' => 'Unauthorized',
            ], 401);
        }

        return $next($request);
    }
}
