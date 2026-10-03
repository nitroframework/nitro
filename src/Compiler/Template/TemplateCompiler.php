<?php

namespace Nitro\Fusion\Compiler\Template;

use Closure;
use Nitro\Fusion\Compiler\CompileException;
use Nitro\Fusion\Compiler\Globals;
use Nitro\Fusion\Compiler\Scope;
use Nitro\Fusion\Compiler\Template\Parts\Blocks;
use Nitro\Fusion\Compiler\Template\Parts\ChildComponents;
use Nitro\Fusion\Compiler\Template\Parts\Context;
use Nitro\Fusion\Compiler\Template\Parts\Directives;
use Nitro\Fusion\Compiler\Template\Parts\ExpressionTable;
use Nitro\Fusion\Compiler\Template\Parts\FusionAttributes;
use Nitro\Fusion\Compiler\Template\Parts\HtmlScanner;
use Nitro\Fusion\Compiler\Template\Parts\InlinedViews;
use Nitro\Fusion\Compiler\Template\Parts\StaticParts;
use Nitro\Fusion\Compiler\Template\Parts\Syntax;
use Nitro\Fusion\Compiler\Template\Parts\Tree;
use Nitro\Fusion\Compiler\Transpiler;

/**
 * Compiles a component's Blade view the way Viewi compiles its templates: the markup becomes a
 * tree of nodes (data), and every dynamic part an entry of an expression table (code):
 *
 *   template: {t: "r", h: [{t: "t", c: "span", a: [{c: "class", h: [{c: "badge "}, {e: 1, code: 0}]}], h: [...]}]},
 *   expressions: [function ($this) { return P.toStr($this.tone()); }, ...]
 *
 * Blade's blocks are template nodes carrying their directive (@if: {c: "if", code}, @foreach:
 * {c: "foreach", forData, key, item, loop}, ...). An expression gets the component and the
 * template's variables in scope ($item, $loop, ...) as arguments, by position. The browser
 * builds the DOM from the tree (runtime/render.js); the server renders the same view with Blade,
 * and the tree yields the same DOM.
 *
 * Only what can render in the browser is accepted: echoes, the control-flow directives, the
 * attribute directives (@class, @checked, ...), @error, @csrf, @json, fusion: attributes and
 * <fusion:child /> tags. Anything else is a CompileException naming the file and line, and so is
 * markup a tree cannot hold: an element opened inside @if or @foreach and closed outside it.
 *
 * This class is the front door: compile() reads the view's tokens (Lexer) and sends each to its
 * part, then fragment() writes the result. The parts, in Template/Parts:
 *
 *  - HTML: HtmlScanner (the state machine reading the markup), Tree (the open stack and the
 *    nodes it builds)
 *  - Blade: Directives (each @directive where it belongs), Blocks (loops, @switch, @break,
 *    @error, @teleport), InlinedViews (@include and <x-component>, expanded by BladeInliner)
 *  - Fusion: ExpressionTable (the numbered expressions and what each reads: fine-grained
 *    rendering), FusionAttributes (fusion:click, fusion:model, ...), ChildComponents
 *    (<fusion:child>, <fusion:slot>)
 *  - Syntax: what a view may contain; Context: the state the parts share while compiling a view
 *
 * Each part's docblock says what it does and whether it is HTML, Blade or Fusion.
 */
final class TemplateCompiler
{
    /** @var list<string> component methods the template calls (for the compiler's reachability); bound to Context */
    public array $calledMethods = [];

    private readonly Context $context;

    public readonly Tree $tree;

    public readonly ExpressionTable $expressionTable;

    public readonly HtmlScanner $html;

    public readonly Directives $directives;

    public readonly Blocks $blocks;

    public readonly InlinedViews $inlinedViews;

    public readonly FusionAttributes $fusionAttributes;

    public readonly ChildComponents $childComponents;

    /**
     * @param  Closure(string): bool  $isBladeDirective
     * @param  bool  $cloneStatic  clone mode (fusion.rendering = 'clone'): the static parts are marked for the browser to copy (StaticParts)
     */
    public function __construct(private readonly Closure $isBladeDirective, private readonly bool $cloneStatic = false)
    {
        $this->context = new Context;

        /** The ComponentCompiler reads this on the TemplateCompiler; the parts, on the Context. */
        $this->calledMethods = &$this->context->calledMethods;

        $this->tree = new Tree($this, $this->context);
        $this->expressionTable = new ExpressionTable($this, $this->context);
        $this->html = new HtmlScanner($this, $this->context);
        $this->directives = new Directives($this, $this->context);
        $this->blocks = new Blocks($this, $this->context);
        $this->inlinedViews = new InlinedViews($this, $this->context);
        $this->fusionAttributes = new FusionAttributes($this, $this->context);
        $this->childComponents = new ChildComponents($this, $this->context);
    }

