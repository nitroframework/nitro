<?php

namespace Nitro\Fusion\View\ServerRendering;

use JsonException;
use Psr\Log\LoggerInterface;

/**
 * The app's render service: a Node process (php artisan fusion:ssr) that has the app's views
 * built for the server (nitro-fusion/ssr). Each view is a POST to {url}/render with its
 * ViewState; the answer is {"html": "..."}, {"error": "..."} when the view failed, or
 * {"unserved": true} for an engine the service has no renderer for.
 *
 * It never holds a page up for long: a service that is not running answers at once (the
 * connection is refused), a slow one has `timeout` seconds. Once it has failed, the rest of the
 * request leaves its views to the browser. A view's own error is logged and only that view
 * goes to the browser.
 */
final class NodeRenderer implements ServerRenderer
{
    private bool $unavailable = false;

    /** @var array<string, true> engines the service has no renderer for */
    private array $unserved = [];

    public function __construct(
        private readonly string $url,
        private readonly float $timeout = 2.0,
        private readonly ?LoggerInterface $log = null,
    ) {
    }

    public function render(ViewState $state): ?string
    {
        if ($this->unavailable || isset($this->unserved[$state->engine])) {
            return null;
        }

        try {
            $payload = json_encode($state, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            $this->log?->warning("Fusion server rendering: [{$state->view}] has state JSON cannot hold ({$exception->getMessage()}); the browser renders it.");

            return null;
        }

        $context = stream_context_create(['http' => [
            'method' => 'POST',
            'header' => "Content-Type: application/json\r\nAccept: application/json\r\n",
            'content' => $payload,
            'timeout' => $this->timeout,
            /** A view's error comes back as a 500 with its message: read it rather than fail. */
            'ignore_errors' => true,
        ]]);

        $body = @file_get_contents(rtrim($this->url, '/').'/render', false, $context);

        if ($body === false) {
            $this->unavailable = true;
            $this->log?->warning("Fusion server rendering: no render service at {$this->url} (php artisan fusion:ssr); the browser renders this request's views.");

            return null;
        }

        $answer = json_decode($body, true);

        /** An engine the service does not render (one of the app's own): its views are the browser's, quietly. */
        if (is_array($answer) && ($answer['unserved'] ?? false) === true) {
            $this->unserved[$state->engine] = true;

            return null;
        }

        if (! is_array($answer) || ! is_string($answer['html'] ?? null)) {
            $error = is_array($answer) && is_string($answer['error'] ?? null) ? $answer['error'] : 'an answer without HTML';
            $this->log?->warning("Fusion server rendering: the {$state->engine} view [{$state->view}] failed: {$error}; the browser renders it.");

            return null;
        }

        return $answer['html'];
    }
}
