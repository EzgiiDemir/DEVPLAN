<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Every log line written during a request's lifetime gets tagged with the
 * same request_id via Log::withContext() — so tracing a production incident
 * means grepping one id across every log line it touched, rather than
 * correlating timestamps across unrelated entries. Reuses an incoming
 * X-Request-Id header when present (e.g. from a reverse proxy or another
 * internal service) instead of always minting a new one, so a trace started
 * upstream doesn't fork into a second identity here — and always echoes the
 * id back in the response so the client can report it if something goes
 * wrong.
 */
class CorrelationId
{
    private const ATTRIBUTE_KEY = 'correlation_id';

    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $requestId = $request->header('X-Request-Id') ?: (string) Str::uuid();
        $request->attributes->set(self::ATTRIBUTE_KEY, $requestId);

        Log::withContext(['request_id' => $requestId]);

        $response = $next($request);
        $response->headers->set('X-Request-Id', $requestId);

        return $response;
    }

    /**
     * Reads the current request's correlation id, if this middleware has run
     * — used when creating a background AiJob so its payload can carry the
     * id across the queue boundary, letting whatever worker process
     * eventually picks it up re-establish the same Log::withContext() and
     * keep the trace intact.
     */
    public static function current(): ?string
    {
        return request()?->attributes->get(self::ATTRIBUTE_KEY);
    }
}
