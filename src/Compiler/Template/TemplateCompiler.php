<?php

namespace Nitro\Compiler\Template;

use Closure;
use Illuminate\View\Compilers\BladeCompiler;
use Nitro\Compiler\BrowserClasses;
use Nitro\Compiler\CompileException;
use Nitro\Compiler\Globals;
use Nitro\Compiler\Scope;
use Nitro\Compiler\Template\Parts\Blocks;
use Nitro\Compiler\Template\Parts\ChildComponents;
use Nitro\Compiler\Template\Parts\ComponentCalls;
use Nitro\Compiler\Template\Parts\Context;
use Nitro\Compiler\Template\Parts\Directives;
use Nitro\Compiler\Template\Parts\ExpressionTable;
use Nitro\Compiler\Template\Parts\NitroAttributes;
use Nitro\Compiler\Template\Parts\HtmlScanner;
use Nitro\Compiler\Template\Parts\InlinedViews;
use Nitro\Compiler\Template\Parts\Nesting;
use Nitro\Compiler\Template\Parts\StaticParts;
use Nitro\Compiler\Template\Parts\Syntax;
use Nitro\Compiler\Template\Parts\Tree;
use Nitro\Compiler\Transpiler;

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
 * builds the DOM from the tree (runtime/view/view.js); the server renders the same view with Blade,
 * and the tree yields the same DOM.
 *
 * Only what can render in the browser is accepted: echoes, the control-flow directives, the
 * attribute directives (@class, @checked, ...), @error, @csrf, @json, nitro: attributes and
 * <nitro:child /> tags. Anything else is a CompileException naming the file and line, and so is
 * markup a tree cannot hold: an element opened inside @if or @foreach and closed outside it.
 *
 * This class is the front door: compile() reads the view's tokens (Lexer) and sends each to its
 * part, then fragment() writes the result. The parts, in Template/Parts:
 *
 *  - HTML: HtmlScanner (the state machine reading the markup), Tree (the open stack and the
 *    nodes it builds)
 *  - Blade: Directives (each @directive where it belongs), Blocks (loops, @switch, @break,
 *    @error, @teleport), InlinedViews (@include and <x-component>, expanded by BladeInliner)
 *  - Nitro: ExpressionTable (the numbered expressions and what each reads: fine-grained
 *    rendering), NitroAttributes (nitro:click, nitro:model, ...), ChildComponents
 *    (<nitro:child>, <nitro:slot>)
 *  - Syntax: what a view may contain; Context: the state the parts share while compiling a view
 *
 * Each part's docblock says what it does and whether it is HTML, Blade or Nitro.
 */
final class TemplateCompiler
{
    /** @var list<string> component methods the template calls (for the compiler's reachability); bound to Context */
    public array $calledMethods = [];

    /**
     * Whether the view runs JavaScript of the app's own on the component (a nitro: attribute of
     * nitro.attributes, its Nitro.directive()): that code reaches it by the PHP's names, so the
     * component's definition carries them.
     */
    public bool $runsAppScript = false;

    /** @var list<string> the view's @nitroScript blocks: JavaScript, run once for each instance as it goes live */
    public array $scripts = [];

    /** A Blade component's view ending a slot (ComponentViews): its end is trimmed where it renders, as Blade trims a slot. */
    public bool $endsTrimmed = false;

    /** A Blade component's view (ComponentViews): what is open above its top is its caller's (Nesting). */
    public bool $componentView = false;

    private readonly Context $context;

    public readonly Tree $tree;

    public readonly ExpressionTable $expressionTable;

    public readonly HtmlScanner $html;

    public readonly Directives $directives;

    public readonly Blocks $blocks;

    public readonly InlinedViews $inlinedViews;

    public readonly NitroAttributes $nitroAttributes;

    public readonly ChildComponents $childComponents;

    public readonly ComponentCalls $componentCalls;

    public readonly Nesting $nesting;

