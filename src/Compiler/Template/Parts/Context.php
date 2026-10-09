<?php

namespace Nitro\Compiler\Template\Parts;

use Nitro\Compiler\CompileException;
use Nitro\Compiler\Scope;
use Nitro\Compiler\Template\TemplateNode;
use Nitro\Compiler\Transpiler;
use PhpParser\Node\Expr;

/**
 * The state the parts of the template compiler share while compiling one view. Unlike the
 * transpiler's, it is a state machine's: a Blade view arrives as tokens (text, echoes,
 * directives), and the HTML scanner's state (inside a tag? a quoted value? a comment?) carries
 * over from one token to the next, since <div class="{{ $x }}"> comes in three of them.
 *
 * What lives here, and who writes it:
 *
 *  - the output: the tree (Tree, from the stack's root), the expression table and what each
 *    expression reads (ExpressionTable)
 *  - the open stack: elements, blocks, child tags and slots, innermost last (Tree, Blocks,
 *    HtmlScanner, ChildComponents); each entry keeps the template variables in scope when it
 *    opened, restored when it closes
 *  - the template variables in scope ($item, $loop, $message, ...) by argument position, and
 *    what each one's value comes from (ExpressionTable, Blocks)
 *  - the HTML scanner's state: the state name, the tag being read, the attribute whose quoted
 *    value is being read, the comment and end tag read so far, the static text not yet a node
 *    (HtmlScanner, Tree)
 *  - the line being compiled, for errors and handlers (TemplateCompiler, HtmlScanner)
 *  - the inlined views' frames (InlinedViews): Nitro's handling of @include and <x-component>
 *
 * TemplateCompiler binds its public $calledMethods to the property here (by reference), so the
 * ComponentCompiler keeps reading it on the TemplateCompiler.
 *
 * @internal
 */
final class Context
{
    public Scope $scope;

    public ?string $file;

    /** The PHP compiler for the view's expressions and handlers (a new one per view). */
    public Transpiler $transpiler;

    /** @var list<string> the expression table, as JavaScript function sources */
    public array $expressions = [];

    /** @var array<string, mixed> the node tree compile() made */
    public array $tree = [];

    /** @var list<string> template variables in scope, by argument position ($item, $loop, $message, ...) */
    public array $locals = [];

    /** @var array<string, list<int|string>> what each template variable's value comes from (expressions, keys) */
    public array $sources = [];

    /** @var list<list<int|string>> by expression: what the template variables it reads come from */
    public array $expressionSources = [];

    /**
     * Open elements and blocks, innermost last.
     *
     * @var list<array{kind: string, node: TemplateNode, line: int, locals: list<string>, sources?: array<string, list<int|string>>, loop?: bool, tag?: string, ns?: string, case?: ?TemplateNode, child?: TemplateNode, name?: string}>
     */
    public array $stack = [];

    /** Static text read but not yet made a node. */
    public string $text = '';

    /** How many <nitro:child> tags the view has so far: each one's number. */
    public int $children = 0;

    /** A directive was the last token: Blade's PHP drops the newline right after it. */
    public bool $eatNewline = false;

    /** HTML scanner state: data, tag, value, comment, raw, rcdata, endtag */
    public string $state = 'data';

    /** The tag being read (its attributes), until its ">". */
    public ?TemplateNode $tag = null;

    public int $tagLine = 1;

    /** The index, among the tag's attributes, of the one whose quoted value is being read. */
    public int $attribute = -1;

    public string $quote = '';

    public string $endTag = '';

    public string $comment = '';

    public int $line = 1;

    /** How far into the current text token $line has counted. */
    public int $scanned = 0;

    /** @var list<string> component methods the template calls (for the compiler's reachability) */
    public array $calledMethods = [];

    /**
     * Inlined views (BladeInliner), innermost last: their variables (parsed), whether they see
     * the variables around them (an include) or not (a component), and how many template
     * variables were declared when the view began (those after are its own).
     *
     * @var list<array{id: int, vars: array<string, Expr>, isolated: bool, label: string, mark: int}>
     */
    public array $frames = [];

    /** @var array<int, list<array{id: int, vars: array<string, Expr>, isolated: bool, label: string, mark: int}>> by frame: the frames around it (its slots compile there) */
    public array $frameParents = [];

    /** @var list<list<array{id: int, vars: array<string, Expr>, isolated: bool, label: string, mark: int}>> frames set aside while a slot compiles */
    public array $setAside = [];

    /** @var list<array{0: ?string, 1: int}> by line of the inlined source: the file and line it came from */
    public array $lineMap = [];

    /** Compiling a handler: $event and the component's methods are the handler's own. */
    public bool $inHandler = false;

    /** @var list<int> by open slot: where its text starts in the text not yet made a node (Blade trims a slot's output) */
    public array $slotText = [];

    /** A compile error at the line being compiled. */
    public function error(string $message): CompileException
    {
        return new CompileException($message, $this->file, $this->line);
    }

    /** A value as readable JavaScript (pretty JSON): the tree. */
    public function json(mixed $value): string
    {
        return json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR);
    }
}
