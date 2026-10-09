<?php

namespace Nitro\Rendering;

use Illuminate\Support\HtmlString;
use InvalidArgumentException;
use Nitro\Compiler\Template\ChildTag;

/**
 * <nitro:...> tags with content, as a view renders them: the content renders into output
 * buffers, one per slot (<nitro:slot name="...">), until </nitro:...> mounts the component with
 * them (Nitro::tag()). A part of Nitro, which these tags call.
 */
final class Tags
{
    /**
     * <nitro:...> tags with content being rendered, innermost last: the slots they collect.
     *
     * @var list<array{name: string, props: array, key: mixed, number: int, hydrate: ?string, slots: array<string, string>, slot: ?string}>
     */
    private array $tags = [];

    public function __construct(private readonly Placement $placement, private readonly ComponentRenderer $renderer)
    {
    }

    /**
     * <nitro:name ...> with content: the content renders (here, in this view) into output buffers,
     * one per slot, until </nitro:name> renders the component with them.
     */
    public function start(string $name, array $props, mixed $key, int $number, ?string $hydrate = null): string
    {
        /** Keyed now: the loops around the tag, not those its content may have. */
        $key = $this->renderer->current() !== null && $key === null ? ChildTag::key($number, $this->renderer->loopIndexes()) : $key;
        $this->tags[] = ['name' => $name, 'props' => $props, 'key' => $key, 'number' => $number, 'hydrate' => $hydrate, 'slots' => [], 'slot' => null];
        ob_start();

        return '';
    }

    /** <nitro:slot name="footer"> inside a tag with content. */
    public function startSlot(string $name): string
    {
        $top = array_key_last($this->tags);

        if ($top === null || $this->tags[$top]['slot'] !== null) {
            throw new InvalidArgumentException("<nitro:slot name=\"{$name}\"> belongs directly inside a <nitro:...> tag");
        }

        $this->tags[$top]['slot'] = $name;
        ob_start();

        return '';
    }

    public function endSlot(): string
    {
        $top = array_key_last($this->tags);

        if ($top === null || $this->tags[$top]['slot'] === null) {
            throw new InvalidArgumentException('</nitro:slot> closes no <nitro:slot>');
        }

        $this->tags[$top]['slots'][$this->tags[$top]['slot']] = (string) ob_get_clean();
        $this->tags[$top]['slot'] = null;

        return '';
    }

    /** </nitro:name>: the component, with what was between its tags as its default slot. */
    public function end(string $name): HtmlString
    {
        $tag = array_pop($this->tags);

        if ($tag === null || $tag['name'] !== $name || $tag['slot'] !== null) {
            throw new InvalidArgumentException("</nitro:{$name}> closes no <nitro:{$name}>");
        }

        $slots = ['default' => (string) ob_get_clean(), ...$tag['slots']];

        return $this->placement->tag($tag['name'], $tag['props'], $tag['key'], $tag['number'], $slots, $tag['hydrate']);
    }
}
