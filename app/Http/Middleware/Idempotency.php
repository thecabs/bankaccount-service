<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class Idempotency
{
    /**
     * Empêche les doublons sur POST/PUT/PATCH/DELETE.
     * Usage: ->middleware('idempotency:600')  // TTL=600s
     */
    public function handle(Request $request, Closure $next, int $ttl = 600)
    {
        if (!in_array($request->getMethod(), ['POST','PUT','PATCH','DELETE'], true)) {
            return $next($request);
        }

        $key = $request->header('Idempotency-Key');
        if (!$key) {
            return response()->json(['error' => 'Idempotency-Key required'], 400);
        }

        $subject = $request->attributes->get('external_id')
            ?? $request->attributes->get('sub')
            ?? $request->ip();

        $bodyHash = base64_encode(hash('sha256', $request->getContent() ?: '', true));
        $route    = $request->getMethod() . ' ' . $request->getPathInfo();
        $cacheKey = 'idem:' . md5($subject . '|' . $route . '|' . $key . '|' . $bodyHash);

        // add() retourne false si la clé existe déjà → doublon
        if (!Cache::add($cacheKey, 1, now()->addSeconds($ttl))) {
            return response()->json(['error' => 'Duplicate operation'], 409);
        }

        // Trace id si absent (utile pour audit)
        if (!$request->headers->has('X-Request-Id')) {
            $request->headers->set('X-Request-Id', (string) Str::uuid());
        }

        return $next($request);
    }
}
