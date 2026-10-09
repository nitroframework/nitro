<?php

namespace Nitro\Compiler;

use Closure;
use Nitro\Compiler\Php\Calls;
use Nitro\Compiler\Php\Catalog;
use Nitro\Compiler\Php\ComponentAccess;
use Nitro\Compiler\Php\Context;
use Nitro\Support\PropertyHooks;
use Nitro\Compiler\Php\Expressions;
use Nitro\Compiler\Php\FunctionDefinitions;
use Nitro\Compiler\Php\Handlers;
use Nitro\Compiler\Php\Statements;
use Nitro\Compiler\Php\Writes;
use Nitro\Compiler\Template\VariableSubstitution;
use PhpParser\Node\Expr;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar;
use PhpParser\Node\Stmt;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
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
 *  - Nitro: ComponentAccess ($this, #[Server], dispatch, Nitro::browser()), Handlers (event
 *    handlers, magic actions, what a template expression may not do)
 *  - Context: the state they share while compiling one piece of code
 *
 * Each part's docblock says what it does and whether it is general PHP or Nitro.
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

    /** A view's imports (its use directives), as PHP use statements: its expressions' names resolve through them. Set by the TemplateCompiler; bound to Context. */
    public string $imports = '';

    /** @var array{routes: array<string, true>, lang: array<string, true>, config: array<string, true>, functions: array<string, true>, classes: array<string, true>} what the Laravel helpers name, and the functions of js/functions called; bound to Context */
    public array $uses;

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
        $this->imports = &$this->context->imports;

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
        /** updating() / updated($property, $value): the runtime passes the property by the browser's name (an obfuscated build), so a property named in it is too. */
        $this->context->pathHook = $scope->names !== null && PropertyHooks::takesPath($method->name->toString());

        return $this->functions->functionLike($method->params, $method->stmts ?? [], $method->name->toString(), isMethod: true);
    }

    /**
     * A static method of a #[Browser] class, as `function (...) { ... }`: no component, its
     * class's constants as self::X, its class's methods as self::method().
     *
     * @param  class-string  $class
     */
    public function staticMethod(Stmt\ClassMethod $method, Scope $scope, string $class): string
    {
        $this->context->scope = $scope;
        $this->context->tempCounter = 0;
        $this->context->class = $class;

        return $this->functions->functionLike($method->params, $method->stmts ?? [], $method->name->toString());
    }

    /**
     * An instance method of a #[Browser] enum, as `function (...) { ... }` called with the case as
     * `this`: $this->name, $this->value, $this->method(), $this itself (match ($this) {...}).
     *
     * @param  class-string  $enum
     */
    public function enumMethod(Stmt\ClassMethod $method, Scope $scope, string $enum): string
    {
        $this->context->scope = $scope;
        $this->context->tempCounter = 0;
        $this->context->class = $enum;
        $this->context->enumCase = true;

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

        return $this->context->hasTemps() ? $this->context->withTemps("return {$js};", true) : $js;
    }

    /**
     * An event handler (nitro:click="save", nitro:click="remove($item['id'])"): component method
     * calls, the magic actions $set, $toggle, $refresh and $dispatch, and Nitro::browser() calls.
     * $event is the DOM event.
     *
     * @param  list<string>  $locals
     */
    public function handler(string $code, Scope $scope, array $locals, int $line = 1): string
    {
        $this->context->scope = $scope->withVariables($scope->variable ?? fn () => null, lineOffset: $line - 1);
        $this->context->locals = array_fill_keys([...$locals, ...Globals::HANDLER_VARIABLES], true);
        $this->context->temps = [];

        $stmts = $this->parse('<?php '.self::methodCalls(trim($code)).';', $line);
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
     * A handler's bare calls as $this calls: "save" and "goTo($page)" call component methods.
     * Written as $this->method(), PHP accepts any method name, keywords included (goto, print,
     * list, ...), which a bare call does not; "$this->save" gets its (). Read as PHP's tokens, so
     * a string ($set('note', 'a; b')) is never changed.
     */
    private static function methodCalls(string $code): string
    {
        $tokens = array_slice(token_get_all('<?php '.$code), 1);
        $blank = fn ($token) => is_array($token) && in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true);

        /** The code's tokens without the blanks, as text, for what comes before and after one. */
        $texts = [];

        foreach ($tokens as $token) {
            if (! $blank($token)) {
                $texts[] = is_array($token) ? $token[1] : $token;
            }
        }

        $name = fn (?string $text) => $text !== null && preg_match('/^[A-Za-z_]\w*$/', $text) === 1;
        $out = '';
        $at = 0;

        foreach ($tokens as $token) {
            $text = is_array($token) ? $token[1] : $token;

            if ($blank($token)) {
                $out .= $text;

                continue;
            }

            $previous = $texts[$at - 1] ?? ';';
            $next = $texts[$at + 1] ?? null;
            $ends = $next === null || $next === ';';

            if ($name($text) && $previous === ';' && ($ends || $next === '(')) {
                $out .= '$this->'.$text.($ends ? '()' : '');
            } elseif ($name($text) && $previous === '->' && ($texts[$at - 2] ?? null) === '$this' && $ends) {
                $out .= $text.'()';
            } else {
                $out .= $text;
            }

            $at++;
        }

        return $out;
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
        $head = 'for ('.implode(', ', array_map(fn ($expression) => $this->statementExpr($expression), $for->init)).'; '
            .($for->cond === [] ? 'true' : 'P.bool(('.implode(', ', array_map(fn ($expression) => $this->expr($expression), $for->cond)).'))').'; '
            .implode(', ', array_map(fn ($expression) => $this->statementExpr($expression), $for->loop)).')';

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
        $context = $this->context;

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
            $stmt instanceof Stmt\Echo_ => throw $context->error('echo has no output in the browser: render values in the template', $stmt),
            $stmt instanceof Stmt\TryCatch => throw $context->error('try/catch is not supported in browser code: move this code to a #[Server] method', $stmt),
            $stmt instanceof Stmt\Global_, $stmt instanceof Stmt\Static_ => throw $context->error('global and static variables are not supported in the browser', $stmt),
            default => throw $context->error(sprintf('%s is not supported in the browser', $context->describe($stmt)), $stmt),
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

        /** $this->cart->save(): a store's #[Server] method, as a statement. */
        if ($expr instanceof Expr\MethodCall && $this->component->isStoreServerCall($expr)) {
            return $this->component->serverCall($expr);
        }

        if ($expr instanceof Expr\FuncCall && $expr->name instanceof Name && in_array(Catalog::functionName($expr->name), Catalog::BY_REF, true)) {
            return $this->calls->byRefCall($expr, false);
        }

        return $this->expr($expr);
    }

    /** An expression, sent to the part that compiles it; those with no equivalent in the browser are errors here. */
    public function expr(Expr $expression): string
    {
        $context = $this->context;

        return match (true) {
            $expression instanceof Scalar\String_ => $context->string($context->pathHook ? $context->scope->browserPath($expression->value) : $expression->value),
            $expression instanceof Scalar\Int_ => (string) $expression->value,
            $expression instanceof Scalar\Float_ => $context->float($expression->value),
            $expression instanceof Scalar\InterpolatedString => $this->expressions->interpolated($expression),
            $expression instanceof Scalar\MagicConst => throw $context->error('Magic constants are not available in the browser', $expression),
            $expression instanceof Expr\ConstFetch => $this->expressions->constant($expression),
            $expression instanceof Expr\ClassConstFetch => $this->expressions->classConstant($expression),
            $expression instanceof Expr\Variable => $this->expressions->variable($expression),
            $expression instanceof Expr\Array_ => $this->expressions->array($expression),
            $expression instanceof Expr\ArrayDimFetch => $this->expressions->dimFetch($expression),
            $expression instanceof Expr\PropertyFetch, $expression instanceof Expr\NullsafePropertyFetch => $this->component->propertyFetch($expression),
            $expression instanceof Expr\MethodCall, $expression instanceof Expr\NullsafeMethodCall => $this->component->methodCall($expression),
            $expression instanceof Expr\FuncCall => $this->calls->funcCall($expression),
            $expression instanceof Expr\Assign => $this->writes->assignment($expression, true),
            $expression instanceof Expr\AssignOp => $this->writes->assignOp($expression, true),
            $expression instanceof Expr\PreInc, $expression instanceof Expr\PostInc, $expression instanceof Expr\PreDec, $expression instanceof Expr\PostDec => $this->writes->increment($expression, true),
            $expression instanceof Expr\BinaryOp => $this->expressions->binary($expression),
            $expression instanceof Expr\BooleanNot => '!P.bool('.$this->expr($expression->expr).')',
            $expression instanceof Expr\UnaryMinus => 'P.neg('.$this->expr($expression->expr).')',
            $expression instanceof Expr\UnaryPlus => 'P.toNum('.$this->expr($expression->expr).')',
            $expression instanceof Expr\BitwiseNot => '(~P.toInt('.$this->expr($expression->expr).'))',
            $expression instanceof Expr\Ternary => $this->expressions->ternary($expression),
            $expression instanceof Expr\Cast => $this->expressions->cast($expression),
            $expression instanceof Expr\Isset_ => $this->expressions->isset($expression),
            $expression instanceof Expr\Empty_ => $this->expressions->empty($expression),
            $expression instanceof Expr\Match_ => $this->expressions->match($expression),
            $expression instanceof Expr\Closure => $this->functions->closure($expression),
            $expression instanceof Expr\ArrowFunction => $this->functions->arrowFunction($expression),
            $expression instanceof Expr\New_ => throw $context->error('Creating objects (new) is not supported in the browser: move this code to a #[Server] method', $expression),
            $expression instanceof Expr\StaticCall => $this->calls->staticCall($expression),
            $expression instanceof Expr\Throw_ => throw $context->error('throw is not supported in the browser: move this code to a #[Server] method', $expression),
            $expression instanceof Expr\Instanceof_ => throw $context->error('instanceof is not supported in the browser (objects arrive as arrays)', $expression),
            $expression instanceof Expr\ErrorSuppress => $this->expr($expression->expr),
            default => throw $context->error(sprintf('%s is not supported in the browser', $context->describe($expression)), $expression),
        };
    }

    // ------------------------------------------------------------ parsing

    /** @return list<Stmt> */
    private function parse(string $code, int $line): array
    {
        /** A view's imports: its use statements first, on the same line (the code keeps its line numbers). */
        $imports = $this->context->imports;

        if ($imports !== '') {
            $code = '<?php '.$imports.' '.substr($code, strlen('<?php '));
        }

        try {
            $stmts = (new ParserFactory)->createForVersion(PhpVersion::fromComponents(8, 3))->parse($code) ?? [];
        } catch (Throwable $exception) {
            throw new CompileException('Invalid PHP: '.$exception->getMessage(), $this->context->scope->file, $line);
        }

        if ($imports !== '') {
            (new NodeTraverser(new NameResolver(null, ['replaceNodes' => false])))->traverse($stmts);
            $stmts = array_values(array_filter($stmts, fn (Stmt $stmt) => ! $stmt instanceof Stmt\Use_ && ! $stmt instanceof Stmt\GroupUse));
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
