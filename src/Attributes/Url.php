<?php

namespace Nitro\Fusion\Attributes;

use Attribute;
use Illuminate\Http\Request;
use Nitro\Fusion\Compiler\Components;
use Nitro\Fusion\Component;
use Nitro\Fusion\FeatureAttribute;

/**
 * A property kept in the query string, as Livewire's #[Url]: the page reads it from the URL when
 * the component is created, and the browser writes it back as it changes, so a search, a sort
 * or a page survives a reload and can be shared.
 *
 *   #[Url] public string $search = '';               ?search=...
 *   #[Url(as: 'q')] public string $search = '';      ?q=...
 *   #[Url(history: true)] public int $page = 1;      each change is a history entry (back goes back)
 *   #[Url(keep: true)] public string $sort = 'name'; in the URL even at its default
 *   #[Url(except: 1)] public int $page = 1;          left out when it is 1
 *
 * A value equal to the property's declared default is left out of the URL (unless keep), or the
 * value given as except. A value from the URL that does not fit the property's type is ignored.
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
final class Url extends FeatureAttribute
{
    public function __construct(
        public readonly ?string $as = null,
        public readonly bool $history = false,
        public readonly bool $keep = false,
        public readonly mixed $except = new NoValue,
    ) {
    }

    public function hasExcept(): bool
    {
        return ! $this->except instanceof NoValue;
    }

    /** The value from the request's query string, when the component is created (before mount(), so mount() sees it). */
    public function mounting(Component $component): void
    {
        $request = app()->bound('request') ? app('request') : null;
        $name = (string) $this->target();
        $key = $this->as ?? $name;

        if (! $request instanceof Request || ! $request->query->has($key)) {
            return;
        }

        $property = app(Components::class)->meta($component::class)->property($name);
        [$fits, $value] = $property?->fromQuery($request->query->all()[$key]) ?? [false, null];

        if ($fits) {
            $component->{$name} = $value;
        }
    }
}
