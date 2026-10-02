<?php

namespace Nitro\Fusion\Compiler\Template;

use Closure;
use Nitro\Fusion\Compiler\CompileException;
use Nitro\Fusion\Compiler\Globals;
use Nitro\Fusion\Compiler\Scope;
use Nitro\Fusion\Compiler\Transpiler;
use PhpParser\Node\Expr;
use PhpParser\Node\Stmt;
use PhpParser\ParserFactory;
use PhpParser\PhpVersion;

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
 */
final class TemplateCompiler
{
    /** Directives compiled here. Every other Blade directive is refused. */
    private const DIRECTIVES = [
        'if', 'elseif', 'else', 'endif', 'unless', 'endunless', 'isset', 'endisset', 'empty', 'endempty',
        'foreach', 'endforeach', 'forelse', 'endforelse', 'for', 'endfor', 'while', 'endwhile',
        'break', 'continue', 'switch', 'case', 'default', 'endswitch', 'error', 'enderror',
        'class', 'style', 'checked', 'selected', 'disabled', 'readonly', 'required', 'csrf', 'method', 'json',
        'teleport', 'endteleport', 'deferred', 'placeholder', 'enddeferred',
        /** BladeInliner's, around an inlined @include or <x-component> (not written in views). */
        'fusionScope', 'endfusionScope', 'fusionSlot', 'endfusionSlot', 'fusionFrame', 'endfusionFrame', 'fusionAttr',
    ];

    /** BladeInliner's directives that sit inside a tag (a component's attributes). */
    private const INLINED_TAG_DIRECTIVES = ['fusionAttr', 'fusionFrame', 'endfusionFrame'];

    /** Directives that write an attribute of the tag they are in. */
    private const ATTRIBUTE_DIRECTIVES = ['class', 'style', 'checked', 'selected', 'disabled', 'readonly', 'required'];

    /** Directives Blade compiles to a statement ending in "?>": PHP drops a newline right after it. */
    private const KEEPS_NEWLINE = ['class', 'style', 'fusionScope', 'fusionSlot', 'endfusionSlot'];

    private const CONDITIONALS = ['if', 'unless', 'isset', 'empty', 'error'];

    /** Elements without content or end tag. */
    private const VOID = ['area', 'base', 'br', 'col', 'embed', 'hr', 'img', 'input', 'link', 'meta', 'source', 'track', 'wbr'];

    /** Raw text: no tags inside, no character references decoded (an echo stays escaped). */
    private const RAW_TEXT = ['script', 'style'];

    /** Text only, character references decoded. */
    private const RCDATA = ['textarea', 'title'];

    /** The HTML parser drops a newline right after their start tag. */
    private const LEADING_NEWLINE = ['pre', 'textarea', 'listing'];

    /** Largest loop a render runs (@for, @while): a template has no way to stop an endless one. */
    private const MAX_ITERATIONS = 100000;

    private Scope $scope;

    private ?string $file;

    private Transpiler $transpiler;

    /** @var list<string> the expression table, as JavaScript function sources */
    private array $expressions = [];

    /** @var array<string, mixed> the node tree compile() made */
    private array $tree = [];

    /** @var list<string> template variables in scope, by argument position ($item, $loop, $message, ...) */
    private array $locals = [];

    /** @var array<string, list<int|string>> what each template variable's value comes from (expressions, keys) */
    private array $sources = [];

    /** @var list<list<int|string>> by expression: what the template variables it reads come from */
    private array $expressionSources = [];

    /**
     * Open elements and blocks, innermost last.
     *
     * @var list<array{kind: string, node: TemplateNode, line: int, locals: list<string>, sources?: array<string, list<int|string>>, loop?: bool, tag?: string, ns?: string, case?: ?TemplateNode, child?: TemplateNode, name?: string}>
     */
    private array $stack = [];

    /** Static text read but not yet made a node. */
    private string $text = '';

    private int $children = 0;

    private bool $eatNewline = false;

    /** HTML scanner state: data, tag, value, comment, raw, rcdata, endtag */
    private string $state = 'data';

    /** The tag being read (its attributes), until its ">". */
    private ?TemplateNode $tag = null;

    private int $tagLine = 1;

    /** The index, among the tag's attributes, of the one whose quoted value is being read. */
    private int $attribute = -1;

    private string $quote = '';

    private string $endTag = '';

    private string $comment = '';

    private int $line = 1;

    /** How far into the current text token $line has counted. */
    private int $scanned = 0;

    /** @var list<string> component methods the template calls (for the compiler's reachability) */
    public array $calledMethods = [];

    /**
     * Inlined views (BladeInliner), innermost last: their variables (parsed), whether they see
     * the variables around them (an include) or not (a component), and how many template
     * variables were declared when the view began (those after are its own).
     *
     * @var list<array{id: int, vars: array<string, Expr>, isolated: bool, label: string, mark: int}>
     */
    private array $frames = [];

    /** @var array<int, list<array{id: int, vars: array<string, Expr>, isolated: bool, label: string, mark: int}>> by frame: the frames around it (its slots compile there) */
    private array $frameParents = [];

    /** @var list<list<array{id: int, vars: array<string, Expr>, isolated: bool, label: string, mark: int}>> frames set aside while a slot compiles */
    private array $setAside = [];

    /** @var list<array{0: ?string, 1: int}> by line of the inlined source: the file and line it came from */
    private array $lineMap = [];

    /** Compiling a handler: $event and the component's methods are the handler's own. */
    private bool $inHandler = false;

    /** @var list<int> by open slot: where its text starts in the text not yet made a node (Blade trims a slot's output) */
    private array $slotText = [];

    /** @param  Closure(string): bool  $isBladeDirective */
    public function __construct(private readonly Closure $isBladeDirective)
    {
    }

    /** "template: {...},\nexpressions: [...]": the render half of a component's definition. */
    public function compile(string $source, Scope $scope, ?string $file = null): string
    {
        $this->scope = $scope->withVariables($this->resolver(), $file);
        $this->file = $file;
        $this->transpiler = new Transpiler($this->scope);
        $this->expressions = [];
        $this->expressionSources = [];
        $this->sources = [];
        $this->locals = [];
        $this->text = '';
        $this->children = 0;
        $this->eatNewline = false;
        $this->state = 'data';
        $this->tag = null;
        $this->calledMethods = [];
        $this->frames = [];
        $this->frameParents = [];
        $this->setAside = [];
        $this->lineMap = [];
        $this->inHandler = false;
        $this->slotText = [];

        /** @include and <x-components>: inlined (BladeInliner), their variables replaced as they are parsed. */
        if (BladeInliner::needed($source)) {
            [$source, $this->lineMap] = (new BladeInliner(app('view'), new ComponentTags(app('blade.compiler'))))->expand($source, $file);
            $this->transpiler->substitute = fn (string $name) => $this->replacement($name);
        }

        try {
            return $this->read($source, $file);
        } catch (CompileException $e) {
            /** A line of the inlined source is reported where it came from. */
            $origin = $e->sourceFile === $file && $e->sourceLine !== null ? ($this->lineMap[$e->sourceLine - 1] ?? null) : null;

            throw $origin === null ? $e : new CompileException($e->reason, $origin[0], $origin[1]);
        }
    }

