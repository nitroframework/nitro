<?php

namespace Nitro\Fusion\Compiler;

use Closure;
use Nitro\Fusion\Compiler\Php\Calls;
use Nitro\Fusion\Compiler\Php\Catalog;
use Nitro\Fusion\Compiler\Php\ComponentAccess;
use Nitro\Fusion\Compiler\Php\Context;
use Nitro\Fusion\Compiler\Php\Expressions;
use Nitro\Fusion\Compiler\Php\FunctionDefinitions;
use Nitro\Fusion\Compiler\Php\Handlers;
use Nitro\Fusion\Compiler\Php\Statements;
use Nitro\Fusion\Compiler\Php\Writes;
use Nitro\Fusion\Compiler\Template\VariableSubstitution;
use PhpParser\Node\Expr;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar;
use PhpParser\Node\Stmt;
use PhpParser\ParserFactory;
use PhpParser\PhpVersion;
use Throwable;

/**
 * Compiles PHP (component methods, template expressions, event handlers) to JavaScript that keeps
 * PHP's semantics through js/src/php.js (imported as P):
 *
 *  - values: operators and functions go through P (PHP comparison, arithmetic, truthiness,
 *    string conversion); PHP arrays are values, so every write builds a new array along the path
 *    it changes (P.setIn) and assigns it back to its root variable or property
 *  - names: a PHP variable keeps its $ ($total -> $total), so it can never shadow a function
 *    (P.fns.date) or a JavaScript keyword
 *  - $this: the component in the browser; $this->prop reads and writes its state, $this->method()
 *    calls a compiled method, and a #[Server] method becomes a request to the server
 *
 * Anything without a faithful equivalent is a CompileException naming the file and line.
 *
 * This class is the front door: the four entry points (method(), templateExpression(),
 * handler(), templateFor()), parsing, and expr() / stmt(), which send each node to the part that
 * compiles it. The parts, in Compiler/Php:
 *
 *  - general PHP: Statements, Expressions, Writes (PHP's array value semantics), Calls (PHP's
 *    functions and Laravel's helpers), FunctionDefinitions (methods, closures, arrow functions),
 *    Catalog (what of PHP exists in the browser)
 *  - Fusion: ComponentAccess ($this, #[Server], dispatch, Fusion::browser()), Handlers (event
 *    handlers, magic actions, what a template expression may not do)
 *  - Context: the state they share while compiling one piece of code
 *
 * Each part's docblock says what it does and whether it is general PHP or Fusion.
 */
final class Transpiler
{
    /** PHP functions available in the browser (implemented in js/src/php.js `fns`): Catalog::FUNCTIONS. */
    public const FUNCTIONS = Catalog::FUNCTIONS;

    /** Functions taking their first argument by reference (the result is assigned back): Catalog::BY_REF. */
    public const BY_REF = Catalog::BY_REF;

    /** @var list<string> component methods the compiled code calls (read and reset by the caller); bound to Context */
    public array $calledMethods = [];

    /**
     * In a view inlined by BladeInliner: a variable's replacement (null keeps it), applied to
     * every template expression, handler and @for as it is parsed. Set by the TemplateCompiler;
     * bound to Context.
     *
     * @var (Closure(string): ?Expr)|null
     */
    public ?Closure $substitute = null;

    /** @var array{routes: array<string, true>, lang: array<string, true>, config: array<string, true>, functions: array<string, true>} what the Laravel helpers name, and the functions of js/functions called; bound to Context */
    public array $uses = ['routes' => [], 'lang' => [], 'config' => [], 'functions' => []];

    private readonly Context $context;

    public readonly Statements $statements;

    public readonly Expressions $expressions;

    public readonly Writes $writes;

    public readonly Calls $calls;

    public readonly FunctionDefinitions $functions;

    public readonly ComponentAccess $component;

    public readonly Handlers $handlers;

    public function __construct(Scope $scope = new Scope)
    {
        $this->context = new Context($scope);

        /** The callers read and set these on the Transpiler; the parts, on the Context. */
        $this->calledMethods = &$this->context->calledMethods;
        $this->uses = &$this->context->uses;
        $this->substitute = &$this->context->substitute;

        $this->statements = new Statements($this, $this->context);
        $this->expressions = new Expressions($this, $this->context);
        $this->writes = new Writes($this, $this->context);
        $this->calls = new Calls($this, $this->context);
        $this->functions = new FunctionDefinitions($this, $this->context);
        $this->component = new ComponentAccess($this, $this->context);
        $this->handlers = new Handlers($this, $this->context);
    }