    /**
     * @param  Closure(string): bool  $isBladeDirective
     * @param  bool  $cloneStatic  clone mode (nitro.rendering = 'clone'): the static parts are marked for the browser to copy (StaticParts)
     * @param  list<string>  $attributes  the application's own nitro: attributes (nitro.attributes, checked by Globals::customAttributes())
     */
    public function __construct(
        private readonly Closure $isBladeDirective,
        private readonly bool $cloneStatic = false,
        public readonly array $attributes = [],
    ) {
        $this->context = new Context;

        /** The ComponentCompiler reads this on the TemplateCompiler; the parts, on the Context. */
        $this->calledMethods = &$this->context->calledMethods;

        $this->tree = new Tree($this, $this->context);
        $this->expressionTable = new ExpressionTable($this, $this->context);
        $this->html = new HtmlScanner($this, $this->context);
        $this->directives = new Directives($this, $this->context);
        $this->blocks = new Blocks($this, $this->context);
        $this->inlinedViews = new InlinedViews($this, $this->context);
        $this->nitroAttributes = new NitroAttributes($this, $this->context);
        $this->childComponents = new ChildComponents($this, $this->context);
        $this->componentCalls = new ComponentCalls($this, $this->context);
        $this->nesting = new Nesting($this, $this->context);
    }

    /** A compiler as this one (its directives, rendering mode, the app's attributes), for another view: a Blade component's (ComponentViews). */
    public function sibling(): self
    {
        return new self($this->isBladeDirective, $this->cloneStatic, $this->attributes);
    }

    /**
     * The Blade components' views the view calls, by ComponentViews' ids.
     *
     * @return list<string>
     */
    public function calls(): array
    {
        return $this->context->calls;
    }

    /**
     * What a Blade component's view the view calls uses, used as the view's own (the bundle
     * ships what its components' views need).
     *
     * @param  array<string, array<string, true>>  $uses
     */
    public function use(array $uses): void
    {
        foreach ($uses as $kind => $names) {
            $this->context->transpiler->uses[$kind] = ($this->context->transpiler->uses[$kind] ?? []) + $names;
        }
    }

    /**
     * A Blade component's view: what its callers decide of its elements' nesting (Nesting).
     *
     * @return array{reach: list<array{0: string, 1: list<array{0: string, 1: ?int}>, 2: string}>, slots: array<string, list<list<array{0: string, 1: ?int}>>>}
     */
    public function nesting(): array
    {
        return ['reach' => $this->context->nestingReach, 'slots' => $this->context->slotPlaces];
    }

    /**
     * Whether the view, or a Blade component's view it calls, reads an error's field known as the
     * page runs (an obfuscated build): the component's definition then carries its properties'
     * browser names (ComponentAccess::errorArguments(), $this.$field()).
     */
    public function needsNames(): bool
    {
        return $this->context->transpiler->needsNames;
    }

    /** A Blade component's view it calls needs the names (ComponentViews). */
    public function callsNeedingNames(): void
    {
        $this->context->transpiler->needsNames = true;
    }

    /** The scope the view is compiled in (a Blade component's view called from it takes the build's names from it). */
    public function scope(): Scope
    {
        return $this->context->scope;
    }

