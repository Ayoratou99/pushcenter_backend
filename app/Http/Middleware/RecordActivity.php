<?php

namespace App\Http\Middleware;

use App\Services\ActivityRecorder;
use App\Support\ActivityCatalog;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Records the console actions listed in ActivityCatalog, once they succeed.
 */
class RecordActivity
{
    public function __construct(private readonly ActivityRecorder $recorder)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $definition = ActivityCatalog::for($request);

        if (! $definition) {
            return $next($request);
        }

        // Read before the controller runs: a deleted subject keeps its name.
        $before = $this->recorder->before($request, $definition);

        $response = $next($request);

        if ($response->getStatusCode() < 400) {
            $this->recorder->recordRequest($request, $response, $definition, $before);
        }

        return $response;
    }
}
