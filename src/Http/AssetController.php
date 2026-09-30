<?php

namespace Nitro\Fusion\Http;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Nitro\Fusion\Build\Bundle;
use Nitro\Fusion\Compiler\CompileException;
use Nitro\Fusion\Fusion;

/**
 * Serves the runtime (fusion.js) and the application's compiled components (app.js, and the
 * #[LazyLoad] chunks, app/<group>.js). Requests carrying the current version are cached for a
 * year; the version changes with the content.
 */
final class AssetController
{
    public function runtime(Request $request, Fusion $fusion): Response
    {
        return $this->script((string) file_get_contents($fusion->runtimePath()), $request->query('v') === $fusion->runtimeVersion());
    }

    public function bundle(Request $request, Bundle $bundle): Response
    {
        return $this->compiled($request, $bundle, fn () => $bundle->path());
    }

    /** A #[LazyLoad] group's chunk (app/<group>.js). */
    public function chunk(Request $request, Bundle $bundle, string $group): Response
    {
        return $this->compiled($request, $bundle, fn () => $bundle->chunkPath($group) ?? abort(404));
    }

    /** @param  Closure(): string  $path */
    private function compiled(Request $request, Bundle $bundle, Closure $path): Response
    {
        try {
            $path = $path();
        } catch (CompileException $e) {
            /** In development the page still renders; the console says what failed to compile. */
            if (! config('app.debug')) {
                throw $e;
            }

            return $this->script('console.error('.json_encode('Fusion: '.$e->getMessage()).');', false);
        }

        return $this->script((string) file_get_contents($path), $request->query('v') === $bundle->tag());
    }

    private function script(string $contents, bool $immutable): Response
    {
        return new Response($contents, 200, [
            'Content-Type' => 'application/javascript; charset=utf-8',
            'Cache-Control' => $immutable ? 'public, max-age=31536000, immutable' : 'no-cache',
        ]);
    }
}
