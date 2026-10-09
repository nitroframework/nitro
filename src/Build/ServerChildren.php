<?php

namespace Nitro\Build;

use Nitro\Compiler\ComponentMeta;
use Nitro\Compiler\Components;
use Nitro\Compiler\Template\ChildTag;
use Nitro\View\ViewEngines;

/**
 * Whether a component's view places a child only the server can make: one with mount() (only the
 * server has its parameters), or, besides, one with #[Server] methods or a store (its signed state
 * comes from the server's render). Read from the view's source, through the children's own views.
 */
final class ServerChildren
{
    /** @var array<string, bool> class (#mount: mount() only) => found */
    private array $found = [];

    public function __construct(private readonly Components $components, private readonly ViewEngines $views)
    {
    }

    /**
     * @param  bool  $mountOnly  only children with mount()
     * @param  array<string, true>  $seen  the views already read, through this one's children
     */
    public function placedBy(ComponentMeta $meta, bool $mountOnly = false, array &$seen = []): bool
    {
        /** A store has no view, and a view the app's build compiles places no Nitro children. */
        if ($meta->store || ! $this->views->of($meta)->compiledByNitro()) {
            return false;
        }

        $key = $meta->class.($mountOnly ? '#mount' : '');

        if (isset($this->found[$key])) {
            return $this->found[$key];
        }

        $seen[$meta->class] = true;
        $source = (string) file_get_contents($this->views->of($meta)->file($meta));
        $found = false;

        preg_match_all('/<nitro:([\w.\-]+)/', $source, $tags);

        foreach (array_unique($tags[1]) as $name) {
            /** It may name any component, one with mount() or #[Server] methods too. */
            if ($name === ChildTag::DYNAMIC) {
                $found = true;

                break;
            }

            if (! $this->components->has($name)) {
                continue;
            }

            $child = $this->components->meta($name);

            /** A child that uses a store: rendered here, so the store is made here (its mount()) and sent with the page. */
            if ($child->hasMount || (! $mountOnly && ($child->serverMethods !== [] || $child->stores !== [])) || (! isset($seen[$child->class]) && $this->placedBy($child, $mountOnly, $seen))) {
                $found = true;

                break;
            }
        }

        return $this->found[$key] = $found;
    }
}
