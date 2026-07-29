<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckSecretKey
{
    public function handle(Request $request, Closure $next): Response
    {
        // Bypass secret key verification for CORS preflight (OPTIONS) requests
        if ($request->isMethod('OPTIONS')) {
            return response('', 200)
                ->header('Access-Control-Allow-Origin', '*')
                ->header('Access-Control-Allow-Methods', 'GET, POST, PUT, DELETE, PATCH, OPTIONS')
                ->header('Access-Control-Allow-Headers', 'Content-Type, Authorization, X-Requested-With, SECRET-KEY, SECRET_KEY, secret-key, secret_key, Accept')
                ->header('Access-Control-Max-Age', '86400');
        }

        $secretKey = $request->header('SECRET-KEY')
            ?? $request->header('SECRET_KEY')
            ?? $request->header('secret-key')
            ?? $request->header('secret_key')
            ?? $request->query('secret_key')
            ?? $request->input('secret_key');

        if (!$secretKey || $secretKey !== config('app.secret_key')) {
            return response()->json([
                'message' => 'Unauthorized',
            ], 401);
        }

        return $next($request);
    }
}
