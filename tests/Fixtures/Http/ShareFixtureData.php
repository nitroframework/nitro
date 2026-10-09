<?php

namespace Nitro\Tests\Fixtures\Http;

use Closure;
use Illuminate\Http\Request;
use Nitro\Nitro;
use Nitro\Tests\Fixtures\Components\Store;
use Symfony\Component\HttpFoundation\Response;

/**
 * The data shared with every component, set for each request (Nitro is created per request):
 * the user (Greeting renames it), and countries, sent once.
 */
class ShareFixtureData
{
    public function handle(Request $request, Closure $next): Response
    {
        $nitro = app(Nitro::class);

        $nitro->share('user', fn () => ['name' => Store::$saved['user'] ?? 'Ada']);

        /** Sent once: the browser keeps it; its closure runs for the browser only while the browser lacks it. */
        $nitro->shareOnce('countries', function () {
            Store::$onceCalls++;

            return ['PK', 'US'];
        });

        return $next($request);
    }
}
