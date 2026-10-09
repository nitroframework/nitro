<?php

namespace Nitro;

/**
 * A page's <head>: its title and the tags search engines and link previews read. Set in mount()
 * with $this->head(); what a page leaves unset comes from #[Title], #[Description] and the
 * nitro.head defaults. The layout prints it with @nitroHead; a nitro:navigate visit brings it
 * with the page's data.
 *
 *   $this->head()
 *       ->title($post->title)
 *       ->description($post->excerpt)
 *       ->image($post->cover_url)
 *       ->canonical(route('posts.show', $post));
 */
final class Head
{
    private ?string $title = null;

    private ?string $description = null;

    private ?string $image = null;

    private ?string $canonical = null;

    private bool $noindex = false;

    /** @var array<string, string> other <meta name="..."> tags */
    private array $meta = [];

    /** The page's title; the layout's format (nitro.head.title) is applied to it. */
    public function title(string $title): self
    {
        $this->title = $title;

        return $this;
    }

    /** The description search results and link previews show. */
    public function description(string $description): self
    {
        $this->description = $description;

        return $this;
    }

    /** The image link previews show (an absolute URL: asset() gives one). */
    public function image(string $url): self
    {
        $this->image = $url;

        return $this;
    }

    /** The page's one true URL, for a page reached by several. */
    public function canonical(string $url): self
    {
        $this->canonical = $url;

        return $this;
    }

    /** Keep search engines from listing the page. */
    public function noindex(bool $noindex = true): self
    {
        $this->noindex = $noindex;

        return $this;
    }

    /** Any other <meta name="..." content="...">: author, theme-color, ... */
    public function meta(string $name, string $content): self
    {
        $this->meta[$name] = $content;

        return $this;
    }

    /**
     * What was set, null for what was not.
     *
     * @return array{title: ?string, description: ?string, image: ?string, canonical: ?string, noindex: bool, meta: array<string, string>}
     */
    public function values(): array
    {
        return [
            'title' => $this->title,
            'description' => $this->description,
            'image' => $this->image,
            'canonical' => $this->canonical,
            'noindex' => $this->noindex,
            'meta' => $this->meta,
        ];
    }
}
