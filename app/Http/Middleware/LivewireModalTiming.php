<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class LivewireModalTiming
{
    public function handle(Request $request, Closure $next): mixed
    {
        if (! str_ends_with($request->path(), '/livewire/update')) {
            return $next($request);
        }

        $started = hrtime(true);
        $sqlCount = 0;
        $sqlDuration = 0.0;
        $listener = function ($query) use (&$sqlCount, &$sqlDuration): void {
            $sqlCount++;
            $sqlDuration += (float) $query->time;
        };
        DB::listen($listener);

        $payload = $request->json()?->all();
        $component = data_get($payload, 'components.0.snapshot.memo.name')
            ?: data_get($payload, 'components.0.snapshot.memo.name');
        $calls = data_get($payload, 'components.0.calls', []);
        $callNames = collect($calls)->map(fn ($call) => data_get($call, 'method'))->filter()->values()->all();

        $response = $next($request);
        $elapsed = (hrtime(true) - $started) / 1_000_000;
        $body = method_exists($response, 'getContent') ? (string) $response->getContent() : '';

        Log::channel('stack')->debug('LIVEWIRE_MODAL_TIMING', [
            'component' => $component,
            'calls' => $callNames,
            'php_ms' => round($elapsed, 2),
            'sql_count' => $sqlCount,
            'sql_ms' => round($sqlDuration, 2),
            'request_bytes' => strlen((string) $request->getContent()),
            'response_bytes' => strlen($body),
        ]);

        return $response;
    }
}
