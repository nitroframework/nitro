<?php

namespace Nitro\Rendering;

use Illuminate\Contracts\Container\Container;
use Illuminate\Support\HtmlString;
use Nitro\Compiler\CompileException;
use Nitro\Compiler\ComponentMeta;
use Nitro\Component;
use Nitro\Support\Config;

/**
 * The page's <head>: its title and the tags search engines and link previews read, from what
 * mount() set ($this->head()), #[Title] / #[Description] and nitro.head. @nitroHead prints it;
 * a visit's answer carries it; a client page's is worked out at build time. And the document title
 * as the layout writes it (layoutTitle()). A part of Nitro.
 */
final class PageHead
{
    /** @var array{title: ?string, description: ?string, image: ?string, canonical: ?string, noindex: bool, meta: array<string, string>}|null the page being answered's <head> */
    private ?array $head = null;

    /** Whether the layout printed @nitroHead (a visit sends the head's tags only then). */
    private bool $printed = false;

    /** Whether the layout is being rendered for its title only (layoutTitle()). */
    private bool $renderingTitle = false;

    public function __construct(
        private readonly Config $config,
        private readonly Container $app,
        private readonly Portals $portals,
    ) {
    }

    /**
     * The page's <head>, as @nitroHead prints it, now the one being answered: what mount() set
     * ($this->head()), else #[Title] and #[Description], else the nitro.head defaults.
     *
     * @return array{title: ?string, description: ?string, image: ?string, canonical: ?string, noindex: bool, meta: array<string, string>}
     */
    public function forPage(Component $component, ComponentMeta $meta): array
    {
        $set = $component->nitro()->head?->values() ?? [];

        return $this->head = [
            'title' => $set['title'] ?? $meta->title,
            'description' => $set['description'] ?? $meta->description ?? $this->config->get('head.description'),
            'image' => $set['image'] ?? $this->config->get('head.image'),
            'canonical' => $set['canonical'] ?? null,
            'noindex' => $set['noindex'] ?? false,
            'meta' => $set['meta'] ?? [],
        ];
    }

    /**
     * A client page's title and head tags (#[Navigate('client')]), worked out at build time from
     * its #[Title], #[Description] and layout, since a visit to it does not ask the server: what a
     * visit's answer would carry ('head' null when the layout has no @nitroHead).
     *
     * @return array{title: ?string, head: ?string}
     *
     * @throws CompileException when its layout cannot be rendered outside a request
     */
    public function forClientPage(ComponentMeta $meta): array
    {
        $layout = $meta->layout->view ?? $this->config->layout();
        $previous = [$this->head, $this->printed];
        $this->head = [
            'title' => $meta->title,
            'description' => $meta->description ?? $this->config->get('head.description'),
            'image' => $this->config->get('head.image'),
            'canonical' => null,
            'noindex' => false,
            'meta' => [],
        ];
        $this->printed = false;

        try {
            /** Each page's layout renders afresh: its @portal places are its own. */
            $title = $this->portals->apart(fn () => $this->layoutTitle($layout, [...($meta->layout->data ?? []), 'title' => $meta->title]));

            return ['title' => $title, 'head' => $this->printed() ? $this->tags() : null];
        } catch (\Throwable $exception) {
            throw new CompileException("#[Navigate('client')] on {$meta->class}: its title comes from its layout [{$layout}], which could not be rendered at build time ({$exception->getMessage()}). Use #[Navigate('markup')].", $meta->file);
        } finally {
            [$this->head, $this->printed] = $previous;
        }
    }

    /**
     * @nitroHead: the page's <title> (in the nitro.head.title format) and the tags search engines
     * and link previews read. Outside a Nitro page (a plain Blade view), the defaults.
     */
    public function html(): HtmlString
    {
        $this->printed = true;
        $title = $this->head['title'] ?? null;
        $format = (string) $this->config->get('head.title');
        $text = $title === null || $title === '' ? (string) $this->app->make('config')->get('app.name') : str_replace('%s', $title, $format);

        return new HtmlString('<title>'.e($text)."</title>\n".$this->tags());
    }

    /** Whether the layout printed @nitroHead. */
    public function printed(): bool
    {
        return $this->printed;
    }

    /** The head's tags but the title, each marked data-nitro-head for the browser to replace on a visit. */
    public function tags(): string
    {
        $head = $this->head ?? ['title' => null, 'description' => $this->config->get('head.description'), 'image' => $this->config->get('head.image'), 'canonical' => null, 'noindex' => false, 'meta' => []];
        $tags = [];
        $meta = fn (string $attribute, string $key, ?string $content) => $content === null || $content === ''
            ? null
            : '<meta '.$attribute.'="'.e($key).'" content="'.e($content).'" data-nitro-head>';

        $tags[] = $meta('name', 'description', $head['description']);
        $tags[] = $meta('property', 'og:title', $head['title']);
        $tags[] = $meta('property', 'og:description', $head['description']);
        $tags[] = $meta('property', 'og:image', $head['image']);
        $tags[] = $head['image'] === null || $head['image'] === '' ? null : $meta('name', 'twitter:card', 'summary_large_image');
        $tags[] = $head['canonical'] === null ? null : '<link rel="canonical" href="'.e($head['canonical']).'" data-nitro-head>';
        $tags[] = $head['noindex'] ? $meta('name', 'robots', 'noindex') : null;

        foreach ($head['meta'] as $name => $content) {
            $tags[] = $meta('name', $name, $content);
        }

        return implode("\n", array_filter($tags));
    }

    /** The document title as the layout writes it (the layout owns its format). */
    public function layoutTitle(string $layout, array $layoutData): ?string
    {
        /** For its title only: @nitroScripts writes nothing (no shared data resolved, no globals built). */
        $this->renderingTitle = true;

        try {
            $html = $this->app->make('view')->make($layout, [...$layoutData, 'slot' => new HtmlString('')])->render();
        } finally {
            $this->renderingTitle = false;
        }

        return preg_match('/<title\b[^>]*>(.*?)<\/title>/is', $html, $match)
            ? html_entity_decode(trim($match[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8')
            : null;
    }

    /** Whether the layout is being rendered for its title only: @nitroScripts writes nothing then. */
    public function renderingTitle(): bool
    {
        return $this->renderingTitle;
    }
}
