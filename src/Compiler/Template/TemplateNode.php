<?php

namespace Nitro\Compiler\Template;

/**
 * A node of a compiled template, while the compiler builds the tree. Its array form is what the
 * browser gets, in Viewi's shape:
 *
 *   t   type: r root, t tag, c child component, x text, m comment, b break/continue,
 *       template (a Blade block: @if, @foreach, ... in i)
 *   c   content: the tag or component name, the static text
 *   i   the block's directive: {c: "if", code: 3}, {c: "foreach", forData: 4, ...}
 *   a   attributes: {c: "class", h: [{c: "badge "}, {e: 1, code: 5}]}
 *   h   children
 *   e/code  an expression (code: its index in the component's expressions)
 *   slots   a child component's: {default: {h: [...]}, footer: {h: [...]}}, rendered by this
 *           component's view inside the child (Viewi's slots); "s" marks where a slot goes
 */
final class TemplateNode
{
    /** @var list<TemplateNode> */
    public array $children = [];

    /** @var list<array<string, mixed>> */
    public array $attributes = [];

    /** @var array<string, TemplateNode> a child component's slots: name => its content (a root node) */
    public array $slots = [];

    /** @param  array<string, mixed>  $fields */
    public function __construct(public array $fields)
    {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $node = $this->fields;

        if ($this->attributes !== []) {
            $node['a'] = $this->attributes;
        }

        if ($this->children !== []) {
            $node['h'] = array_map(fn (TemplateNode $child) => $child->toArray(), $this->children);
        }

        foreach ($this->slots as $name => $slot) {
            $node['slots'][$name] = ['h' => array_map(fn (TemplateNode $child) => $child->toArray(), $slot->children)];
        }

        return $node;
    }

    public function hasAttribute(string $name): bool
    {
        foreach ($this->attributes as $attribute) {
            if ($attribute['c'] === $name) {
                return true;
            }
        }

        return false;
    }
}
