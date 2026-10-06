<?php

namespace Nitro\Fusion\Rendering;

use Illuminate\Support\HtmlString;
use InvalidArgumentException;
use Nitro\Fusion\Compiler\Template\ChildTag;
use Nitro\Fusion\Fusion;

/**
 * <fusion:...> tags with content, as a view renders them: the content renders into output
 * buffers, one per slot (<fusion:slot name="...">), until </fusion:...> mounts the component with
 * them (Fusion::tag()). A part of Fusion, which these tags call.
 */
final class Tags
{
    /**
     * <fusion:...> tags with content being rendered, innermost last: the slots they collect.
     *
     * @var list<array{name: string, props: array, key: mixed, number: int, hydrate: ?string, slots: array<string, string>, slot: ?string}>
     */
    private array $tags = [];

    public function __construct(private readonly Fusion $fusion, private readonly ComponentRenderer $renderer)
    {
    }

    /**
     * <fusion:name ...> with content: the content renders (here, in this view) into output buffers,
     * one per slot, until </fusion:name> renders the component with them.
     */
    public function start(string $name, array $props, mixed $key, int $number, ?string $hydrate = null): string
    {
        /** Keyed now: the loops around the tag, not those its content may have. */
        $key = $this->renderer->current() !== null && $key === null ? ChildTag::key($number, $this->renderer->loopIndexes()) : $key;
        $this->tags[] = ['name' => $name, 'props' => $props, 'key' => $key, 'number' => $number, 'hydrate' => $hydrate, 'slots' => [], 'slot' => null];
        ob_start();

        return '';
    }

    /** <fusion:slot name="footer"> inside a tag with content. */
    public function startSlot(string $name): string
    {
        $top = array_key_last($this->tags);

        if ($top === null || $this->tags[$top]['slot'] !== null) {
            throw new InvalidArgumentException("<fusion:slot name=\"{$name}\"> belongs directly inside a <fusion:...> tag");
        }

        $this->tags[$top]['slot'] = $name;
        ob_start();

        return '';
    }

    public function endSlot(): string
    {
        $top = array_key_last($this->tags);

        if ($top === null || $this->tags[$top]['slot'] === null) {
            throw new InvalidArgumentException('</fusion:slot> closes no <fusion:slot>');
        }

        $this->tags[$top]['slots'][$this->tags[$top]['slot']] = (string) ob_get_clean();
        $this->tags[$top]['slot'] = null;

        return '';
    }

    /** </fusion:name>: the component, with what was between its tags as its default slot. */
    public function end(string $name): HtmlString
    {
        $tag = array_pop($this->tags);

        if ($tag === null || $tag['name'] !== $name || $tag['slot'] !== null) {
            throw new InvalidArgumentException("</fusion:{$name}> closes no <fusion:{$name}>");
        }

        $slots = ['default' => (string) ob_get_clean(), ...$tag['slots']];

        return $this->fusion->tag($tag['name'], $tag['props'], $tag['key'], $tag['number'], $slots, $tag['hydrate']);
    }
}