    // ------------------------------------------------------------ entry points

    /**
     * A component method, as `function (...) { ... }` called with the component as `this`.
     */
    public function method(Stmt\ClassMethod $method, Scope $scope): string
    {
        $this->context->scope = $scope;
        $this->context->tempCounter = 0;
        /** updated($property, $value): the runtime passes the property by the browser's name (an obfuscated build), so a property named in it is too. */
        $this->context->updatedHook = $scope->names !== null && $method->name->toString() === 'updated';

        return $this->functions->functionLike($method->params, $method->stmts ?? [], $method->name->toString(), isMethod: true);
    }

    /**
     * An expression from a template, reading component state; $locals are the template's own
     * variables in scope ($loop variables, foreach values).
     *
     * @param  list<string>  $locals
     */
    public function templateExpression(string $code, Scope $scope, array $locals, int $line = 1): string
    {
        $this->context->scope = $scope->withVariables($scope->variable ?? fn () => null, lineOffset: $line - 1);
        $this->context->locals = array_fill_keys($locals, true);
        $this->context->temps = [];

        $expr = $this->parseExpression($code, $line);
        $this->handlers->forbidWrites($expr);
        $js = $this->expr($expr);

        return $this->context->temps === [] ? $js : $this->context->withTemps("return {$js};", true);
    }

    /**
     * An event handler (fusion:click="save", fusion:click="remove($item['id'])"): component method
     * calls, the magic actions $set, $toggle, $refresh and $dispatch, and Fusion::browser() calls.
     * $event is the DOM event.
     *
     * @param  list<string>  $locals
     */
    public function handler(string $code, Scope $scope, array $locals, int $line = 1): string
    {
        $this->context->scope = $scope->withVariables($scope->variable ?? fn () => null, lineOffset: $line - 1);
        $this->context->locals = array_fill_keys([...$locals, ...Globals::HANDLER_VARIABLES], true);
        $this->context->temps = [];

        /**
         * "save" and "goTo($page)" call component methods. Written as $this->method(), PHP accepts
         * any method name, keywords included (goto, print, list, ...), which a bare call does not.
         */
        $code = preg_replace('/(^|;)(\s*)([A-Za-z_]\w*)(\s*)(?=\(|;|$)/', '$1$2$this->$3$4', trim($code));
        $code = preg_replace('/(\$this->[A-Za-z_]\w*)(\s*)(?=;|$)/', '$1()$2', $code);

        $stmts = $this->parse('<?php '.$code.';', $line);
        $body = '';

        foreach ($stmts as $stmt) {
            if (! $stmt instanceof Stmt\Expression) {
                throw $this->context->error('An event handler is a method call, such as "save" or "remove($id)"', $stmt);
            }

            $body .= $this->handlers->handlerCall($stmt->expr).";\n";
        }

        /**
         * The template's variables ($n of @for, $item of @foreach) are the arguments its
         * expression gets for that element (TemplateCompiler), so a handler keeps the values it
         * was rendered with.
         */
        return '($event) => {'."\n".$this->context->declareTemps().$body.'}';
    }

