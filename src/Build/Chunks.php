<?php

namespace Nitro\Fusion\Build;

use Nitro\Fusion\Compiler\Components;
use Nitro\Fusion\Support\Registry;

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
    ) {
    }

    /** @return array<string, string> each component in a chunk => its group */
    public function map(): array
    {
        if ($this->map === null) {
            $this->map = [];

            foreach (array_keys($this->registry->all()) as $name) {
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
        return $built ? substr(hash('sha256', 'fusion-chunk:'.$group), 0, 8) : $group;
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
     * The Route::fusion() pages of components in chunks, as [path pattern, group]: a visit to a
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
