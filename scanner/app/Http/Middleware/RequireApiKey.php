<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequireApiKey
{
    public function handle(Request $request, Closure $next): Response
    {
        $expected = (string) config('scanner.api_key');
        $given = (string) $request->header('X-Api-Key', '');

        if ($expected === '' || ! hash_equals($expected, $given)) {
            return response()->json(['error' => 'unauthorized'], 401);
        }

        return $next($request);
    }
}