    /** "template: {...},\nexpressions: [...]": the render half of a component's definition. */
    public function compile(string $source, Scope $scope, ?string $file = null): string
    {
        $c = $this->context;
        $c->scope = $scope->withVariables($this->resolver(), $file);
        $c->file = $file;
        $c->transpiler = new Transpiler($c->scope);
        $c->expressions = [];
        $c->expressionSources = [];
        $c->sources = [];
        $c->locals = [];
        $c->text = '';
        $c->children = 0;
        $c->eatNewline = false;
        $c->state = 'data';
        $c->tag = null;
        $c->calledMethods = [];
        $c->frames = [];
        $c->frameParents = [];
        $c->setAside = [];
        $c->lineMap = [];
        $c->inHandler = false;
        $c->slotText = [];

        /** @include and <x-components>: inlined (BladeInliner), their variables replaced as they are parsed. */
        if (BladeInliner::needed($source)) {
            [$source, $c->lineMap] = (new BladeInliner(app('view'), new ComponentTags(app('blade.compiler'))))->expand($source, $file);
            $c->transpiler->substitute = fn (string $name) => $this->inlinedViews->replacement($name);
        }

        try {
            return $this->read($source, $file);
        } catch (CompileException $e) {
            /** A line of the inlined source is reported where it came from. */
            $origin = $e->sourceFile === $file && $e->sourceLine !== null ? ($c->lineMap[$e->sourceLine - 1] ?? null) : null;

            throw $origin === null ? $e : new CompileException($e->reason, $origin[0], $origin[1]);
        }
    }

    /**
     * The compiled template: the tree as an object literal (readable), or as a JSON string the
     * browser parses (JSON.parse reads a large tree faster than the same object as code; Viewi
     * ships its templates so), then the expression table.
     */
    public function fragment(bool $parse = false): string
    {
        $tree = $parse
            ? 'JSON.parse('.self::jsString(json_encode($this->context->tree, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR)).')'
            : $this->context->json($this->context->tree);

        return 'template: '.$tree.",\nexpressions: [\n".implode(",\n", $this->context->expressions)."\n]";
    }

    /**
     * What the template's Laravel helpers name: route names, translation keys, config keys.
     *
     * @return array{routes: array<string, true>, lang: array<string, true>, config: array<string, true>, functions: array<string, true>}
     */
    public function uses(): array
    {
        return $this->context->transpiler->uses;
    }

    /**
     * The expressions compile() put in the table, by index.
     *
     * @return list<string>
     */
    public function expressions(): array
    {
        return $this->context->expressions;
    }

    /**
     * What each expression's template variables come from, by expression: the expressions (by
     * index) and keys ("$errors") whose values they are.
     *
     * @return list<list<int|string>>
     */
    public function expressionSources(): array
    {
        return $this->context->expressionSources;
    }

    /** The view's tokens, each sent to its part; then the checks that everything opened was closed. */
    private function read(string $source, ?string $file): string
    {
        $c = $this->context;
        $root = new TemplateNode(['t' => 'r']);
        $c->stack = [['kind' => 'root', 'node' => $root, 'line' => 1, 'locals' => []]];

        $lexer = new Lexer(fn (string $name) => in_array($name, Syntax::DIRECTIVES, true) || ($this->isBladeDirective)($name), $file);

        foreach ($lexer->tokenize($source) as $token) {
            $c->line = $token->line;

            match ($token->kind) {
                Token::TEXT, Token::VERBATIM => $this->html->textToken($token->value),
                Token::ECHO => $this->expressionTable->echo($token->value, false),
                Token::RAW => $this->expressionTable->echo($token->value, true),
                Token::DIRECTIVE => $this->directives->directive($token),
                default => throw $c->error("Unknown token {$token->kind}"),
            };

            $c->eatNewline = $token->kind === Token::DIRECTIVE && ! in_array($token->value, Syntax::KEEPS_NEWLINE, true);
        }

        if ($c->state === 'tag' || $c->state === 'value') {
            throw new CompileException('A tag is not closed (missing ">")', $file, $c->tagLine);
        }

        $this->tree->flushText();

        if (count($c->stack) > 1) {
            $open = end($c->stack);
            $what = match ($open['kind']) {
                'element' => "<{$open['tag']}>",
                'child' => "<fusion:{$open['tag']}>",
                'slot' => "<fusion:slot name=\"{$open['name']}\">",
                default => "@{$open['kind']}",
            };

            throw new CompileException("{$what} is not closed", $file, $open['line']);
        }

        $c->tree = $root->toArray();

        /** Clone mode: the static parts marked, for the browser to copy rather than create. */
        if ($this->cloneStatic) {
            $c->tree = StaticParts::mark($c->tree);
        }

        return $this->fragment();
    }

    /** A JavaScript string literal (in single quotes). */
    private static function jsString(string $value): string
    {
        return "'".strtr($value, ['\\' => '\\\\', "'" => "\\'", "\n" => '\n', "\r" => '\r', "\u{2028}" => ' ', "\u{2029}" => ' '])."'";
    }

    /** How a variable resolves in a template: a local ($item, $loop), a Fusion global, a public property. */
    private function resolver(): Closure
    {
        return function (string $name): ?string {
            if (in_array($name, $this->context->locals, true)) {
                return '$'.$name;
            }

            if (isset(Globals::TEMPLATE_VARIABLES[$name])) {
                return Globals::TEMPLATE_VARIABLES[$name];
            }

            return $this->context->scope->isProperty($name) ? '$this.'.$this->context->scope->browserProperty($name) : null;
        };
    }
}
