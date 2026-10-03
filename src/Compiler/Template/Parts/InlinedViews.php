<?php

namespace Nitro\Fusion\Compiler\Template\Parts;

use Nitro\Fusion\Compiler\Template\VariableSubstitution;
use PhpParser\Node\Expr;
use PhpParser\Node\Stmt;
use PhpParser\ParserFactory;
use PhpParser\PhpVersion;

/**
 * Views inlined into the component's view: Blade's @include and <x-component>, as Fusion compiles
 * them. BladeInliner expands them before the view is read, and marks where each one begins and
 * ends with directives of its own (@fusionScope, @fusionSlot, @fusionFrame, @fusionAttr); this
 * part reads those markers.
 *
 *  - frames: each inlined view is a frame with the variables it was given (parsed as PHP, read
 *    through the frames around it); an @include sees the variables around it, a Blade component
 *    is isolated and sees only its @props, attributes and slots, as in Blade
 *  - a variable in an inlined view is replaced as the Transpiler parses it (replacement()): by
 *    what the view was given, kept if it is the view's own, or refused in a component
 *  - a component's slot is compiled where its tag is, with the frames around the tag, and its
 *    trailing whitespace trimmed, as Blade trims a slot's output
 *  - @fusionAttr: a component's attribute, written as Laravel's ComponentAttributeBag prints it
 *
 * Blade's semantics, implemented by Fusion; the markers are Fusion's own and never written in a
 * view.
 *
 * @internal
 */
final class InlinedViews extends Part
{
    /**
     * The markers @fusionScope({...}) ... @endfusionScope: an inlined view begins and ends;
     * the markers @fusionSlot(id) ... @endfusionSlot (and @fusionFrame in a tag): what is read there belongs where the
     * component's tag is, so its frames are those around the component.
     */
    public function inlined(string $name, ?string $args): void
    {
        $c = $this->context;

        switch ($name) {
            case 'fusionScope':
                $frame = json_decode((string) base64_decode((string) $args), true, 16, JSON_THROW_ON_ERROR);
                $vars = [];

                /** What the view is given is read where it is included: through the frames around it. */
                foreach ($frame['vars'] as $var => $php) {
                    $vars[$var] = $this->parseReplacement($php);
                }

                $c->frameParents[$frame['id']] = $c->frames;
                $c->frames[] = ['id' => $frame['id'], 'vars' => $vars, 'isolated' => $frame['isolated'], 'label' => $frame['label'], 'mark' => count($c->locals)];

                break;
            case 'endfusionScope':
                array_pop($c->frames);

                break;
            case 'fusionSlot':
            case 'fusionFrame':
                $c->setAside[] = $c->frames;
                $c->frames = $c->frameParents[(int) $args] ?? [];

                if ($name === 'fusionSlot') {
                    $c->slotText[] = strlen($c->text);
                }

                break;
            case 'endfusionSlot':
            case 'endfusionFrame':
                $c->frames = array_pop($c->setAside) ?? [];

                /** Blade trims what a slot renders: its trailing whitespace (a component's last newline) goes. */
                if ($name === 'endfusionSlot') {
                    $start = array_pop($c->slotText) ?? 0;
                    $c->text = substr($c->text, 0, $start).rtrim(substr($c->text, $start));
                }

                break;
        }
    }

    /**
     * An inlined view's variable: what it was given, kept (one of the template's own, or, in an
     * included view, one of the view around it), or an error (a component sees only its own).
     */
    public function replacement(string $name): ?Expr
    {
        $c = $this->context;

        for ($i = count($c->frames) - 1; $i >= 0; $i--) {
            $frame = $c->frames[$i];

            if (array_key_exists($name, $frame['vars'])) {
                return $frame['vars'][$name];
            }

            if (in_array($name, array_slice($c->locals, $frame['mark']), true)) {
                return null;
            }

            if ($frame['isolated']) {
                if ($c->inHandler || $name === 'errors') {
                    return null;
                }

                throw $c->error($name === 'this'
                    ? "{$frame['label']}: a Blade component has no \$this (it renders on its own); give it what it needs as attributes"
                    : "{$frame['label']} has no \${$name}: a Blade component sees its @props, its attributes and its slots, so pass \${$name} as an attribute");
            }
        }

        return null;
    }

    /**
     * The marker @fusionAttr('name', $value): a component's attribute, as ComponentAttributeBag prints it:
     * left out when its value is null or false, its name for true, else its trimmed value (the
     * bag's values are as the server writes them in the HTML: the parser's decoding is done here).
     */
    public function componentAttribute(string $args): void
    {
        $c = $this->context;
        $table = $this->compiler->expressionTable;

        if (! preg_match('/^\s*\'((?:[^\'\\\\]|\\\\.)*)\'\s*,(.*)$/s', $args, $m)) {
            throw $c->error('Malformed component attribute');
        }

        $name = str_replace(["\\'", '\\\\'], ["'", '\\'], $m[1]);
        $code = trim($m[2]);
        $true = $name === 'x-data' || str_starts_with($name, 'wire:') ? "''" : var_export($name, true);
        $value = "html_entity_decode(trim((string) (({$code}) === true ? {$true} : ({$code}))), ENT_QUOTES)";

        $c->tag->attributes[] = [
            'c' => $name,
            'when' => $table->condition("({$code}) !== null && ({$code}) !== false"),
            'h' => [['e' => 1, 'code' => $table->expression('P.toStr('.$table->expr($value).')')]],
        ];
    }

    /** A value given to an inlined view, as PHP, read through the frames around it. */
    private function parseReplacement(string $php): Expr
    {
        try {
            $stmts = (new ParserFactory)->createForVersion(PhpVersion::fromComponents(8, 3))->parse('<?php ('.$php.');') ?? [];
        } catch (\PhpParser\Error $e) {
            throw $this->context->error('Invalid PHP: '.$e->getRawMessage());
        }

        $stmts = VariableSubstitution::apply($stmts, fn (string $name) => $this->replacement($name));
        $stmt = $stmts[0] ?? null;

        if (! $stmt instanceof Stmt\Expression) {
            throw $this->context->error('Expected an expression: '.$php);
        }

        return $stmt->expr;
    }
}
