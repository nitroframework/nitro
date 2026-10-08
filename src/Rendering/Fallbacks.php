<?php

namespace Nitro\Fusion\Rendering;

use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Nitro\Fusion\Component;
use Nitro\Fusion\Support\ComponentHooks;
use Nitro\Fusion\Support\Config;
use Nitro\Fusion\Support\Protocol;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/**
 * What shows in place of a component that failed, so the rest of the page works on: its
 * fallback() (a view, HTML, or text), else fusion.fallbacks.view, else a short message with a link
 * that loads the page again. The error goes to the application's exception handler. The one place
 * a failure turns into a fallback: a component placed on the server, a #[Server] call, and (the
 * default, given with @fusionScripts) a render in the browser.
 *
 * Off when fusion.fallbacks.enabled is false, or null and APP_DEBUG is on: in development the
 * error itself shows.
 *
 * @internal
 */
final class Fallbacks
{
    private const MESSAGE = '<p class="fusion-fallback">This part of the page could not load. <a href="">Try again</a></p>';

    public function __construct(
        private readonly Container $app,
        private readonly Config $config,
    ) {
    }

    public function enabled(): bool
    {
        $enabled = $this->config->get('fallbacks.enabled');

        return $enabled === null ? ! $this->config->debug() : (bool) $enabled;
    }

    /**
     * A component placed in a view that failed to start or render: its fallback, marked for the
     * browser and with the component's id (a parent's view finds its child there and keeps it; it
     * has no state, so it does not start). The error again when fallbacks are off, or when it is the
     * request's answer (abort(403), an HTTP exception), not the component's failure.
     */
    public function placed(string $id, ?Component $component, Throwable $exception): string
    {
        if (! $this->enabled() || $exception instanceof HttpExceptionInterface) {
            throw $exception;
        }

        $this->report($exception);

        return '<div '.Protocol::attribute('fallback').' '.Protocol::attribute('id').'="'.e($id).'">'.$this->html($component, $exception).'</div>';
    }

    /**
     * A #[Server] call that threw: the answer that has the browser show the component's fallback
     * (it keeps its state); null when fallbacks are off, for the error to go on as it is.
     */
    public function failedCall(Component $component, Throwable $exception): ?JsonResponse
    {
        if (! $this->enabled() || $exception instanceof HttpExceptionInterface) {
            return null;
        }

        $this->report($exception);

        return new JsonResponse(['message' => 'Server Error', 'fallback' => $this->html($component, $exception)], 500);
    }

    /** What the browser shows when a component fails to render there: the default; null when fallbacks are off. */
    public function forBrowser(): ?string
    {
        return $this->enabled() ? $this->html(null, null) : null;
    }

    private function html(?Component $component, ?Throwable $exception): string
    {
        if ($component !== null && method_exists($component, ComponentHooks::FALLBACK)) {
            try {
                return $this->toHtml($component->{ComponentHooks::FALLBACK}($exception));
            } catch (Throwable $failed) {
                $this->report($failed);
            }
        }

        $view = $this->config->get('fallbacks.view');

        if (is_string($view) && $view !== '') {
            try {
                return $this->app->make('view')->make($view)->render();
            } catch (Throwable $failed) {
                $this->report($failed);
            }
        }

        return self::MESSAGE;
    }

    private function toHtml(mixed $fallback): string
    {
        return match (true) {
            $fallback instanceof View => $fallback->render(),
            $fallback instanceof Htmlable => $fallback->toHtml(),
            default => e((string) $fallback),
        };
    }

    private function report(Throwable $exception): void
    {
        $this->app->make(ExceptionHandler::class)->report($exception);
    }
}
