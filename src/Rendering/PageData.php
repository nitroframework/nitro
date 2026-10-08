<?php

namespace Nitro\Fusion\Rendering;

use Nitro\Fusion\Support\Protocol;

/**
 * A page's data as the browser gets it in the page's HTML: the globals @fusionScripts gives (what
 * the browser's Laravel helpers need, the shared and flash data, the stores), and one block with
 * each server-rendered component's signed state, by its id, as Next.js and Nuxt carry a page's
 * data. Readable JSON, not an attribute per component: smaller, and no HTML escaping.
 *
 * Components render before and after @fusionScripts (a layout's own come after it), so the block
 * is a placeholder until the page is rendered: finish() fills it (Http\FinishPage). The one place
 * a page's data is written, or read back from a page; the runtime reads it in runtime/page-data.js.
 */
final class PageData
{
    /** Where the block goes, until finish() fills it. */
    private const PLACEHOLDER = '<!--fusion:state-->';

    /** @var array<string, string> component id => its signed state (JSON), for the page being rendered */
    private array $states = [];

    /** A component's signed state, for the page's block. */
    public function keep(string $id, string $envelope): void
    {
        $this->states[$id] = $envelope;
    }

    /**
     * @fusionScripts' part: the globals, and the place of the block.
     *
     * @param  array<string, mixed>  $globals
     */
    public function scripts(array $globals): string
    {
        return '<script type="application/json" '.Protocol::attribute('globals').'>'.self::json($globals).'</script>'."\n".self::PLACEHOLDER;
    }

    /** The page with its block: every state the request rendered. */
    public function finish(string $html): string
    {
        if (! str_contains($html, self::PLACEHOLDER)) {
            return $html;
        }

        $states = array_map(fn (string $envelope) => json_decode($envelope, true), $this->states);
        $this->states = [];

        return str_replace(self::PLACEHOLDER, '<script type="application/json" '.Protocol::attribute('state').'>'.self::json((object) $states).'</script>', $html);
    }

    /** An island that never starts (fusion:static): its HTML only, without the components' marks or their states. */
    public function withoutState(string $html): string
    {
        preg_match_all('/\s'.preg_quote(Protocol::attribute('id'), '/').'="([^"]*)"/', $html, $ids);

        foreach ($ids[1] as $id) {
            unset($this->states[html_entity_decode($id, ENT_QUOTES | ENT_HTML5)]);
        }

        return (string) preg_replace('/\s(?:'.preg_quote(Protocol::attribute('id'), '/').'|'.preg_quote(Protocol::attribute('name'), '/').')="[^"]*"/', '', $html);
    }

    /**
     * The signed states of the components in this HTML, by id, in their order there: from its block,
     * or, for a fragment rendered outside a page's answer (no block), from those this request kept.
     *
     * @return array<string, array<string, mixed>>
     */
    public function statesIn(string $html): array
    {
        preg_match_all('/\s'.preg_quote(Protocol::attribute('id'), '/').'="([^"]*)"/', $html, $ids);
        $block = preg_match('/<script type="application\/json" '.preg_quote(Protocol::attribute('state'), '/').'>(.*?)<\/script>/s', $html, $script) === 1
            ? (array) json_decode($script[1], true)
            : array_map(fn (string $envelope) => json_decode($envelope, true), $this->states);
        $states = [];

        foreach ($ids[1] as $id) {
            $id = html_entity_decode($id, ENT_QUOTES | ENT_HTML5);

            if (isset($block[$id])) {
                $states[$id] = $block[$id];
            }
        }

        return $states;
    }

    /** The page component's signed state, read from a page's HTML (its element marked fusion:page); null when there is none. */
    public function pageState(string $html): mixed
    {
        if (preg_match('/<[a-zA-Z][^>]*\s'.preg_quote(Protocol::attribute('page'), '/').'="[^"]*"[^>]*>/', $html, $root) !== 1
            || preg_match('/\s'.preg_quote(Protocol::attribute('id'), '/').'="([^"]*)"/', $root[0], $id) !== 1) {
            return null;
        }

        return $this->statesIn($html)[html_entity_decode($id[1], ENT_QUOTES | ENT_HTML5)] ?? null;
    }

    /** JSON that cannot end its <script> element early. */
    private static function json(mixed $value): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP);
    }
}