    /**
     * The head of a template @for: "for (init; cond; step)" and the variables its initializer
     * declares (they become template variables inside the loop).
     *
     * @param  list<string>  $locals
     * @return array{0: string, 1: list<string>}
     */
    public function templateFor(string $args, Scope $scope, array $locals, int $line): array
    {
        $this->context->scope = $scope->withVariables($scope->variable ?? fn () => null, lineOffset: $line - 1);
        $stmts = $this->parse('<?php for ('.$args.') {}', $line);

        if (count($stmts) !== 1 || ! $stmts[0] instanceof Stmt\For_) {
            throw new CompileException('Malformed @for', $scope->file, $line);
        }

        $declared = [];

        foreach ($stmts[0]->init as $init) {
            if ($init instanceof Expr\Assign && $init->var instanceof Expr\Variable && is_string($init->var->name)) {
                $declared[] = $init->var->name;
            }
        }

        $this->context->locals = array_fill_keys([...$locals, ...$declared], true);
        $this->context->temps = [];
        $this->context->loops = [];

        $for = $stmts[0];
        $head = 'for ('.implode(', ', array_map(fn ($e) => $this->statementExpr($e), $for->init)).'; '
            .($for->cond === [] ? 'true' : 'P.bool(('.implode(', ', array_map(fn ($e) => $this->expr($e), $for->cond)).'))').'; '
            .implode(', ', array_map(fn ($e) => $this->statementExpr($e), $for->loop)).')';

        if ($this->context->usesTemporaries()) {
            throw new CompileException('@for is too complex for a template: compute the values in a method', $scope->file, $line);
        }

        return [$head, $declared];
    }

    // ------------------------------------------------------------ dispatch

    /** @param  list<Stmt>  $stmts */
    public function stmts(array $stmts): string
    {
        $out = '';

        foreach ($stmts as $stmt) {
            $out .= $this->stmt($stmt);
        }

        return $out;
    }

    /** A statement, sent to the part that compiles it; those with no equivalent in the browser are errors here. */
    public function stmt(Stmt $stmt): string
    {
        $c = $this->context;

        return match (true) {
            $stmt instanceof Stmt\Expression => $this->statementExpr($stmt->expr).";\n",
            $stmt instanceof Stmt\Return_ => $stmt->expr === null ? "return;\n" : 'return '.$this->expr($stmt->expr).";\n",
            $stmt instanceof Stmt\If_ => $this->statements->if($stmt),
            $stmt instanceof Stmt\Foreach_ => $this->statements->foreach($stmt),
            $stmt instanceof Stmt\For_ => $this->statements->for($stmt),
            $stmt instanceof Stmt\While_ => $this->statements->while($stmt),
            $stmt instanceof Stmt\Do_ => $this->statements->do($stmt),
            $stmt instanceof Stmt\Switch_ => $this->statements->switch($stmt),
            $stmt instanceof Stmt\Break_ => $this->statements->jump('break', $stmt),
            $stmt instanceof Stmt\Continue_ => $this->statements->jump('continue', $stmt),
            $stmt instanceof Stmt\Unset_ => $this->statements->unset($stmt),
            $stmt instanceof Stmt\Nop => '',
            $stmt instanceof Stmt\Block => "{\n".$this->stmts($stmt->stmts)."}\n",
            $stmt instanceof Stmt\Echo_ => throw $c->error('echo has no output in the browser: render values in the template', $stmt),
            $stmt instanceof Stmt\TryCatch => throw $c->error('try/catch is not supported in browser code: move this code to a #[Server] method', $stmt),
            $stmt instanceof Stmt\Global_, $stmt instanceof Stmt\Static_ => throw $c->error('global and static variables are not supported in the browser', $stmt),
            default => throw $c->error(sprintf('%s is not supported in the browser', $c->describe($stmt)), $stmt),
        };
    }

    /**
     * An expression whose value is not used: assignments and increments need no temporaries.
     */
    public function statementExpr(Expr $expr): string
    {
        if ($expr instanceof Expr\Assign) {
            return $this->writes->assignment($expr, false);
        }

        if ($expr instanceof Expr\AssignOp) {
            return $this->writes->assignOp($expr, false);
        }

        if ($expr instanceof Expr\PreInc || $expr instanceof Expr\PostInc || $expr instanceof Expr\PreDec || $expr instanceof Expr\PostDec) {
            return $this->writes->increment($expr, false);
        }

        if ($expr instanceof Expr\MethodCall && $this->context->isThis($expr->var) && $expr->name instanceof Identifier
            && $this->context->scope->isServerMethod($expr->name->toString())) {
            return $this->component->serverCall($expr);
        }

        if ($expr instanceof Expr\FuncCall && $expr->name instanceof Name && in_array(Catalog::functionName($expr->name), Catalog::BY_REF, true)) {
            return $this->calls->byRefCall($expr, false);
        }

        return $this->expr($expr);
    }

