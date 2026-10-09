<?php

namespace Nitro\Rendering;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Contracts\Support\Responsable;
use Illuminate\Contracts\View\View;
use Illuminate\Database\RecordNotFoundException;
use Illuminate\Database\RecordsNotFoundException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Exceptions\OriginMismatchException;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Exceptions\BackedEnumCaseNotFoundException;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Validation\ValidationException;
use Nitro\Component;
use Nitro\Support\ComponentHooks;
use Nitro\Support\Config;
use Nitro\Support\CurrentRequest;
use Nitro\Support\Protocol;
use Symfony\Component\HttpFoundation\Exception\RequestExceptionInterface;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/**
 * What shows in place of a component that failed, so the rest of the page works on: its
 * fallback() (a view, HTML, or text), else nitro.fallbacks.view, else a short message with a link
 * that loads the page again. The error goes to the application's exception handler. The one place
 * a failure turns into a fallback: a component placed on the server, a #[Server] call, and (the
 * default, given with @nitroScripts) a render in the browser.
 *
 * Off when nitro.fallbacks.enabled is false, or null and APP_DEBUG is on: in development the
 * error itself shows.
 *
 * @internal
 */
final class Fallbacks
{
    private const MESSAGE = '<p class="nitro-fallback">This part of the page could not load. <a href="">Try again</a></p>';

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
     * has no state, so it does not start). The error again when fallbacks are off. The request's
     * answer (isAnswer()), not the component's failure, goes as Laravel's handler renders it, thrown
     * as a response: the view it fails in lets a response through, where it would wrap an exception.
     */
    public function placed(string $id, ?Component $component, Throwable $exception): string
    {
        $request = CurrentRequest::of($this->app);

        if ($request !== null && $this->isAnswer($exception)) {
            throw new HttpResponseException($this->app->make(ExceptionHandler::class)->render($request, $exception));
        }

        if (! $this->enabled() || $this->isAnswer($exception)) {
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
        if (! $this->enabled() || $this->isAnswer($exception)) {
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

    /**
     * What Laravel's exception handler sends as an answer rather than an error: an HTTP exception
     * (abort()), what it turns into one (a policy, a missing model or enum case, a CSRF token, a
     * bad request), a guest, failed validation, a response thrown (a redirect), or an exception that
     * renders its own. The visitor gets it as Laravel sends it, never a fallback.
     */
    private function isAnswer(Throwable $exception): bool
    {
        return $exception instanceof HttpExceptionInterface
            || $exception instanceof HttpResponseException
            || $exception instanceof Responsable
            || $exception instanceof AuthenticationException
            || $exception instanceof AuthorizationException
            || $exception instanceof ValidationException
            || $exception instanceof RecordsNotFoundException
            || $exception instanceof RecordNotFoundException
            || $exception instanceof BackedEnumCaseNotFoundException
            || $exception instanceof TokenMismatchException
            || $exception instanceof OriginMismatchException
            || $exception instanceof RequestExceptionInterface;
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
