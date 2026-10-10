<?php

namespace Nitro\Build;

use Closure;
use Nitro\Compiler\Components;
use Nitro\Support\Registry;

/**
 * What is not in app.js, and how the browser asks for it: every page in a chunk of its own (or its
 * #[LazyLoad] group), as Next.js and Nuxt split by page, and components with #[LazyLoad]; the
 * components pages share stay in app.js. A chunk is named by its group while developing, by a
 * short hash of it in a build (as Vite names chunks).
 */
final class Chunks
{
    /** @var array<string, string>|null name => group */
    private ?array $map = null;

    public function __construct(
        private readonly Registry $registry,
        private readonly Components $components,
        private readonly PageRoutes $pageRoutes,
        /** @var (Closure(): mixed)|null the map as a build recorded it (Sources::derived()), while the components' files are as they were then */
        private readonly ?Closure $recorded = null,
    ) {
    }

    /** @return array<string, string> each component in a chunk => its group: from the build's record, else from each component's meta */
    public function map(): array
    {
        if ($this->map === null) {
            $recorded = $this->recorded === null ? null : ($this->recorded)();
            $this->map = is_array($recorded) ? $recorded : [];

            foreach (is_array($recorded) ? [] : array_keys($this->registry->all()) as $name) {
                $group = $this->components->meta($name)->lazy;

                if ($group !== null) {
                    $this->map[$name] = $group;
                }
            }
        }

        return $this->map;
    }

    /** @return list<string> the groups */
    public function groups(): array
    {
        return array_values(array_unique($this->map()));
    }

    /** A chunk's name in the browser: in a build, a short hash of its group; otherwise the group itself. */
    public function id(string $group, bool $built): string
    {
        return $built ? substr(hash('sha256', 'nitro-chunk:'.$group), 0, 8) : $group;
    }

    /** The group a chunk name (id()) stands for: by its hash, or by its group's own name. */
    public function groupFor(string $id): ?string
    {
        foreach ($this->groups() as $group) {
            if ($id === $group || $id === $this->id($group, true)) {
                return $group;
            }
        }

        return null;
    }

    /**
     * The Route::nitro() pages of components in chunks, as [path pattern, group]: a visit to a
     * matching path loads the chunk while it fetches the page. A pattern that matches too much
     * only loads a chunk early; the page's own component name decides what it needs.
     *
     * @return list<array{0: string, 1: string}>
     */
    public function routes(): array
    {
        $map = $this->map();
        $routes = [];

        foreach ($map === [] ? [] : $this->pageRoutes->all() as [$pattern, $name]) {
            if (isset($map[$name])) {
                $routes[] = [$pattern, $map[$name]];
            }
        }

        return $routes;
    }
}