    /** "template: {...},\nexpressions: [...]": the render half of a component's definition. */
    public function compile(string $source, Scope $scope, ?string $file = null): string
    {
        $context = $this->context;
        $context->scope = $scope->withVariables($this->resolver(), $file);
        $context->file = $file;
        $context->transpiler = new Transpiler($context->scope);
        $context->expressions = [];
        $context->phpExpressions = [];
        $context->expressionSources = [];
        $context->sources = [];
        $context->locals = [];
        $context->text = '';
        $context->children = 0;
        $context->eatNewline = false;
        $context->state = 'data';
        $context->tag = null;
        $context->calledMethods = [];
        $context->frames = [];
        $context->lineMap = [];
        $context->inHandler = false;
        $context->slotText = [];
        $context->calls = [];
        $context->left = [];
        $context->nestingReach = [];
        $context->slotPlaces = [];
        $context->slotContent = [];
        $context->slotPassed = [];

        /** The view's use directives, as the PHP use statements Blade compiles them to: its own (an inlined view has its own, InlinedViews). */
        $context->viewImports = self::imports(ComponentParts::setAside($source)[0]);
        $context->transpiler->imports = $context->viewImports;

        /** @include: inlined; <x-components>: calls of their views (BladeInliner). */
        if (BladeInliner::needed($source)) {
            [$source, $context->lineMap] = (new BladeInliner(app('view'), new ComponentTags(app('blade.compiler')), $this, app(ComponentViews::class), app()->bound(SharedAtBoot::class) ? app(SharedAtBoot::class)->names : []))->expand($source, $file, $this->endsTrimmed);
        }

        /** The variables of an inlined view, and of a Blade component's view (its frame), replaced as they are parsed (one a loop left set is declared before: Blocks::leftRead()). */
        $context->transpiler->substitute = fn (string $name) => $this->inlinedViews->replacement($name);

        try {
            $read = $this->read($source, $file);

            /** A method called on a value is checked later (ComponentCompiler): an inlined line is kept as where it came from. */
            if ($context->lineMap !== []) {
                $classes = [];

                foreach ($context->transpiler->uses['classes'] as $key => $true) {
                    [$class, $method] = BrowserClasses::split($key);
                    [$name, $calledIn, $line] = $class === '*' ? BrowserClasses::calledAt($method) : [null, null, null];
                    $origin = $calledIn === $file && $line !== null ? ($context->lineMap[$line - 1] ?? null) : null;
                    $classes[$origin === null ? $key : BrowserClasses::anyEnum((string) $name, $origin[0], $origin[1])] = $true;
                }

                $context->transpiler->uses['classes'] = $classes;
            }

            return $read;
        } catch (CompileException $exception) {
            /** A line of the inlined source is reported where it came from. */
            $origin = $exception->sourceFile === $file && $exception->sourceLine !== null ? ($context->lineMap[$exception->sourceLine - 1] ?? null) : null;

            throw $origin === null ? $exception : new CompileException($exception->reason, $origin[0], $origin[1]);
        }
    }

    /**
     * A view's use directives, as the PHP use statements Blade compiles them to
     * (CompilesUseStatements). The directive takes a class ('App\Enums\Status'), a class and an
     * alias ('App\Enums\Status', 'S'), a group ('App\Enums\{Status, Priority}'), or a function or
     * a constant ('function App\helpers\money').
     */
    public static function imports(string $source): string
    {
        preg_match_all('/(?<![\w@])@use[ \t]*(\((?:[^()]+|(?1))*\))/', $source, $matches);
        $statements = [];

        foreach ($matches[1] as $expression) {
            $expression = trim((string) preg_replace('/[()]/', '', $expression), " '\"");

            if (str_contains($expression, '{')) {
                [$path, $alias] = [$expression, ''];
            } else {
                $segments = explode(',', $expression);
                $path = trim($segments[0], " '\"");
                $alias = isset($segments[1]) ? ' as '.trim($segments[1], " '\"") : '';
            }

            $modifier = '';

            foreach (['function ', 'const '] as $kind) {
                if (str_starts_with($path, $kind)) {
                    $modifier = $kind;
                    $path = explode(' ', $path, 2)[1];
                }
            }

            $statements[] = 'use '.$modifier.'\\'.ltrim($path, '\\').$alias.';';
        }

        return implode(' ', $statements);
    }

    /**
     * The compiled template: the tree as an object literal (readable), or as a JSON string the
     * browser parses (JSON.parse reads a large tree faster than the same object as code; Viewi
     * ships its templates so), then the expression table.
     */
    public function fragment(bool $parse = false): string
    {
        $browser = Tree::forBrowser($this->context->tree);
        $tree = $parse
            ? 'JSON.parse('.self::jsString(json_encode($browser, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR)).')'
            : $this->context->json($browser);

        return 'template: '.$tree.",\nexpressions: [\n".implode(",\n", $this->context->expressions)."\n]";
    }

    /**
     * What the template's Laravel helpers name: route names, translation keys, config keys.
     *
     * @return array{routes: array<string, true>, lang: array<string, true>, config: array<string, true>, functions: array<string, true>, classes: array<string, true>}
     */
    public function uses(): array
    {
        return $this->context->transpiler->uses;
    }

    /**
     * The expressions' PHP twins, by the same index: what the server's render prints or reads
     * there, with the template variables it takes (by position); null for the browser's only.
     *
     * @return list<array{locals: list<string>, php: string}|null>
     */
    public function phpExpressions(): array
    {
        return $this->context->phpExpressions;
    }

    /** @return array<string, mixed> the node tree compile() made: the fragment's template, with Blade's source of its text (Tree::sourced()) */
    public function tree(): array
    {
        return $this->context->tree;
    }