    private function read(string $source, ?string $file): string
    {
        $root = new TemplateNode(['t' => 'r']);
        $this->stack = [['kind' => 'root', 'node' => $root, 'line' => 1, 'locals' => []]];

        $lexer = new Lexer(fn (string $name) => in_array($name, self::DIRECTIVES, true) || ($this->isBladeDirective)($name), $file);

        foreach ($lexer->tokenize($source) as $token) {
            $this->line = $token->line;

            match ($token->kind) {
                Token::TEXT, Token::VERBATIM => $this->textToken($token->value),
                Token::ECHO => $this->echo($token->value, false),
                Token::RAW => $this->echo($token->value, true),
                Token::DIRECTIVE => $this->directive($token),
                default => throw $this->error("Unknown token {$token->kind}"),
            };

            $this->eatNewline = $token->kind === Token::DIRECTIVE && ! in_array($token->value, self::KEEPS_NEWLINE, true);
        }

        if ($this->state === 'tag' || $this->state === 'value') {
            throw new CompileException('A tag is not closed (missing ">")', $file, $this->tagLine);
        }

        $this->flushText();

        if (count($this->stack) > 1) {
            $open = end($this->stack);
            $what = match ($open['kind']) {
                'element' => "<{$open['tag']}>",
                'child' => "<fusion:{$open['tag']}>",
                'slot' => "<fusion:slot name=\"{$open['name']}\">",
                default => "@{$open['kind']}",
            };

            throw new CompileException("{$what} is not closed", $file, $open['line']);
        }

        $this->tree = $root->toArray();

        return $this->fragment();
    }

    /**
     * The compiled template: the tree as an object literal (readable), or as a JSON string the
     * browser parses (JSON.parse reads a large tree faster than the same object as code; Viewi
     * ships its templates so), then the expression table.
     */
    public function fragment(bool $parse = false): string
    {
        $tree = $parse
            ? 'JSON.parse('.self::jsString(json_encode($this->tree, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR)).')'
            : $this->json($this->tree);

        return 'template: '.$tree.",\nexpressions: [\n".implode(",\n", $this->expressions)."\n]";
    }

    /** A JavaScript string literal (in single quotes). */
    private static function jsString(string $value): string
    {
        return "'".strtr($value, ['\\' => '\\\\', "'" => "\\'", "\n" => '\n', "\r" => '\r', "\u{2028}" => ' ', "\u{2029}" => ' '])."'";
    }

    /**
     * What the template's Laravel helpers name: route names, translation keys, config keys.
     *
     * @return array{routes: array<string, true>, lang: array<string, true>, config: array<string, true>, functions: array<string, true>}
     */
    public function uses(): array
    {
        return $this->transpiler->uses;
    }

    /**
     * The expressions compile() put in the table, by index.
     *
     * @return list<string>
     */
    public function expressions(): array
    {
        return $this->expressions;
    }

    /** How a variable resolves in a template: a local ($item, $loop), a Fusion global, a public property. */
    private function resolver(): Closure
    {
        return function (string $name): ?string {
            if (in_array($name, $this->locals, true)) {
                return '$'.$name;
            }

            if (isset(Globals::TEMPLATE_VARIABLES[$name])) {
                return Globals::TEMPLATE_VARIABLES[$name];
            }

            return $this->scope->isProperty($name) ? '$this.'.$this->scope->browserProperty($name) : null;
        };
    }

    // ------------------------------------------------------------ the tree

    /** Where nodes go now: the innermost open element or block (a @switch: its current @case). */
    private function current(): TemplateNode
    {
        $top = end($this->stack);

        if ($top['kind'] === 'switch') {
            if ($top['case'] === null) {
                throw $this->error('Only @case and @default can follow @switch');
            }

            return $top['case'];
        }

        return $top['node'];
    }

    private function append(TemplateNode $node): void
    {
        $this->flushText();
        $this->current()->children[] = $node;
    }

    /** The static text read so far, as a text node (character references decoded, as the HTML parser does). */
    private function flushText(): void
    {
        if ($this->text === '') {
            return;
        }

        $text = $this->text;
        $this->text = '';
        /** A slot's text read so far is out of the buffer: what the slot adds from now starts at 0. */
        $this->slotText = array_map(fn () => 0, $this->slotText);
        $top = end($this->stack);

        /** Between @switch and its first @case: Blade's PHP, where only whitespace belongs. */
        if ($top['kind'] === 'switch' && $top['case'] === null) {
            if (trim($text) !== '') {
                throw $this->error('Only @case and @default can follow @switch');
            }

            return;
        }

        $parent = $this->current();

        if ($parent->children === [] && in_array($parent->fields['c'] ?? '', self::LEADING_NEWLINE, true) && ($parent->fields['t'] ?? '') === 't') {
            $text = (string) preg_replace('/\A\r?\n/', '', $text);
        }

        if ($this->state !== 'raw') {
            $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }

        if ($text === '') {
            return;
        }

        $last = end($parent->children);

        if ($last !== false && ($last->fields['t'] ?? '') === 'x' && ! isset($last->fields['e'])) {
            $last->fields['c'] .= $text;

            return;
        }

        $parent->children[] = new TemplateNode(['t' => 'x', 'c' => $text]);
    }

    /** The innermost open element (a block is not one). */
    private function element(): ?array
    {
        for ($i = count($this->stack) - 1; $i >= 0; $i--) {
            if ($this->stack[$i]['kind'] === 'element') {
                return $this->stack[$i];
            }
        }

        return null;
    }

    // ------------------------------------------------------------ expressions

    /**
     * Add a JavaScript expression to the table, as a function of the component and the
     * template's variables in scope (by position); returns its index.
     */
    private function expression(string $js): int
    {
        $parameters = implode(', ', ['$this', ...array_map(fn (string $local) => '$'.$local, $this->locals)]);
        $this->expressions[] = "/* ".count($this->expressions)." */ function ({$parameters}) { return {$js}; }";

        /** Viewi's nestedDependencies: an expression reading $item reads what its @foreach reads. */
        $sources = [];

        foreach ($this->locals as $local) {
            if (preg_match('/\$'.preg_quote($local, '/').'\b/', $js) === 1) {
                array_push($sources, ...($this->sources[$local] ?? []));
            }
        }

        $this->expressionSources[] = array_values(array_unique($sources, SORT_REGULAR));

        return count($this->expressions) - 1;
    }

    /**
     * What each expression's template variables come from, by expression: the expressions (by
     * index) and keys ("$errors") whose values they are.
     *
     * @return list<list<int|string>>
     */
    public function expressionSources(): array
    {
        return $this->expressionSources;
    }

    /**
     * Declare a template variable, remembering what its value comes from.
     *
     * @param  list<int|string>  $sources
     */
    private function declareFrom(string $name, array $sources): int
    {
        $this->sources[$name] = $sources;

        return $this->declare($name);
    }

    /** PHP (a Blade expression) compiled to JavaScript. */
    private function expr(string $code): string
    {
        $js = $this->transpiler->templateExpression($code, $this->scope, $this->locals, $this->line);
        $this->track();

        return $js;
    }

    /** A template variable's argument position, declaring it (after those in scope) if it is new. */
    private function declare(string $name): int
    {
        $position = array_search($name, $this->locals, true);

        if ($position === false) {
            $this->locals[] = $name;

            return count($this->locals) - 1;
        }

        return $position;
    }

    private function track(): void
    {
        foreach ($this->transpiler->calledMethods as $method) {
            $this->calledMethods[$method] = $method;
        }

        $this->transpiler->calledMethods = [];
    }

    private function error(string $message): CompileException
    {
        return new CompileException($message, $this->file, $this->line);
    }

