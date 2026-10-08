<?php

namespace Nitro\Fusion\Tests\Fixtures\Http;

use Closure;
use Illuminate\Http\Request;
use Nitro\Fusion\Fusion;
use Nitro\Fusion\Tests\Fixtures\Components\Store;
use Symfony\Component\HttpFoundation\Response;

/**
 * The data shared with every component, set for each request (Fusion is created per request):
 * the user (Greeting renames it), and countries, sent once.
 */
class ShareFixtureData
{
    public function handle(Request $request, Closure $next): Response
    {
        $fusion = app(Fusion::class);

        $fusion->share('user', fn () => ['name' => Store::$saved['user'] ?? 'Ada']);

        /** Sent once: the browser keeps it; its closure runs for the browser only while the browser lacks it. */
        $fusion->shareOnce('countries', function () {
            Store::$onceCalls++;

            return ['PK', 'US'];
        });

        return $next($request);
    }
}
