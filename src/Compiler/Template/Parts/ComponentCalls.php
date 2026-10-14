<?php

namespace Nitro\Compiler\Template\Parts;

use Nitro\Compiler\Template\ComponentViews;
use Nitro\Compiler\Template\TemplateNode;
use Nitro\Support\Protocol;

/**
 * A Blade component's tag, as BladeInliner leaves it: a call of the component's view, compiled
 * once (ComponentViews). The inliner writes
 *
 *   <nitro-call nitro:click="..." ...><nitro-slot name="footer">...</nitro-slot></nitro-call>@nitroCall({view, inputs})
 *
 * read as any element (its nitro: attributes and its slots' content are the caller's, compiled
 * where the tag is), then made the call node (the directive after it also takes the newline after
 * the tag, as Blade's component tag does):
 *
 *   {t: "v", v: the view's id, i: the inputs (an expression), d: the nitro: attributes, slots: {name: {h}},
 *    l: where the caller's $loop is, when it has one}
 *
 * Nitro's own; the markers are never written in a view.
 *
 * @internal
 */
final class ComponentCalls extends Part
{
    public const TAG = 'nitro-call';

    public const SLOT = 'nitro-slot';

    /**
     * @nitroCall({view, inputs}) after the call's element: the element made the call node, its
     * inputs read where the tag is, its slots the <nitro-slot> elements in it.
     */
    public function call(string $args): void
    {
        $context = $this->context;
        $call = json_decode((string) base64_decode($args), true, 16, JSON_THROW_ON_ERROR);
        $children = $this->compiler->tree->current()->children;
        $node = end($children);

        if (! $node instanceof TemplateNode || ($node->fields['c'] ?? null) !== self::TAG) {
            throw $context->error('@nitroCall follows a component\'s call');
        }

        $table = $this->compiler->expressionTable;
        $slots = [];

        foreach ($node->children as $child) {
            if (($child->fields['c'] ?? null) !== self::SLOT) {
                continue;
            }

            foreach ($child->attributes as $attribute) {
                if ($attribute['c'] === 'name') {
                    $slots[$attribute['h'][0]['c'] ?? 'default'] = $child;
                }
            }
        }

        /** l: where the caller's $loop is: the view's loops have it as their parent (Blade's one loop stack). */
        $loop = $table->visibleLoop();
        $node->fields = array_filter([
            't' => 'v',
            'v' => $call['view'],
            'i' => $table->expression($table->expr($call['inputs']), $table->php($call['inputs'])),
            'd' => self::byName($node->attributes),
            'l' => $loop[0] ?? null,
        ], fn ($value) => $value !== [] && $value !== null);
        $node->attributes = [];
        $node->children = [];
        $node->slots = $slots;
        $context->calls[] = $call['view'];

        /** Its view's elements and its slots' content, under what is open where the tag is. */
        $this->compiler->nesting->called(app(ComponentViews::class)->nesting($call['view']), $slots, $call['label']);
    }

    /**
     * The call's nitro: attributes by name as written, each with its entries: an application's
     * attribute with the marker entry before it (NitroAttributes).
     *
     * @param  list<array<string, mixed>>  $attributes
     * @return array<string, list<array<string, mixed>>>
     */
    private static function byName(array $attributes): array
    {
        $named = [];
        $before = [];

        foreach ($attributes as $attribute) {
            if ($attribute['c'] === Protocol::attribute('directive')) {
                $before[] = $attribute;

                continue;
            }

            $named[$attribute['c']] = [...$before, $attribute];
            $before = [];
        }

        return $named;
    }
}