    /**
     * Whether Blade compiles @name: a built-in directive or one the application registered.
     *
     * @return Closure(string): bool
     */
    public static function bladeDirectives(BladeCompiler $blade): Closure
    {
        return fn (string $name) => method_exists($blade, 'compile'.ucfirst($name)) || array_key_exists($name, $blade->getCustomDirectives());
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

    /**
     * @nitroScript: the component's JavaScript, kept for its definition (a <script> tag around it,
     * for an editor's sake, taken off). Anywhere in the root element but in a block: it runs once
     * for each instance, which an @if or a @foreach would make a question.
     */
    private function script(Token $token): void
    {
        $context = $this->context;

        $elements = array_filter($context->stack, fn (array $open) => $open['kind'] === 'element');

        if ($elements === [] || array_filter($context->stack, fn (array $open) => ! in_array($open['kind'], ['root', 'element'], true)) !== []) {
            throw $context->error('@'.Globals::SCRIPT[0].' goes in the component\'s root element, outside @if, @foreach and the other blocks: it runs once for each instance of the component');
        }

        $code = trim($token->value);

        if (preg_match('#^<script\b[^>]*>(.*)</script>$#is', $code, $tag) === 1) {
            $code = trim($tag[1]);
        }

        $this->scripts[] = $code;
        $this->runsAppScript = true;
    }

    /** The view's tokens, each sent to its part; then the checks that everything opened was closed. */
    private function read(string $source, ?string $file): string
    {
        $context = $this->context;
        $root = new TemplateNode(['t' => 'r']);
        $context->stack = [['kind' => 'root', 'node' => $root, 'line' => 1, 'locals' => []]];

        $lexer = new Lexer(fn (string $name) => in_array($name, Syntax::DIRECTIVES, true) || ($this->isBladeDirective)($name), $file);

        foreach ($lexer->tokenize($source) as $token) {
            $context->line = $token->line;

            match ($token->kind) {
                Token::TEXT, Token::VERBATIM => $this->html->textToken($token->value),
                Token::ECHO => $this->expressionTable->echo($token->value, false),
                Token::RAW => $this->expressionTable->echo($token->value, true),
                Token::DIRECTIVE => $this->directives->directive($token),
                Token::SCRIPT => $this->script($token),
                default => throw $context->error("Unknown token {$token->kind}"),
            };

            $context->eatNewline = $token->kind === Token::DIRECTIVE && ! in_array($token->value, Syntax::KEEPS_NEWLINE, true);
        }

        if ($context->state === 'tag' || $context->state === 'value') {
            throw new CompileException('A tag is not closed (missing ">")', $file, $context->tagLine);
        }

        $this->tree->flushText();
        $this->tree->leaveImplied();

        if (count($context->stack) > 1) {
            $open = $context->stack[array_key_last($context->stack)];
            $what = match ($open['kind']) {
                'element' => "<{$open['tag']}>",
                'child' => "<nitro:{$open['tag']}>",
                'slot' => "<nitro:slot name=\"{$open['name']}\">",
                default => "@{$open['kind']}",
            };

            throw new CompileException("{$what} is not closed", $file, $open['line']);
        }

        $context->tree = $root->toArray();

        /** Clone mode: the static parts marked, for the browser to copy rather than create. */
        if ($this->cloneStatic) {
            $context->tree = StaticParts::mark($context->tree);
        }

        return $this->fragment();
    }

    /** A JavaScript string literal (in single quotes). */
    private static function jsString(string $value): string
    {
        return "'".strtr($value, ['\\' => '\\\\', "'" => "\\'", "\n" => '\n', "\r" => '\r', "\u{2028}" => ' ', "\u{2029}" => ' '])."'";
    }

    /** How a variable resolves in a template: a local ($item, $loop), a Nitro global, a public property. */
    private function resolver(): Closure
    {
        return function (string $name): ?string {
            if (in_array($name, $this->context->locals, true)) {
                return '$'.$name;
            }

            if (isset(Globals::TEMPLATE_VARIABLES[$name])) {
                return Globals::TEMPLATE_VARIABLES[$name];
            }

            /** A property, or a store the component uses ($cart: its members are read through it). */
            return $this->context->scope->isProperty($name) || $this->context->scope->isStore($name) ? '$this.'.$this->context->scope->browserProperty($name) : null;
        };
    }
}
