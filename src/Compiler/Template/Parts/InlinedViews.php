<?php

namespace Nitro\Compiler\Template\Parts;

use PhpParser\Node\Expr;

/**
 * Views inlined into the component's view (Blade's @include), and a Blade component's view
 * compiled once (ComponentViews), as Nitro compiles them. BladeInliner marks them with
 * directives of its own (@nitroScope, @nitroSlot, @nitroAttr); this part reads those markers.
 *
 *  - frames: an inlined view is a frame with the variables it was given (parsed as PHP, read
 *    through the frames around it), and sees the variables around it; a Blade component's view
 *    is a frame of its own, isolated: it sees only its @props, attributes and slots, as in Blade
 *  - a variable in a frame is replaced as the Transpiler parses it (replacement()): by what the
 *    view was given, kept if it is the view's own, or refused in a component
 *  - a component's slot (@nitroSlot, in its call) has its trailing whitespace trimmed, as Blade
 *    trims a slot's output
 *  - @nitroAttr: a component's attribute, written as Laravel's ComponentAttributeBag prints it
 *
 * Blade's semantics, implemented by Nitro; the markers are Nitro's own and never written in a
 * view.
 *
 * @internal
 */
final class InlinedViews extends Part
{
    /**
     * The markers @nitroScope({...}) ... @endnitroScope: an inlined view begins and ends (a
     * component's view: it begins); @nitroSlot() ... @endnitroSlot: a slot's content.
     */
    public function inlined(string $name, ?string $args): void
    {
        $context = $this->context;

        switch ($name) {
            case 'nitroScope':
                $frame = json_decode((string) base64_decode((string) $args), true, 16, JSON_THROW_ON_ERROR);
                $vars = [];

                /** What the view is given is read where it is included: through the frames around it. */
                foreach ($frame['vars'] as $var => $php) {
                    $vars[$var] = $this->parseReplacement($php);
                }

                $context->frames[] = ['vars' => $vars, 'isolated' => $frame['isolated'], 'label' => $frame['label'], 'mark' => count($context->locals), 'imports' => $frame['imports'] ?? ''];

                break;
            case 'endnitroScope':
                array_pop($context->frames);
                /** What a loop left set in the view that ends is that view's (PHP's scope of an included view). */
                $context->left = array_filter($context->left, fn (array $left) => $left['depth'] <= count($context->frames));

                break;
            case 'nitroSlot':
                $context->slotText[] = strlen($context->text);

                break;
            case 'endnitroSlot':
                /** Blade trims what a slot renders: its trailing whitespace goes. */
                $start = array_pop($context->slotText) ?? 0;
                $context->text = substr($context->text, 0, $start).rtrim(substr($context->text, $start));

                break;
        }

        /** What is read from here on is written in the innermost view: its own imports (a file's, as in Blade). */
        $context->transpiler->imports = $context->frames === [] ? $context->viewImports : $context->frames[array_key_last($context->frames)]['imports'];
    }

    /**
     * An inlined view's variable: kept when the view declared it (a loop's, which hides anything
     * of that name it was given, as in PHP), else what it was given, kept (one of the template's
     * own, or, in an included view, one of the view around it), or an error (a component sees
     * only its own).
     */
    public function replacement(string $name): ?Expr
    {
        $context = $this->context;

        for ($i = count($context->frames) - 1; $i >= 0; $i--) {
            $frame = $context->frames[$i];

            if (in_array($name, array_slice($context->locals, $frame['mark']), true)) {
                return null;
            }

            if (array_key_exists($name, $frame['vars'])) {
                return $frame['vars'][$name];
            }

            if ($frame['isolated']) {
                if ($context->inHandler || $name === 'errors') {
                    return null;
                }

                /** @each's view sees $key and its item; a component its own. */
                throw $context->error(match (true) {
                    str_starts_with($frame['label'], '@each') => "{$frame['label']} has no \${$name}: the view @each renders sees only \$key and the item",
                    $name === 'this' => "{$frame['label']}: a Blade component has no \$this (it renders on its own); give it what it needs as attributes",
                    default => "{$frame['label']} has no \${$name}: a Blade component sees its @props, its attributes and its slots, so pass \${$name} as an attribute",
                });
            }
        }

        return null;
    }

    /**
     * Which view gives a variable the value it reads (replacement()'s frames, innermost first):
     * how many frames are open with that one (1: the outermost), or null when no frame gives it
     * (the view's own variable, or one of the template's).
     */
    public function givenAt(string $name): ?int
    {
        $context = $this->context;

        for ($i = count($context->frames) - 1; $i >= 0; $i--) {
            $frame = $context->frames[$i];

            if (in_array($name, array_slice($context->locals, $frame['mark']), true)) {
                return null;
            }

            if (array_key_exists($name, $frame['vars'])) {
                return $i + 1;
            }

            if ($frame['isolated']) {
                return null;
            }
        }

        return null;
    }

    /**
     * The marker @nitroAttr('name', $value): a component's attribute, as ComponentAttributeBag prints it:
     * left out when its value is null or false, its name for true, else its trimmed value (the
     * bag's values are as the server writes them in the HTML: the parser's decoding is done here).
     */
    public function componentAttribute(string $args): void
    {
        $context = $this->context;
        $table = $this->compiler->expressionTable;

        if (! preg_match('/^\s*\'((?:[^\'\\\\]|\\\\.)*)\'\s*,(.*)$/s', $args, $match)) {
            throw $context->error('Malformed component attribute');
        }

        $name = str_replace(["\\'", '\\\\'], ["'", '\\'], $match[1]);
        $code = trim($match[2]);
        $true = $name === 'x-data' || str_starts_with($name, 'wire:') ? "''" : var_export($name, true);

        /** The value taken once by an arrow function: copied into each check, it would be compiled once per check. */
        $value = "(fn (\$__value) => html_entity_decode(trim((string) (\$__value === true ? {$true} : \$__value)), ENT_QUOTES))({$code})";

        /** The server prints it as ComponentAttributeBag::__toString() does: as it is, trimmed, " as \". */
        $printed = "(fn (\$__value) => str_replace('\"', '\\\\\"', trim((string) (\$__value === true ? {$true} : \$__value))))({$code})";

        $context->tag->attributes[] = [
            'c' => $name,
            'when' => $table->condition("(fn (\$__value) => \$__value !== null && \$__value !== false)({$code})"),
            'h' => [['e' => 1, 'code' => $table->expression('P.toStr('.$table->expr($value).')', $table->php($printed))]],
        ];
    }

    /**
     * A value given to an inlined view, as PHP, read through the frames around it and the view's
     * imports (@use), as the Transpiler parses the view's own expressions.
     */
    private function parseReplacement(string $php): Expr
    {
        return $this->context->transpiler->parseExpression($php, $this->context->line);
    }
}