    private function json(mixed $value): string
    {
        return json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR);
    }

    // ------------------------------------------------------------ echoes

    /** {{ $x }} (text; the escaping Blade does is what the HTML parser undoes) or {!! $x !!} (HTML). */
    private function echo(string $code, bool $raw): void
    {
        /** {{ $slot }}, {{ $slots['footer'] }}: where the parent's slot content goes. */
        if ($this->state === 'data' && preg_match('/^\s*\$slot(?:s\[\s*([\'"])([\w\-]+)\1\s*\])?\s*$/', $code, $slot) && ! in_array('slot', $this->locals, true)) {
            $this->append(new TemplateNode(['t' => 's', 'c' => $slot[2] ?? 'default']));

            return;
        }

        $value = $this->expr($code);

        match ($this->state) {
            'data' => $this->append(new TemplateNode(['t' => 'x', 'e' => 1, 'code' => $this->expression('P.toStr('.$value.')'), ...($raw ? ['raw' => 1] : [])])),
            /** In <script> and <style> nothing is decoded: the escaped text is the content. */
            'raw' => $this->append(new TemplateNode(['t' => 'x', 'e' => 1, 'code' => $this->expression(($raw ? 'P.toStr(' : 'P.e(').$value.')')])),
            'rcdata' => $this->append(new TemplateNode(['t' => 'x', 'e' => 1, 'code' => $this->expression('P.toStr('.$value.')')])),
            'value' => $this->attributePart(['e' => 1, 'code' => $this->expression('P.toStr('.$value.')')]),
            'tag' => throw $this->error('An echo inside a tag must be an attribute value, such as class="{{ $class }}"'),
            default => throw $this->error('Echoes cannot be used inside an HTML comment or an end tag'),
        };
    }

    // ------------------------------------------------------------ directives

    private function directive(Token $token): void
    {
        $name = $token->value;
        $args = $token->args;

        if (! in_array($name, self::DIRECTIVES, true)) {
            throw $this->error("@{$name} cannot render in the browser, so it is not supported in a Fusion component".
                ' (use a child component or pass the data as a property)');
        }

        if ($this->state === 'tag') {
            $this->tagDirective($name, $args);

            return;
        }

        if (in_array($name, ['fusionScope', 'endfusionScope', 'fusionSlot', 'endfusionSlot'], true)) {
            $this->inlined($name, $args);

            return;
        }

        if ($this->state === 'value') {
            if ($name !== 'json') {
                throw $this->error("@{$name} cannot be used inside an attribute value: use @class, @style or an echo");
            }

            $this->attributePart(['e' => 1, 'code' => $this->expression('P.fns.json_encode('.$this->expr($this->required($name, $args)).', 15)')]);

            return;
        }

        if ($this->state === 'comment' || $this->state === 'endtag') {
            throw $this->error("@{$name} cannot be used inside an HTML comment or an end tag");
        }

        $this->inSwitchBeforeCase($name);

        match ($name) {
            'if' => $this->openBlock('if', ['c' => 'if', 'code' => $this->condition($this->required($name, $args))]),
            'unless' => $this->openBlock('unless', ['c' => 'if', 'code' => $this->expression('!P.bool('.$this->expr($this->required($name, $args)).')')]),
            'isset' => $this->openBlock('isset', ['c' => 'if', 'code' => $this->expression($this->expr('isset('.$this->required($name, $args).')'))]),
            'empty' => $args === null
                ? $this->forelseEmpty()
                : $this->openBlock('empty', ['c' => 'if', 'code' => $this->expression($this->expr('empty('.$args.')'))]),
            'elseif' => $this->branch(['if'], 'elseif', fn () => ['c' => 'else-if', 'code' => $this->condition($this->required($name, $args))]),
            'else' => $this->branch(self::CONDITIONALS, 'else', fn () => ['c' => 'else']),
            'endif' => $this->closeBlock(['if']),
            'endunless' => $this->closeBlock(['unless']),
            'endisset' => $this->closeBlock(['isset']),
            'endempty' => $this->closeBlock(['empty']),
            'foreach' => $this->foreach($this->required($name, $args), false),
            'forelse' => $this->foreach($this->required($name, $args), true),
            'endforeach' => $this->closeBlock(['foreach']),
            'endforelse' => $this->closeBlock(['forelse', 'forelse-empty']),
            'for' => $this->for($this->required($name, $args)),
            'endfor' => $this->closeBlock(['for']),
            'while' => $this->while($this->required($name, $args)),
            'endwhile' => $this->closeBlock(['while']),
            'break', 'continue' => $this->jump($name, $args),
            'switch' => $this->switch($this->required($name, $args)),
            'case' => $this->case(['c' => 'case', 'code' => $this->expression($this->expr($this->required($name, $args)))]),
            'default' => $this->case(['c' => 'default']),
            'endswitch' => $this->closeBlock(['switch']),
            'error' => $this->errorBlock($this->required($name, $args)),
            'enderror' => $this->closeBlock(['error']),
            'deferred' => $this->openBlock('deferred', ['c' => 'if', 'code' => $this->expression('!$this.$deferring('.$this->deferredProperties($args).')')]),
            'placeholder' => $this->branch(['deferred'], 'placeholder', fn () => ['c' => 'else']),
            'enddeferred' => $this->closeBlock(['deferred']),
            'teleport' => $this->teleport($this->required($name, $args)),
            'endteleport' => $this->closeBlock(['teleport']),
            'csrf' => $this->append($this->hiddenInput('_token', $this->expression('B.session.token'), ['autocomplete' => 'off'])),
            'method' => $this->append($this->hiddenInput('_method', $this->expression('P.toStr('.$this->expr($this->required($name, $args)).')'))),
            'json' => $this->append(new TemplateNode(['t' => 'x', 'e' => 1, 'code' => $this->expression('P.fns.json_encode('.$this->expr($this->required($name, $args)).', 15)')])),
            default => throw $this->error("@{$name} belongs inside a tag, such as <div @{$name}(...)>"),
        };
    }

    /** @deferred's properties, by name ('stats', 'chart'): a JSON list, empty for any. */
    private function deferredProperties(?string $args): string
    {
        $args = trim((string) $args);

        if ($args !== '' && ! preg_match('/^([\'"])\w+\1(\s*,\s*([\'"])\w+\3)*$/', $args)) {
            throw $this->error("@deferred names properties as strings: @deferred('stats') or @deferred('stats', 'chart')");
        }

        preg_match_all('/\w+/', $args, $names);

        return json_encode(array_map($this->scope->browserProperty(...), $names[0]), JSON_THROW_ON_ERROR);
    }

    private function required(string $name, ?string $args): string
    {
        if ($args === null || trim($args) === '') {
            throw $this->error("@{$name} needs an expression");
        }

        return $args;
    }

    // ------------------------------------------------------------ inlined views (BladeInliner)

    /**
     * The markers @fusionScope({...}) ... @endfusionScope: an inlined view begins and ends;
     * the markers @fusionSlot(id) ... @endfusionSlot (and @fusionFrame in a tag): what is read there belongs where the
     * component's tag is, so its frames are those around the component.
     */
    private function inlined(string $name, ?string $args): void
    {
        switch ($name) {
            case 'fusionScope':
                $frame = json_decode((string) base64_decode((string) $args), true, 16, JSON_THROW_ON_ERROR);
                $vars = [];

                /** What the view is given is read where it is included: through the frames around it. */
                foreach ($frame['vars'] as $var => $php) {
                    $vars[$var] = $this->parseReplacement($php);
                }

                $this->frameParents[$frame['id']] = $this->frames;
                $this->frames[] = ['id' => $frame['id'], 'vars' => $vars, 'isolated' => $frame['isolated'], 'label' => $frame['label'], 'mark' => count($this->locals)];

                break;
            case 'endfusionScope':
                array_pop($this->frames);

                break;
            case 'fusionSlot':
            case 'fusionFrame':
                $this->setAside[] = $this->frames;
                $this->frames = $this->frameParents[(int) $args] ?? [];

                if ($name === 'fusionSlot') {
                    $this->slotText[] = strlen($this->text);
                }

                break;
            case 'endfusionSlot':
            case 'endfusionFrame':
                $this->frames = array_pop($this->setAside) ?? [];

                /** Blade trims what a slot renders: its trailing whitespace (a component's last newline) goes. */
                if ($name === 'endfusionSlot') {
                    $start = array_pop($this->slotText) ?? 0;
                    $this->text = substr($this->text, 0, $start).rtrim(substr($this->text, $start));
                }

                break;
        }
    }

    /**
     * An inlined view's variable: what it was given, kept (one of the template's own, or, in an
     * included view, one of the view around it), or an error (a component sees only its own).
     */
    private function replacement(string $name): ?Expr
    {
        for ($i = count($this->frames) - 1; $i >= 0; $i--) {
            $frame = $this->frames[$i];

            if (array_key_exists($name, $frame['vars'])) {
                return $frame['vars'][$name];
            }

            if (in_array($name, array_slice($this->locals, $frame['mark']), true)) {
                return null;
            }

            if ($frame['isolated']) {
                if ($this->inHandler || $name === 'errors') {
                    return null;
                }

                throw $this->error($name === 'this'
                    ? "{$frame['label']}: a Blade component has no \$this (it renders on its own); give it what it needs as attributes"
                    : "{$frame['label']} has no \${$name}: a Blade component sees its @props, its attributes and its slots, so pass \${$name} as an attribute");
            }
        }

        return null;
    }

    /** A value given to an inlined view, as PHP, read through the frames around it. */
    private function parseReplacement(string $php): Expr
    {
        try {
            $stmts = (new ParserFactory)->createForVersion(PhpVersion::fromComponents(8, 3))->parse('<?php ('.$php.');') ?? [];
        } catch (\PhpParser\Error $e) {
            throw $this->error('Invalid PHP: '.$e->getRawMessage());
        }

        $stmts = VariableSubstitution::apply($stmts, fn (string $name) => $this->replacement($name));
        $stmt = $stmts[0] ?? null;

        if (! $stmt instanceof Stmt\Expression) {
            throw $this->error('Expected an expression: '.$php);
        }

        return $stmt->expr;
    }

    /**
     * The marker @fusionAttr('name', $value): a component's attribute, as ComponentAttributeBag prints it:
     * left out when its value is null or false, its name for true, else its trimmed value (the
     * bag's values are as the server writes them in the HTML: the parser's decoding is done here).
     */
    private function componentAttribute(string $args): void
    {
        if (! preg_match('/^\s*\'((?:[^\'\\\\]|\\\\.)*)\'\s*,(.*)$/s', $args, $m)) {
            throw $this->error('Malformed component attribute');
        }

        $name = str_replace(["\\'", '\\\\'], ["'", '\\'], $m[1]);
        $code = trim($m[2]);
        $true = $name === 'x-data' || str_starts_with($name, 'wire:') ? "''" : var_export($name, true);
        $value = "html_entity_decode(trim((string) (({$code}) === true ? {$true} : ({$code}))), ENT_QUOTES)";

        $this->tag->attributes[] = [
            'c' => $name,
            'when' => $this->condition("({$code}) !== null && ({$code}) !== false"),
            'h' => [['e' => 1, 'code' => $this->expression('P.toStr('.$this->expr($value).')')]],
        ];
    }

    /** A condition, as PHP reads it, to a JavaScript boolean. */
    private function condition(string $code): int
    {
        return $this->expression('P.bool('.$this->expr($code).')');
    }

    /** @class, @style, @checked, ... inside a tag: attributes of it. */
    private function tagDirective(string $name, ?string $args): void
    {
        if (in_array($name, self::INLINED_TAG_DIRECTIVES, true)) {
            $name === 'fusionAttr' ? $this->componentAttribute((string) $args) : $this->inlined($name, $args);

            return;
        }

        if (! in_array($name, self::ATTRIBUTE_DIRECTIVES, true)) {
            throw $this->error("@{$name} cannot sit inside a tag: wrap the element in it, or use @class, @style, @disabled, @checked, ...");
        }

        $this->tag->attributes[] = match ($name) {
            'class' => ['c' => 'class', 'h' => [['e' => 1, 'code' => $this->expression('B.classes('.$this->expr($args ?? '[]').')')]]],
            'style' => ['c' => 'style', 'h' => [['e' => 1, 'code' => $this->expression('B.styles('.$this->expr($args ?? '[]').')')]]],
            default => ['c' => $name, 'when' => $this->condition($this->required($name, $args))],
        };
    }

    /** @param  array<string, string>  $more  more static attributes */
    private function hiddenInput(string $name, int $value, array $more = []): TemplateNode
    {
        $input = new TemplateNode(['t' => 't', 'c' => 'input']);
        $input->attributes = [
            ['c' => 'type', 'h' => [['c' => 'hidden']]],
            ['c' => 'name', 'h' => [['c' => $name]]],
            ['c' => 'value', 'h' => [['e' => 1, 'code' => $value]]],
        ];

        foreach ($more as $attribute => $static) {
            $input->attributes[] = ['c' => $attribute, 'h' => [['c' => $static]]];
        }

        return $input;
    }

    // ------------------------------------------------------------ blocks

    /**
     * Open a block: a template node carrying its directive (compiled with the variables in scope
     * before the block's own).
     *
     * @param  array<string, mixed>  $directive
     */
    private function openBlock(string $kind, array $directive, bool $loop = false): TemplateNode
    {
        $node = new TemplateNode(['t' => 'template', 'i' => [$directive]]);
        $this->append($node);
        $this->stack[] = ['kind' => $kind, 'node' => $node, 'line' => $this->line, 'locals' => $this->locals, 'sources' => $this->sources, 'loop' => $loop];

        return $node;
    }

    /**
     * @elseif / @else: the open branch ends, the next begins (a sibling template node).
     *
     * @param  list<string>  $kinds
     * @param  Closure(): array<string, mixed>  $directive
     */
    private function branch(array $kinds, string $name, Closure $directive): void
    {
        $this->flushText();
        $top = end($this->stack);

        if (! in_array($top['kind'], $kinds, true)) {
            throw $this->error($top['kind'] === 'element'
                ? "<{$top['tag']}> (line {$top['line']}) must be closed before @{$name}"
                : "@{$name} outside of ".implode('/', array_map(fn ($k) => "@{$k}", $kinds)));
        }

        array_pop($this->stack);
        $this->locals = $top['locals'];
        $this->sources = $top['sources'] ?? $this->sources;

        $node = new TemplateNode(['t' => 'template', 'i' => [$directive()]]);
        $this->current()->children[] = $node;
        $this->stack[] = ['kind' => $top['kind'], 'node' => $node, 'line' => $this->line, 'locals' => $this->locals, 'sources' => $this->sources, 'loop' => false];
    }

    /** @param  list<string>  $kinds */
    private function closeBlock(array $kinds): void
    {
        $this->flushText();
        $top = end($this->stack);

        if ($top['kind'] === 'element') {
            throw $this->error("<{$top['tag']}> (line {$top['line']}) must be closed before @end{$kinds[0]}: open and close an element inside the same block");
        }

        if (! in_array($top['kind'], $kinds, true)) {
            throw $this->error('Unexpected @end'.$kinds[0].($top['kind'] !== 'root' ? " (@{$top['kind']} from line {$top['line']} is still open)" : ''));
        }

        array_pop($this->stack);
        $this->locals = $top['locals'];
        $this->sources = $top['sources'] ?? $this->sources;
    }

    /** Blade's @foreach($items as $key => $item) and @forelse, with $loop. */
    private function foreach(string $args, bool $forelse): void
    {
        if (! preg_match('/^\s*(.+)\s+as\s+(.*?)\s*$/is', $args, $m)) {
            throw $this->error('Malformed @'.($forelse ? 'forelse' : 'foreach').': expected "$items as $item"');
        }

        [, $iteratee, $iteration] = $m;

        if (! preg_match('/^\$(\w+)(?:\s*=>\s*\$(\w+))?$/', trim($iteration), $vars)) {
            throw $this->error('@foreach must name its variables ($item or $key => $item)');
        }

        [$keyVar, $valueVar] = isset($vars[2]) ? [$vars[1], $vars[2]] : [null, $vars[1]];

        $data = $this->expression($this->expr($iteratee));
        $parentLoop = in_array('loop', $this->locals, true);
        /** $loop->parent is the loop around it: its state comes from that loop's data too. */
        $loopSources = [$data, ...($parentLoop ? $this->sources['loop'] ?? [] : [])];
        $node = $this->openBlock($forelse ? 'forelse' : 'foreach', [], true);

        $node->fields['i'] = [array_filter([
            'c' => 'foreach',
            'forData' => $data,
            'key' => $keyVar !== null ? $this->declareFrom($keyVar, [$data]) : null,
            'item' => $this->declareFrom($valueVar, [$data]),
            'loop' => $this->declareFrom('loop', $loopSources),
            'parentLoop' => $parentLoop ? 1 : null,
        ], fn ($v) => $v !== null)];
    }

    /** Blade's @empty inside @forelse: the loop's block ends, the one for no items begins. */
    private function forelseEmpty(): void
    {
        $this->branch(['forelse'], 'empty', fn () => ['c' => 'empty']);
        $this->stack[count($this->stack) - 1]['kind'] = 'forelse-empty';
    }

    /** Blade's @for($i = 0; $i < 10; $i++): its iterations, worked out by the expression. */
    private function for(string $args): void
    {
        [$head, $variables] = $this->transpiler->templateFor($args, $this->scope, $this->locals, $this->line);
        $this->track();
        $declare = $variables === [] ? '' : 'let '.implode(', ', array_map(fn ($v) => '$'.$v, $variables)).'; ';
        $values = '['.implode(', ', array_map(fn ($v) => '$'.$v, $variables)).']';

        $code = $this->expression($this->iterations($declare.$head, $values, '@for'));
        $node = $this->openBlock('for', [], true);
        $node->fields['i'] = [['c' => 'for', 'code' => $code, 'vars' => array_map(fn ($v) => $this->declareFrom($v, [$code]), $variables)]];
    }

    private function while(string $args): void
    {
        $code = $this->expression($this->iterations('while (P.bool('.$this->expr($args).'))', '[]', '@while'));
        $this->openBlock('while', ['c' => 'while', 'code' => $code], true);
    }

    /** A loop's iterations as a list: each the values its variables have in it. */
    private function iterations(string $loop, string $values, string $directive): string
    {
        return "(() => { const \$\$runs = []; {$loop} { \$\$runs.push({$values}); if (\$\$runs.length > ".self::MAX_ITERATIONS.") throw new Error('{$directive} ran more than ".self::MAX_ITERATIONS." times'); } return \$\$runs; })()";
    }

    /** Blade's @break, @break(2), @break($condition); @continue likewise. */
    private function jump(string $keyword, ?string $args): void
    {
        $loops = array_values(array_filter($this->stack, fn ($frame) => $frame['loop'] ?? false));

        if ($loops === []) {
            throw $this->error("@{$keyword} outside of a loop");
        }

        /** Teleported content renders elsewhere: a loop around the @teleport is not around it there. */
        $inner = array_slice($this->stack, (int) array_key_last(array_filter($this->stack, fn ($frame) => $frame['loop'] ?? false)));

        if (in_array('teleport', array_column($inner, 'kind'), true)) {
            throw $this->error("@{$keyword} inside @teleport cannot leave a loop around the @teleport");
        }

        $node = new TemplateNode(['t' => 'b', 'c' => $keyword, 'levels' => 1]);

        if ($args !== null && preg_match('/^\s*(-?\d+)\s*$/', $args, $m)) {
            $levels = max(1, (int) $m[1]);

            if ($levels > count($loops)) {
                throw $this->error("@{$keyword}({$levels}): not inside that many loops");
            }

            $node->fields['levels'] = $levels;
        } else {
            if ($keyword === 'continue' && end($loops)['kind'] === 'switch') {
                throw $this->error('@continue inside @switch: use @break');
            }

            if ($args !== null) {
                $node->fields['code'] = $this->condition($args);
            }
        }

        $this->append($node);
    }

    private function switch(string $args): void
    {
        $this->openBlock('switch', ['c' => 'switch', 'code' => $this->expression($this->expr($args))], true);
        $this->stack[count($this->stack) - 1]['case'] = null;
    }

    /** @param  array<string, mixed>  $directive */
    private function case(array $directive): void
    {
        $this->flushText();
        $top = end($this->stack);

        if ($top['kind'] !== 'switch') {
            throw $this->error('@'.($directive['c'] === 'case' ? 'case' : 'default').' outside of @switch');
        }

        $case = new TemplateNode(['t' => 'template', 'i' => [$directive]]);
        $top['node']->children[] = $case;
        $this->stack[count($this->stack) - 1]['case'] = $case;
    }

    /** Text or directives between @switch and its first @case: Blade's PHP, where they cannot be. */
    private function inSwitchBeforeCase(string $directive): void
    {
        $top = end($this->stack);

        if ($top['kind'] === 'switch' && $top['case'] === null && ! in_array($directive, ['case', 'default', 'endswitch'], true)) {
            throw $this->error('Only @case and @default can follow @switch');
        }
    }

    /** Blade's @error('field'): the component's validation message for the field, as $message. */
    private function errorBlock(string $args): void
    {
        /**
         * A field written out, or one built from a start written out ('items.'.$i.'.price'): its
         * property by the browser's name (an obfuscated build).
         */
        if (preg_match('/^(\s*)([\'"])([\w.*\-]+)\2/', $args, $literal) === 1) {
            $args = $literal[1].var_export($this->scope->browserPath($literal[3]), true).substr($args, strlen($literal[0]));
        }

        $field = $this->expression($this->expr('['.$args.'][0]'));
        $node = $this->openBlock('error', []);
        $node->fields['i'] = [['c' => 'error', 'code' => $field, 'message' => $this->declareFrom('message', [$field, '$errors'])]];
    }

    /**
     * Livewire's @teleport, as Viewi's <Portal to="modals">: the content renders at @portal('modals')
     * (Viewi's <Portal name="modals">), on the server and in the browser (runtime renderTeleport),
     * with this component's state and handlers. The name is a literal, as Viewi's is.
     */
    private function teleport(string $args): void
    {
        if ($this->state !== 'data') {
            throw $this->error('@teleport goes between elements, not inside <script>, <style>, <textarea> or <title>');
        }

        if (! preg_match('/^\s*([\'"])([A-Za-z0-9_-]+)\1\s*$/', $args, $name)) {
            throw $this->error("@teleport takes the name of a @portal, as a string literal: @teleport('modals')");
        }

        if (in_array('teleport', array_column($this->stack, 'kind'), true)) {
            throw $this->error('@teleport cannot be inside another @teleport');
        }

        $node = new TemplateNode(['t' => 'p', 'to' => $name[2]]);
        $this->append($node);
        $this->stack[] = ['kind' => 'teleport', 'node' => $node, 'line' => $this->line, 'locals' => $this->locals, 'sources' => $this->sources, 'loop' => false];
    }

    // ------------------------------------------------------------ HTML

    private function textToken(string $text): void
    {
        if ($this->eatNewline) {
            $text = preg_replace('/\A\r?\n/', '', $text, 1, $eaten);
            $this->line += $eaten;
        }

        $length = strlen($text);
        $i = 0;
        $this->scanned = 0;

        while ($i < $length) {
            $this->lineAt($text, $i);

            $i = match ($this->state) {
                'data' => $this->data($text, $i),
                'tag' => $this->tag($text, $i),
                'value' => $this->value($text, $i),
                'comment' => $this->commentText($text, $i),
                'endtag' => $this->endTagText($text, $i),
                'raw', 'rcdata' => $this->rawText($text, $i),
                default => throw $this->error("Unknown scanner state {$this->state}"),
            };
        }
    }

    /** HTML spans lines: errors and handlers report the line they are on, not the text's first. */
    private function lineAt(string $text, int $offset): void
    {
        if ($offset > $this->scanned) {
            $this->line += substr_count($text, "\n", $this->scanned, $offset - $this->scanned);
            $this->scanned = $offset;
        }
    }

    private function data(string $text, int $i): int
    {
        $lt = strpos($text, '<', $i);

        if ($lt === false) {
            $this->text .= substr($text, $i);

            return strlen($text);
        }

        $this->text .= substr($text, $i, $lt - $i);
        $this->lineAt($text, $lt);

        if (substr_compare($text, '<!--', $lt, 4) === 0) {
            $this->flushText();
            $this->comment = '';
            $this->state = 'comment';

            return $lt + 4;
        }

        if (substr_compare($text, '<fusion:slot', $lt, 12, true) === 0 && preg_match('/\G<fusion:slot[\s\/>]/i', $text, $_, 0, $lt)) {
            return $this->slotOpen($text, $lt);
        }

        if (substr_compare($text, '<fusion:', $lt, 8, true) === 0) {
            return $this->child($text, $lt);
        }

        if (substr_compare($text, '</fusion:', $lt, 9, true) === 0) {
            return $this->childClose($text, $lt);
        }

        if (substr_compare($text, '</', $lt, 2) === 0) {
            $this->flushText();
            $this->endTag = '';
            $this->state = 'endtag';

            return $lt + 2;
        }

        if (preg_match('/\G<([a-zA-Z][a-zA-Z0-9\-]*)/', $text, $m, 0, $lt)) {
            $this->flushText();
            $this->startTag($m[1]);

            return $lt + strlen($m[0]);
        }

        $this->text .= '<';

        return $lt + 1;
    }

    private function startTag(string $name): void
    {
        $parent = $this->element();
        $ns = $parent['ns'] ?? 'html';

        if ($ns === 'html' && in_array(strtolower($name), ['svg', 'math'], true)) {
            $ns = strtolower($name);
        }

        $tag = $ns === 'html' ? strtolower($name) : $name;

        if ($tag === 'tr' && $parent !== null && $parent['tag'] === 'table') {
            throw $this->error('Put the rows of a <table> inside <tbody>, <thead> or <tfoot> (the browser adds a <tbody> when it is missing)');
        }

        $this->tag = new TemplateNode(['t' => 't', 'c' => $tag]);
        $this->tagLine = $this->line;
        $this->state = 'tag';
    }

    /** The tag's ">" (or "/>"): the element opens, unless it is void or a self-closed SVG one. */
    private function finishTag(bool $selfClosing): void
    {
        $node = $this->tag;
        $this->tag = null;
        $this->current()->children[] = $node;

        $tag = $node->fields['c'];
        $parentNs = $this->element()['ns'] ?? 'html';
        $ns = $parentNs === 'html' && in_array($tag, ['svg', 'math'], true) ? $tag : $parentNs;

        if (in_array($tag, self::VOID, true) || ($selfClosing && $ns !== 'html')) {
            $this->state = 'data';

            return;
        }

        /** The children of an SVG <foreignObject> are HTML again. */
        $childNs = $ns === 'svg' && strtolower($tag) === 'foreignobject' ? 'html' : $ns;
        $this->stack[] = ['kind' => 'element', 'node' => $node, 'line' => $this->tagLine, 'locals' => $this->locals, 'tag' => $tag, 'ns' => $childNs];

        $lower = strtolower($tag);
        $this->state = $ns !== 'html' ? 'data' : (in_array($lower, self::RAW_TEXT, true) ? 'raw' : (in_array($lower, self::RCDATA, true) ? 'rcdata' : 'data'));
    }

    private function tag(string $text, int $i): int
    {
        if (preg_match('/\G\s+/', $text, $m, 0, $i)) {
            return $i + strlen($m[0]);
        }

        if (substr_compare($text, '/>', $i, 2) === 0) {
            $this->finishTag(true);

            return $i + 2;
        }

        if ($text[$i] === '>') {
            $this->finishTag(false);

            return $i + 1;
        }

        if (preg_match('/\Gfusion:([\w\-.]+)/', $text, $m, 0, $i)) {
            return $this->fusionAttribute($text, $i, $m[1]);
        }

        if (preg_match('/\G([^\s"\'>\/=]+)(\s*=\s*)?/', $text, $m, 0, $i)) {
            $name = ($this->element()['ns'] ?? 'html') === 'html' && ! in_array($this->tag->fields['c'], ['svg', 'math'], true)
                ? strtolower($m[1])
                : $m[1];
            $i += strlen($m[0]);

            if (($m[2] ?? '') === '') {
                $this->tag->attributes[] = ['c' => $name];

                return $i;
            }

            if ($i < strlen($text) && ($text[$i] === '"' || $text[$i] === "'")) {
                return $this->openValue($name, $text[$i], $i + 1);
            }

            /** An unquoted value runs to whitespace or >. */
            preg_match('/\G[^\s>]*/', $text, $v, 0, $i);
            $this->tag->attributes[] = ['c' => $name, 'h' => [['c' => html_entity_decode($v[0], ENT_QUOTES | ENT_HTML5, 'UTF-8')]]];

            return $i + strlen($v[0]);
        }

        return $i + 1;
    }

    private function openValue(string $name, string $quote, int $i): int
    {
        $this->tag->attributes[] = ['c' => $name, 'h' => []];
        $this->attribute = count($this->tag->attributes) - 1;
        $this->quote = $quote;
        $this->state = 'value';

        return $i;
    }

    private function value(string $text, int $i): int
    {
        $end = strpos($text, $this->quote, $i);
        $static = $end === false ? substr($text, $i) : substr($text, $i, $end - $i);

        if ($static !== '') {
            $decoded = html_entity_decode($static, ENT_QUOTES | ENT_HTML5, 'UTF-8');

            /** fusion:model's property by the browser's name for it: the path's start (an obfuscated build). */
            $attribute = $this->tag->attributes[$this->attribute];

            if ($this->scope->names !== null && ($attribute['c'] === 'fusion:model' || str_starts_with($attribute['c'], 'fusion:model.')) && $attribute['h'] === []) {
                $decoded = $this->scope->browserPath($decoded);
            }

            /** fusion:target's methods, and properties (an upload's), by the browser's names for them (an obfuscated build). */
            if ($this->scope->names !== null && $this->tag->attributes[$this->attribute]['c'] === 'fusion:target') {
                $decoded = implode(',', array_map(fn (string $target) => match (true) {
                    $this->scope->isClientMethod(trim($target)) || $this->scope->isServerMethod(trim($target)) => $this->scope->browserMethod(trim($target)),
                    $this->scope->isProperty(trim($target)) => $this->scope->browserProperty(trim($target)),
                    default => trim($target),
                }, explode(',', $decoded)));
            }

            $this->attributePart(['c' => $decoded]);
        }

        if ($end === false) {
            return strlen($text);
        }

        $this->state = 'tag';

        return $end + 1;
    }

    /** @param  array<string, mixed>  $part  static text ({c}) or an expression ({e, code}) */
    private function attributePart(array $part): void
    {
        $parts = &$this->tag->attributes[$this->attribute]['h'];
        $last = array_key_last($parts);

        if (isset($part['c']) && $last !== null && isset($parts[$last]['c'])) {
            $parts[$last]['c'] .= $part['c'];

            return;
        }

        $parts[] = $part;
    }

    private function commentText(string $text, int $i): int
    {
        $end = strpos($text, '-->', $i);

        if ($end === false) {
            $this->comment .= substr($text, $i);

            return strlen($text);
        }

        $this->comment .= substr($text, $i, $end - $i);
        $this->current()->children[] = new TemplateNode(['t' => 'm', 'c' => $this->comment]);
        $this->state = 'data';

        return $end + 3;
    }

    private function endTagText(string $text, int $i): int
    {
        $end = strpos($text, '>', $i);

        if ($end === false) {
            $this->endTag .= substr($text, $i);

            return strlen($text);
        }

        $this->endTag .= substr($text, $i, $end - $i);
        $this->state = 'data';
        $this->closeElement(trim($this->endTag));

        return $end + 1;
    }

    private function closeElement(string $name): void
    {
        $top = end($this->stack);
        $html = ($top['ns'] ?? 'html') === 'html' || in_array(strtolower($name), ['svg', 'math'], true);
        $tag = $html ? strtolower($name) : $name;

        /** </br>, </input>: nothing to close. */
        if (in_array(strtolower($tag), self::VOID, true)) {
            return;
        }

        if ($top['kind'] !== 'element') {
            throw $this->error($top['kind'] === 'root'
                ? "</{$tag}> closes nothing that is open"
                : "</{$tag}> closes an element opened outside the @{$top['kind']} of line {$top['line']}: open and close an element inside the same block");
        }

        if (strcasecmp($top['tag'], $tag) !== 0) {
            throw $this->error("</{$tag}> does not match <{$top['tag']}> from line {$top['line']}: close every element, in order");
        }

        array_pop($this->stack);
    }

    /** Inside <script>/<style> (raw) or <textarea>/<title>: text up to the end tag. */
    private function rawText(string $text, int $i): int
    {
        $tag = $this->element()['tag'];

        if (! preg_match('/<\/'.preg_quote($tag, '/').'\b/i', $text, $m, PREG_OFFSET_CAPTURE, $i)) {
            $this->text .= substr($text, $i);

            return strlen($text);
        }

        $at = $m[0][1];
        $this->text .= substr($text, $i, $at - $i);
        $this->flushText();
        $this->endTag = '';
        $this->state = 'endtag';

        return $at + 2;
    }

    /**
     * fusion:click.prevent="save": the attribute stays as written (the server renders it that way
     * too), and the node carries its handler (on: {e: event, m: modifiers, code}).
     */
    private function fusionAttribute(string $text, int $i, string $spec): int
    {
        $parts = explode('.', $spec);
        $name = array_shift($parts);

        if (Globals::isAttribute($name)) {
            if ($name === 'model') {
                $this->checkModel($text, $i, $parts);
            }

            if ($name === 'transition') {
                $this->checkTransition($parts);
            }

            foreach (isset(Globals::ATTRIBUTE_MODIFIERS[$name]) ? $parts : [] as $modifier) {
                if (! in_array($modifier, Globals::ATTRIBUTE_MODIFIERS[$name], true)) {
                    throw $this->error("Unknown modifier .{$modifier} on fusion:{$name} (it takes .".implode(', .', Globals::ATTRIBUTE_MODIFIERS[$name]).')');
                }
            }

            /** Passed through: the runtime reads it from the element (its value may contain {{ }}). */
            $i += strlen('fusion:'.$spec);

            if (preg_match('/\G\s*=\s*(["\'])/', $text, $open, 0, $i)) {
                return $this->openValue('fusion:'.$spec, $open[1], $i + strlen($open[0]));
            }

            $this->tag->attributes[] = ['c' => 'fusion:'.$spec];

            return $i;
        }

        $hook = Globals::HOOKS[$name] ?? null;

        if ($hook === null && ! Globals::isEvent($name)) {
            throw $this->error("Unknown attribute fusion:{$name}: use an event (fusion:click, fusion:submit, ...), fusion:poll, fusion:init, fusion:visible or one of fusion:".implode(', fusion:', Globals::ATTRIBUTES));
        }

        foreach ($parts as $modifier) {
            $known = $hook === null
                ? in_array($modifier, Globals::MODIFIERS, true) || preg_match('/^\d+m?s$/', $modifier) === 1
                : in_array($modifier, $hook, true)
                    || ($name === 'poll' && preg_match('/^\d+m?s$/', $modifier) === 1)
                    || ($name === 'visible' && preg_match('/^\d+px$/', $modifier) === 1);

            if (! $known) {
                throw $this->error("Unknown modifier .{$modifier} on fusion:{$name}");
            }
        }

        /** A bare fusion:poll refreshes the component. */
        if ($name === 'poll' && ! preg_match('/\Gfusion:[\w\-.]+\s*=/', $text, $_, 0, $i)) {
            $this->tag->attributes[] = ['c' => 'fusion:'.$spec, 'on' => ['e' => 'poll', 'm' => $parts, 'code' => $this->handler('$refresh')]];

            return $i + strlen('fusion:'.$spec);
        }

        if (! preg_match('/\Gfusion:[\w\-.]+\s*=\s*("([^"]*)"|\'([^\']*)\')/', $text, $m, 0, $i)) {
            /** An opening quote without its closing one here: Blade ({{ }}, @...) split the value. */
            throw $this->error(preg_match('/\Gfusion:[\w\-.]+\s*=\s*["\']/', $text, $open, 0, $i)
                ? "fusion:{$name} takes a method call such as \"remove(\$item['id'])\", without {{ }} or directives"
                : "fusion:{$name} needs a quoted handler, such as fusion:{$name}=\"save\"");
        }

        $code = $m[2] !== '' ? $m[2] : ($m[3] ?? '');

        if (str_contains($code, '{{') || trim($code) === '') {
            throw $this->error("fusion:{$name} takes a method call such as \"remove(\$item['id'])\", without {{ }}");
        }

        $decoded = html_entity_decode($code, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $this->tag->attributes[] = [
            'c' => 'fusion:'.$spec,
            /** An obfuscated build leaves the handler's text out of the page: the compiled code is what runs. */
            'h' => $this->scope->names === null ? [['c' => $decoded]] : [],
            'on' => ['e' => $name, 'm' => $parts, 'code' => $this->handler($decoded)],
        ];

        return $i + strlen($m[0]);
    }

    /** A handler: an expression whose value is the function to run ($event => ...). */
    private function handler(string $code): int
    {
        $this->inHandler = true;

        try {
            $handler = $this->transpiler->handler($code, $this->scope, $this->locals, $this->line);
        } finally {
            $this->inHandler = false;
        }

        $this->track();

        return $this->expression($handler);
    }

    /**
     * fusion:transition's modifiers: Globals::TRANSITION_MODIFIERS, .duration followed by a time
     * (300ms, 1s), .origin by a side.
     *
     * @param  list<string>  $modifiers
     */
    private function checkTransition(array $modifiers): void
    {
        for ($i = 0; $i < count($modifiers); $i++) {
            $modifier = $modifiers[$i];

            if (! in_array($modifier, Globals::TRANSITION_MODIFIERS, true)) {
                throw $this->error("Unknown modifier .{$modifier} on fusion:transition (it takes .".implode(', .', Globals::TRANSITION_MODIFIERS).')');
            }

            if ($modifier === 'duration' && preg_match('/^\d+m?s$/', $modifiers[$i + 1] ?? '') !== 1) {
                throw $this->error('fusion:transition.duration needs a time: .duration.300ms or .duration.1s');
            }

            if ($modifier === 'origin' && ! in_array($modifiers[$i + 1] ?? '', ['top', 'bottom', 'left', 'right', 'center'], true)) {
                throw $this->error('fusion:transition.origin needs a side: .origin.top, .bottom, .left, .right or .center');
            }

            if ($modifier === 'duration' || $modifier === 'origin') {
                $i++;
            }
        }
    }

    /** fusion:model="prop" or "prop.key": a public property that is not #[Locked]. */
    private function checkModel(string $text, int $i, array $modifiers): void
    {
        foreach ($modifiers as $modifier) {
            if (! in_array($modifier, ['live', 'blur', 'change', 'lazy', 'debounce', 'number', 'trim', 'boolean'], true) && ! preg_match('/^\d+m?s$/', $modifier)) {
                throw $this->error("Unknown modifier .{$modifier} on fusion:model");
            }
        }

        if (preg_match('/\Gfusion:[\w\-.]+\s*=\s*("([^"]*)"|\'([^\']*)\')/', $text, $m, 0, $i)) {
            $path = $m[2] !== '' ? $m[2] : ($m[3] ?? '');
            $valid = preg_match('/^[A-Za-z_]\w*(\.[\w\-]+)*$/', $path) === 1;
        } elseif (preg_match('/\Gfusion:[\w\-.]+\s*=\s*["\']([A-Za-z_]\w*\.[\w\-.]*)$/', $text, $m, 0, $i)) {
            /** A path built with Blade (fusion:model="items.{{ $i }}.name"): the root is fixed, the rest rendered. */
            $path = $m[1];
            $valid = true;
        } else {
            throw $this->error('fusion:model needs a property name, such as fusion:model="email" or "items.{{ $i }}.name"');
        }

        $root = explode('.', $path)[0];

        if (! $valid || ! $this->scope->isProperty($root)) {
            throw $this->error("fusion:model=\"{$path}\": {$root} is not a public property of the component");
        }

        if ($this->scope->isLocked($root)) {
            throw $this->error("fusion:model=\"{$path}\": {$root} is #[Locked], so the browser cannot change it");
        }
    }

    /**
     * <fusion:name ... />: a child component. It is keyed like on the server (ChildTag::key: its
     * number and the indexes of the @foreach loops around it, or its :key).
     */
    private function child(string $text, int $at): int
    {
        /** Children are numbered by the server per view file: inlined, the numbers would not match. */
        if ($this->frames !== []) {
            throw $this->error("<fusion:...> inside {$this->frames[count($this->frames) - 1]['label']}: put Fusion children in the component's own view, not in an included view or a Blade component");
        }

        $tag = ChildTag::parse($text, $at, $this->children++, $this->file, $this->line);

        if ($tag->hydrate !== null) {
            throw $this->error("fusion:hydrate and fusion:static are for islands, components placed in Blade views: <fusion:{$tag->name}> is a child of this component, and starts with it");
        }

        /**
         * Keyed by the indexes of the loops around it: in those loops an iteration is bound to its
         * position (the runtime renders it again when it moves, rather than moving it).
         */
        if ($tag->key === null) {
            foreach ($this->stack as $frame) {
                if (in_array($frame['kind'], ['foreach', 'forelse'], true)) {
                    $frame['node']->fields['i'][0]['positional'] = 1;
                }
            }
        }

        if ($tag->model !== null) {
            $root = explode('.', $tag->model)[0];

            if (! $this->scope->isProperty($root) || $this->scope->isLocked($root)) {
                throw $this->error("fusion:model on <fusion:{$tag->name}> binds \"{$tag->model}\": {$root} must be a public property of this component that the browser can change");
            }
        }

        /** Livewire's <fusion:dynamic-component :is="$name">, Viewi's tag named by an expression: the component :is names. */
        $dynamic = $tag->name === 'dynamic-component';

        if ($dynamic && ! isset($tag->props['is'])) {
            throw $this->error('<fusion:dynamic-component> needs :is, the name of the component to render (as in <fusion:name>)');
        }

        $props = [];

        foreach ($tag->props as $name => $expression) {
            if ($dynamic && $name === 'is') {
                continue;
            }

            /** The child's properties by the browser's names for them (an obfuscated build); "$model" is the runtime's. */
            $props[] = json_encode($name === '$model' || $dynamic ? $name : $this->scope->names?->property($name) ?? $name).': '.$this->expr($expression);
        }

        $node = new TemplateNode(array_filter([
            't' => 'c',
            'c' => $dynamic ? null : $this->scope->browserComponent($tag->name),
            'is' => $dynamic ? $this->expression('P.toStr('.$this->expr($tag->props['is']).')') : null,
            'n' => $tag->number,
            'key' => $tag->key !== null ? $this->expression('P.toStr('.$this->expr($tag->key).')') : null,
            'props' => $this->expression('{'.implode(', ', $props).'}'),
            'model' => $tag->model === null ? null : $this->scope->browserPath($tag->model),
        ], fn ($v) => $v !== null));
        $this->append($node);

        /**
         * <fusion:name> with content: its slots. They are this template's nodes (this component's
         * state, variables and handlers), rendered inside the child where its view puts $slot.
         */
        if (! $tag->selfClosing) {
            $node->slots['default'] = new TemplateNode(['t' => 'r']);
            $this->stack[] = ['kind' => 'child', 'node' => $node->slots['default'], 'child' => $node, 'tag' => $tag->name, 'line' => $this->line, 'locals' => $this->locals, 'sources' => $this->sources];
        }

        return $at + $tag->length;
    }

    /** <fusion:slot name="footer">: a named slot of the child tag around it. */
    private function slotOpen(string $text, int $at): int
    {
        $this->flushText();

        if (! preg_match(substr(ChildTag::SLOT_PATTERN, 0, 1).'\G'.substr(ChildTag::SLOT_PATTERN, 1), $text, $m, 0, $at)) {
            throw $this->error('<fusion:slot> needs a name: <fusion:slot name="footer">');
        }

        $top = end($this->stack);

        if ($top['kind'] !== 'child') {
            throw $this->error('<fusion:slot> belongs directly inside a <fusion:...> tag'.($top['kind'] === 'element' ? " (<{$top['tag']}> from line {$top['line']} is still open)" : ''));
        }

        $name = $m[1] !== '' ? $m[1] : $m[2];
        $slot = $top['child']->slots[$name] = new TemplateNode(['t' => 'r']);
        $this->stack[] = ['kind' => 'slot', 'node' => $slot, 'name' => $name, 'line' => $this->line, 'locals' => $this->locals, 'sources' => $this->sources];

        return $at + strlen($m[0]);
    }

    /** </fusion:slot> or </fusion:name>: the slot, or the child tag, ends. */
    private function childClose(string $text, int $at): int
    {
        $this->flushText();

        if (! preg_match('/\G<\/fusion:([a-z0-9][a-z0-9\-.]*)\s*>/i', $text, $m, 0, $at)) {
            throw $this->error('Malformed </fusion:...> tag');
        }

        $name = strtolower($m[1]);
        $top = end($this->stack);
        $expected = $name === 'slot' ? 'slot' : 'child';

        if ($top['kind'] !== $expected || ($expected === 'child' && $top['tag'] !== $name)) {
            throw $this->error("</fusion:{$name}> closes no <fusion:{$name}>".match ($top['kind']) {
                'element' => " (<{$top['tag']}> from line {$top['line']} is still open)",
                'child' => " (<fusion:{$top['tag']}> from line {$top['line']} is open)",
                'slot' => " (<fusion:slot name=\"{$top['name']}\"> from line {$top['line']} is open)",
                'root' => '',
                default => " (@{$top['kind']} from line {$top['line']} is open)",
            });
        }

        array_pop($this->stack);
        $this->locals = $top['locals'];
        $this->sources = $top['sources'];

        return $at + strlen($m[0]);
    }
}