    /** An expression, sent to the part that compiles it; those with no equivalent in the browser are errors here. */
    public function expr(Expr $e): string
    {
        $c = $this->context;

        return match (true) {
            $e instanceof Scalar\String_ => $c->string($c->updatedHook ? $c->scope->browserPath($e->value) : $e->value),
            $e instanceof Scalar\Int_ => (string) $e->value,
            $e instanceof Scalar\Float_ => $c->float($e->value),
            $e instanceof Scalar\InterpolatedString => $this->expressions->interpolated($e),
            $e instanceof Scalar\MagicConst => throw $c->error('Magic constants are not available in the browser', $e),
            $e instanceof Expr\ConstFetch => $this->expressions->constant($e),
            $e instanceof Expr\ClassConstFetch => $this->expressions->classConstant($e),
            $e instanceof Expr\Variable => $this->expressions->variable($e),
            $e instanceof Expr\Array_ => $this->expressions->array($e),
            $e instanceof Expr\ArrayDimFetch => $this->expressions->dimFetch($e),
            $e instanceof Expr\PropertyFetch, $e instanceof Expr\NullsafePropertyFetch => $this->component->propertyFetch($e),
            $e instanceof Expr\MethodCall, $e instanceof Expr\NullsafeMethodCall => $this->component->methodCall($e),
            $e instanceof Expr\FuncCall => $this->calls->funcCall($e),
            $e instanceof Expr\Assign => $this->writes->assignment($e, true),
            $e instanceof Expr\AssignOp => $this->writes->assignOp($e, true),
            $e instanceof Expr\PreInc, $e instanceof Expr\PostInc, $e instanceof Expr\PreDec, $e instanceof Expr\PostDec => $this->writes->increment($e, true),
            $e instanceof Expr\BinaryOp => $this->expressions->binary($e),
            $e instanceof Expr\BooleanNot => '!P.bool('.$this->expr($e->expr).')',
            $e instanceof Expr\UnaryMinus => 'P.neg('.$this->expr($e->expr).')',
            $e instanceof Expr\UnaryPlus => 'P.toNum('.$this->expr($e->expr).')',
            $e instanceof Expr\BitwiseNot => '(~P.toInt('.$this->expr($e->expr).'))',
            $e instanceof Expr\Ternary => $this->expressions->ternary($e),
            $e instanceof Expr\Cast => $this->expressions->cast($e),
            $e instanceof Expr\Isset_ => $this->expressions->isset($e),
            $e instanceof Expr\Empty_ => $this->expressions->empty($e),
            $e instanceof Expr\Match_ => $this->expressions->match($e),
            $e instanceof Expr\Closure => $this->functions->closure($e),
            $e instanceof Expr\ArrowFunction => $this->functions->arrowFunction($e),
            $e instanceof Expr\New_ => throw $c->error('Creating objects (new) is not supported in the browser: move this code to a #[Server] method', $e),
            $e instanceof Expr\StaticCall => $this->calls->staticCall($e),
            $e instanceof Expr\Throw_ => throw $c->error('throw is not supported in the browser: move this code to a #[Server] method', $e),
            $e instanceof Expr\Instanceof_ => throw $c->error('instanceof is not supported in the browser (objects arrive as arrays)', $e),
            $e instanceof Expr\ErrorSuppress => $this->expr($e->expr),
            default => throw $c->error(sprintf('%s is not supported in the browser', $c->describe($e)), $e),
        };
    }

    // ------------------------------------------------------------ parsing

    /** @return list<Stmt> */
    private function parse(string $code, int $line): array
    {
        try {
            $stmts = (new ParserFactory)->createForVersion(PhpVersion::fromComponents(8, 3))->parse($code) ?? [];
        } catch (Throwable $e) {
            throw new CompileException('Invalid PHP: '.$e->getMessage(), $this->context->scope->file, $line);
        }

        return $this->context->substitute === null ? $stmts : VariableSubstitution::apply($stmts, $this->context->substitute);
    }

    private function parseExpression(string $code, int $line): Expr
    {
        $stmts = $this->parse('<?php ('.$code.');', $line);

        if (count($stmts) !== 1 || ! $stmts[0] instanceof Stmt\Expression) {
            throw new CompileException('Expected one expression: '.$code, $this->context->scope->file, $line);
        }

        return $stmts[0]->expr;
    }
}
