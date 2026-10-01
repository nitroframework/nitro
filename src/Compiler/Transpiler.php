<?php

namespace Nitro\Fusion\Compiler;

use Closure;
use Illuminate\Support\Str;
use Nitro\Fusion\Compiler\Template\VariableSubstitution;
use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\NullableType;
use PhpParser\Node\Param;
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
 */
final class Transpiler
{
    /** PHP functions available in the browser (implemented in js/src/php.js `fns`). */
    public const FUNCTIONS = [
        'strlen', 'mb_strlen', 'strtolower', 'strtoupper', 'mb_strtolower', 'mb_strtoupper', 'ucfirst', 'lcfirst', 'ucwords',
        'str_contains', 'str_starts_with', 'str_ends_with', 'trim', 'ltrim', 'rtrim', 'str_repeat', 'str_pad', 'str_replace',
        'str_ireplace', 'substr', 'mb_substr', 'strpos', 'stripos', 'strrpos', 'substr_count', 'strrev', 'str_split', 'nl2br',
        'implode', 'join', 'explode', 'sprintf', 'number_format', 'htmlspecialchars', 'e', 'strval',
        'abs', 'round', 'floor', 'ceil', 'intdiv', 'fmod', 'sqrt', 'pow', 'max', 'min', 'intval', 'floatval', 'boolval',
        'is_numeric', 'is_string', 'is_array', 'is_bool', 'is_null', 'random_int',
        'count', 'array_is_list', 'array_keys', 'array_values', 'array_key_exists', 'array_key_first', 'array_key_last',
        'in_array', 'array_search', 'array_merge', 'array_filter', 'array_map', 'array_reduce', 'array_slice', 'array_reverse',
        'array_sum', 'array_product', 'array_unique', 'array_column', 'array_combine', 'array_flip', 'array_fill',
        'array_pad', 'array_chunk', 'array_diff', 'array_intersect', 'array_diff_key', 'array_intersect_key', 'array_fill_keys',
        'range', 'json_encode', 'json_decode',
        'sort', 'rsort', 'usort', 'uasort', 'uksort', 'asort', 'arsort', 'ksort', 'krsort', 'shuffle',
        'array_push', 'array_pop', 'array_shift', 'array_unshift', 'array_splice',
    ];

    /** Functions taking their first argument by reference: the result is assigned back. */
    public const BY_REF = [
        'sort', 'rsort', 'usort', 'uasort', 'uksort', 'asort', 'arsort', 'ksort', 'krsort', 'shuffle',
        'array_push', 'array_pop', 'array_shift', 'array_unshift', 'array_splice',
    ];

    /** Functions with an output parameter (a reference): its position. Their JavaScript returns [result, output]. */
    private const OUT_PARAMS = ['preg_match' => 2, 'preg_match_all' => 2];

    /** References that are not taken: a function's argument there fails to compile. */
    private const UNSUPPORTED_REFS = ['preg_replace' => 4, 'preg_replace_callback' => 4, 'similar_text' => 2];

    private const ALIASES = ['join' => 'implode'];

    private const CONSTANTS = [
        /** The server's value: the page is rendered there too, and both renders must match. */
        'PHP_EOL' => PHP_EOL,
        'PHP_INT_MAX' => 9007199254740991,
        'PHP_INT_MIN' => -9007199254740991,
        'PHP_FLOAT_EPSILON' => PHP_FLOAT_EPSILON,
        'M_PI' => M_PI,
        'STR_PAD_RIGHT' => STR_PAD_RIGHT,
        'STR_PAD_LEFT' => STR_PAD_LEFT,
        'STR_PAD_BOTH' => STR_PAD_BOTH,
        'ARRAY_FILTER_USE_KEY' => ARRAY_FILTER_USE_KEY,
        'ARRAY_FILTER_USE_BOTH' => ARRAY_FILTER_USE_BOTH,
        'JSON_PRETTY_PRINT' => JSON_PRETTY_PRINT,
        'JSON_UNESCAPED_SLASHES' => JSON_UNESCAPED_SLASHES,
        'JSON_UNESCAPED_UNICODE' => JSON_UNESCAPED_UNICODE,
        'JSON_THROW_ON_ERROR' => JSON_THROW_ON_ERROR,
        /** The flags of the functions in js/functions. */
        'ENT_COMPAT' => ENT_COMPAT,
        'ENT_QUOTES' => ENT_QUOTES,
        'ENT_NOQUOTES' => ENT_NOQUOTES,
        'ENT_HTML401' => ENT_HTML401,
        'ENT_XML1' => ENT_XML1,
        'ENT_XHTML' => ENT_XHTML,
        'ENT_HTML5' => ENT_HTML5,
        'ENT_SUBSTITUTE' => ENT_SUBSTITUTE,
        'PHP_URL_SCHEME' => PHP_URL_SCHEME,
        'PHP_URL_HOST' => PHP_URL_HOST,
        'PHP_URL_PORT' => PHP_URL_PORT,
        'PHP_URL_USER' => PHP_URL_USER,
        'PHP_URL_PASS' => PHP_URL_PASS,
        'PHP_URL_PATH' => PHP_URL_PATH,
        'PHP_URL_QUERY' => PHP_URL_QUERY,
        'PHP_URL_FRAGMENT' => PHP_URL_FRAGMENT,
        'PHP_QUERY_RFC1738' => PHP_QUERY_RFC1738,
        'PHP_QUERY_RFC3986' => PHP_QUERY_RFC3986,
        'M_E' => M_E,
        'M_SQRT2' => M_SQRT2,
        'MB_CASE_UPPER' => MB_CASE_UPPER,
        'MB_CASE_LOWER' => MB_CASE_LOWER,
        'MB_CASE_TITLE' => MB_CASE_TITLE,
        'CASE_LOWER' => CASE_LOWER,
        'CASE_UPPER' => CASE_UPPER,
        'PREG_PATTERN_ORDER' => PREG_PATTERN_ORDER,
        'PREG_SET_ORDER' => PREG_SET_ORDER,
        'PREG_OFFSET_CAPTURE' => PREG_OFFSET_CAPTURE,
        'PREG_UNMATCHED_AS_NULL' => PREG_UNMATCHED_AS_NULL,
        'PREG_SPLIT_NO_EMPTY' => PREG_SPLIT_NO_EMPTY,
        'PREG_SPLIT_DELIM_CAPTURE' => PREG_SPLIT_DELIM_CAPTURE,
        'PREG_SPLIT_OFFSET_CAPTURE' => PREG_SPLIT_OFFSET_CAPTURE,
        'PREG_GREP_INVERT' => PREG_GREP_INVERT,
    ];

    /** Parameter types coerced on entry, as PHP does in coercive typing mode. */
    private const SCALAR_TYPES = ['int', 'float', 'string', 'bool', 'array'];

    private Scope $scope;

    /** @var array<string, true> variables declared in the function being compiled */
    private array $locals = [];

    /** @var list<string> temporaries of the function being compiled */
    private array $temps = [];

    private int $tempCounter = 0;

    /** @var list<string> labels of the enclosing loops and switches, innermost last */
    private array $loops = [];

    private int $labelCounter = 0;

    /** @var list<string> component methods the compiled code calls (read and reset by the caller) */
    public array $calledMethods = [];

    /**
     * In a view inlined by BladeInliner: a variable's replacement (null keeps it), applied to
     * every template expression, handler and @for as it is parsed. Set by the TemplateCompiler.
     *
     * @var (Closure(string): ?Expr)|null
     */
    public ?Closure $substitute = null;

    /** @var array{routes: array<string, true>, lang: array<string, true>, config: array<string, true>, functions: array<string, true>} what the Laravel helpers name, and the functions of js/functions called */
    public array $uses = ['routes' => [], 'lang' => [], 'config' => [], 'functions' => []];

    public function __construct(Scope $scope = new Scope)
    {
        $this->scope = $scope;
    }

    // ------------------------------------------------------------ entry points

    /**
     * A component method, as `function (...) { ... }` called with the component as `this`.
     */
    public function method(Stmt\ClassMethod $method, Scope $scope): string
    {
        $this->scope = $scope;
        $this->tempCounter = 0;

        return $this->functionLike($method->params, $method->stmts ?? [], $method->name->toString(), isMethod: true);
    }

    /**
     * An expression from a template, reading component state; $locals are the template's own
     * variables in scope ($loop variables, foreach values).
     *
     * @param  list<string>  $locals
     */
    public function templateExpression(string $code, Scope $scope, array $locals, int $line = 1): string
    {
        $this->scope = $scope->withVariables($scope->variable ?? fn () => null, lineOffset: $line - 1);
        $this->locals = array_fill_keys($locals, true);
        $this->temps = [];

        $expr = $this->parseExpression($code, $line);
        $this->forbidWrites($expr);
        $js = $this->expr($expr);

        return $this->temps === [] ? $js : $this->withTemps("return {$js};", true);
    }

    /**
     * An event handler (fusion:click="save", fusion:click="remove($item['id'])"): component method
     * calls and the magic actions $set, $toggle, $refresh and $dispatch. $event is the DOM event.
     *
     * @param  list<string>  $locals
     */
    public function handler(string $code, Scope $scope, array $locals, int $line = 1): string
    {
        $this->scope = $scope->withVariables($scope->variable ?? fn () => null, lineOffset: $line - 1);
        $this->locals = array_fill_keys([...$locals, ...Globals::HANDLER_VARIABLES], true);
        $this->temps = [];

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
                throw $this->error('An event handler is a method call, such as "save" or "remove($id)"', $stmt);
            }

            $body .= $this->handlerCall($stmt->expr).";\n";
        }

        /**
         * The template's variables ($n of @for, $item of @foreach) are the arguments its
         * expression gets for that element (TemplateCompiler), so a handler keeps the values it
         * was rendered with.
         */
        return '($event) => {'."\n".$this->declareTemps().$body.'}';
    }

    // ------------------------------------------------------------ parsing

    /** @return list<Stmt> */
    private function parse(string $code, int $line): array
    {
        try {
            $stmts = (new ParserFactory)->createForVersion(PhpVersion::fromComponents(8, 3))->parse($code) ?? [];
        } catch (Throwable $e) {
            throw new CompileException('Invalid PHP: '.$e->getMessage(), $this->scope->file, $line);
        }

        return $this->substitute === null ? $stmts : VariableSubstitution::apply($stmts, $this->substitute);
    }

    private function parseExpression(string $code, int $line): Expr
    {
        $stmts = $this->parse('<?php ('.$code.');', $line);

        if (count($stmts) !== 1 || ! $stmts[0] instanceof Stmt\Expression) {
            throw new CompileException('Expected one expression: '.$code, $this->scope->file, $line);
        }

        return $stmts[0]->expr;
    }

    // ------------------------------------------------------------ functions

    /**
     * @param  list<Param>  $params
     * @param  list<Stmt>  $stmts
     */
    private function functionLike(array $params, array $stmts, string $name, bool $isMethod = false): string
    {
        [$savedLocals, $savedTemps, $savedLoops] = [$this->locals, $this->temps, $this->loops];
        $this->locals = [];
        $this->temps = [];
        $this->loops = [];

        $signature = [];
        $coercions = '';

        foreach ($params as $param) {
            $signature[] = $this->param($param);
            $coercions .= $this->coercion($param, $name);
            $this->locals[$param->var->name] = true;
        }

        $paramNames = $this->locals;

        foreach ($this->variablesIn($stmts) as $variable) {
            $this->locals[$variable] = true;
        }

        $body = $this->stmts($stmts);
        $declared = array_diff_key($this->locals, $paramNames, ['this' => true]);

        $head = $isMethod ? "const \$this = this;\n" : '';

        if ($declared !== []) {
            $head .= 'let '.implode(', ', array_map(fn ($v) => '$'.$v, array_keys($declared))).";\n";
        }

        $head .= $this->declareTemps().$coercions;

        [$this->locals, $this->temps, $this->loops] = [$savedLocals, $savedTemps, $savedLoops];

        return 'function ('.implode(', ', $signature).") {\n{$head}{$body}}";
    }

    private function param(Param $param): string
    {
        if ($param->byRef) {
            throw $this->error('By-reference parameters are not supported in the browser', $param);
        }

        if (! $param->var instanceof Expr\Variable || ! is_string($param->var->name)) {
            throw $this->error('Unsupported parameter', $param);
        }

        $name = '$'.$param->var->name;

        if ($param->variadic) {
            return '...'.$name;
        }

        return $param->default === null ? $name : $name.' = '.$this->expr($param->default);
    }

    /** Coercive typing: a declared scalar type converts (or rejects) the argument, as PHP does. */
    private function coercion(Param $param, string $function): string
    {
        $type = $param->type;
        $nullable = false;

        if ($type instanceof NullableType) {
            $nullable = true;
            $type = $type->type;
        }

        if (! $type instanceof Identifier || ! in_array($type->toLowerString(), self::SCALAR_TYPES, true) || $param->variadic) {
            return '';
        }

        if ($param->default instanceof Expr\ConstFetch && $param->default->name->toLowerString() === 'null') {
            $nullable = true;
        }

        $name = $param->var->name;

        return sprintf("\$%s = P.param(\$%s, %s, %s, %s);\n", $name, $name, $this->string($type->toLowerString()), $nullable ? 'true' : 'false', $this->string($function.'(): Argument $'.$name));
    }

    /**
     * Variables a function body uses, not descending into closures (they have their own scope)
     * except for the variables a closure captures with `use`.
     *
     * @param  array<Node>  $nodes
     * @return list<string>
     */
    private function variablesIn(array $nodes): array
    {
        $found = [];

        $walk = function ($node) use (&$walk, &$found) {
            if (is_array($node)) {
                foreach ($node as $item) {
                    $walk($item);
                }

                return;
            }

            if (! $node instanceof Node) {
                return;
            }

            if ($node instanceof Expr\Closure) {
                foreach ($node->uses as $use) {
                    $found[$use->var->name] = true;
                }

                return;
            }

            if ($node instanceof Expr\ArrowFunction) {
                return;
            }

            if ($node instanceof Expr\Variable && is_string($node->name) && $node->name !== 'this') {
                $found[$node->name] = true;
            }

            foreach ($node->getSubNodeNames() as $sub) {
                $walk($node->$sub);
            }
        };

        $walk($nodes);

        return array_keys($found);
    }

    private function temp(): string
    {
        $name = '$$'.(++$this->tempCounter);
        $this->temps[] = $name;

        return $name;
    }

    private function usesTemporaries(): bool
    {
        return count($this->temps) > 0;
    }

    private function declareTemps(): string
    {
        return $this->temps === [] ? '' : 'let '.implode(', ', $this->temps).";\n";
    }

    private function withTemps(string $body, bool $arrow): string
    {
        return '(() => {'.$this->declareTemps().$body.'})()';
    }

    // ------------------------------------------------------------ statements

    /** @param  list<Stmt>  $stmts */
    private function stmts(array $stmts): string
    {
        $out = '';

        foreach ($stmts as $stmt) {
            $out .= $this->stmt($stmt);
        }

        return $out;
    }

    private function stmt(Stmt $stmt): string
    {
        return match (true) {
            $stmt instanceof Stmt\Expression => $this->statementExpr($stmt->expr).";\n",
            $stmt instanceof Stmt\Return_ => $stmt->expr === null ? "return;\n" : 'return '.$this->expr($stmt->expr).";\n",
            $stmt instanceof Stmt\If_ => $this->if($stmt),
            $stmt instanceof Stmt\Foreach_ => $this->foreach($stmt),
            $stmt instanceof Stmt\For_ => $this->for($stmt),
            $stmt instanceof Stmt\While_ => $this->loop($stmt, fn ($label) => "{$label}: while (P.bool(".$this->expr($stmt->cond).")) {\n".$this->stmts($stmt->stmts)."}\n"),
            $stmt instanceof Stmt\Do_ => $this->loop($stmt, fn ($label) => "{$label}: do {\n".$this->stmts($stmt->stmts).'} while (P.bool('.$this->expr($stmt->cond)."));\n"),
            $stmt instanceof Stmt\Switch_ => $this->switch($stmt),
            $stmt instanceof Stmt\Break_ => $this->jump('break', $stmt),
            $stmt instanceof Stmt\Continue_ => $this->jump('continue', $stmt),
            $stmt instanceof Stmt\Unset_ => $this->unset($stmt),
            $stmt instanceof Stmt\Nop => '',
            $stmt instanceof Stmt\Block => "{\n".$this->stmts($stmt->stmts)."}\n",
            $stmt instanceof Stmt\Echo_ => throw $this->error('echo has no output in the browser: render values in the template', $stmt),
            $stmt instanceof Stmt\TryCatch => throw $this->error('try/catch is not supported in browser code: move this code to a #[Server] method', $stmt),
            $stmt instanceof Stmt\Global_, $stmt instanceof Stmt\Static_ => throw $this->error('global and static variables are not supported in the browser', $stmt),
            default => throw $this->error(sprintf('%s is not supported in the browser', $this->describe($stmt)), $stmt),
        };
    }

    private function if(Stmt\If_ $stmt): string
    {
        $out = 'if (P.bool('.$this->expr($stmt->cond).")) {\n".$this->stmts($stmt->stmts).'}';

        foreach ($stmt->elseifs as $elseif) {
            $out .= ' else if (P.bool('.$this->expr($elseif->cond).")) {\n".$this->stmts($elseif->stmts).'}';
        }

        if ($stmt->else !== null) {
            $out .= " else {\n".$this->stmts($stmt->else->stmts).'}';
        }

        return $out."\n";
    }

    private function foreach(Stmt\Foreach_ $stmt): string
    {
        if ($stmt->byRef) {
            throw $this->error('foreach by reference is not supported in the browser: assign to $array[$key] instead', $stmt);
        }

        if (! $stmt->valueVar instanceof Expr\Variable || ! is_string($stmt->valueVar->name)) {
            throw $this->error('foreach must assign to a plain variable ($item) in the browser', $stmt);
        }

        if ($stmt->keyVar !== null && (! $stmt->keyVar instanceof Expr\Variable || ! is_string($stmt->keyVar->name))) {
            throw $this->error('foreach must assign the key to a plain variable', $stmt);
        }

        $source = $this->expr($stmt->expr);

        return $this->loop($stmt, function ($label) use ($stmt, $source) {
            $k = $this->temp();
            $v = $this->temp();
            $assign = ($stmt->keyVar !== null ? '$'.$stmt->keyVar->name." = {$k}; " : '').'$'.$stmt->valueVar->name." = {$v};\n";

            return "{$label}: for (const [{$k}_, {$v}_] of P.iter({$source})) {\n"
                ."{$k} = {$k}_; {$v} = {$v}_; {$assign}".$this->stmts($stmt->stmts)."}\n";
        });
    }

    private function for(Stmt\For_ $stmt): string
    {
        $init = implode(', ', array_map(fn ($e) => $this->statementExpr($e), $stmt->init));
        $cond = $stmt->cond === [] ? 'true' : 'P.bool(('.implode(', ', array_map(fn ($e) => $this->expr($e), $stmt->cond)).'))';
        $step = implode(', ', array_map(fn ($e) => $this->statementExpr($e), $stmt->loop));

        return $this->loop($stmt, fn ($label) => "{$label}: for ({$init}; {$cond}; {$step}) {\n".$this->stmts($stmt->stmts)."}\n");
    }

    private function switch(Stmt\Switch_ $stmt): string
    {
        $subject = $this->temp();
        $head = "{$subject} = ".$this->expr($stmt->cond).";\n";

        return $head.$this->loop($stmt, function ($label) use ($stmt, $subject) {
            $out = "{$label}: switch (true) {\n";

            foreach ($stmt->cases as $case) {
                foreach ($case->stmts as $inner) {
                    if ($inner instanceof Stmt\Continue_ && $inner->num === null) {
                        throw $this->error('continue inside switch is not supported in the browser: use break', $inner);
                    }
                }

                $out .= $case->cond === null
                    ? "default:\n"
                    : 'case P.eq('.$subject.', '.$this->expr($case->cond)."):\n";
                $out .= $this->stmts($case->stmts);
            }

            return $out."}\n";
        });
    }

    private function loop(Stmt $stmt, callable $build): string
    {
        $label = '$L'.(++$this->labelCounter);
        $this->loops[] = $label;

        try {
            return $build($label);
        } finally {
            array_pop($this->loops);
        }
    }

    private function jump(string $keyword, Stmt\Break_|Stmt\Continue_ $stmt): string
    {
        $levels = 1;

        if ($stmt->num !== null) {
            if (! $stmt->num instanceof Scalar\Int_) {
                throw $this->error("{$keyword} needs a constant number of levels", $stmt);
            }

            $levels = $stmt->num->value;
        }

        if ($levels < 1 || $levels > count($this->loops)) {
            throw $this->error("Cannot '{$keyword} {$levels}': not inside that many loops", $stmt);
        }

        return $keyword.' '.$this->loops[count($this->loops) - $levels].";\n";
    }

    private function unset(Stmt\Unset_ $stmt): string
    {
        $out = '';

        foreach ($stmt->vars as $var) {
            if ($var instanceof Expr\Variable && is_string($var->name)) {
                $out .= '$'.$var->name." = undefined;\n";

                continue;
            }

            [$root, $path] = $this->target($var);

            if ($path === []) {
                throw $this->error('A component property cannot be unset; assign null or reset() it', $var);
            }

            $out .= "{$root} = P.unsetIn({$root}, [".implode(', ', $path)."]);\n";
        }

        return $out;
    }

    // ------------------------------------------------------------ expressions

    /**
     * An expression whose value is not used: assignments and increments need no temporaries.
     */
    private function statementExpr(Expr $expr): string
    {
        if ($expr instanceof Expr\Assign) {
            return $this->assign($expr->var, $this->expr($expr->expr), false);
        }

        if ($expr instanceof Expr\AssignOp) {
            return $this->assignOp($expr, false);
        }

        if ($expr instanceof Expr\PreInc || $expr instanceof Expr\PostInc || $expr instanceof Expr\PreDec || $expr instanceof Expr\PostDec) {
            return $this->increment($expr, false);
        }

        if ($expr instanceof Expr\MethodCall && $this->isThis($expr->var) && $expr->name instanceof Identifier
            && $this->scope->isServerMethod($expr->name->toString())) {
            return $this->serverCall($expr);
        }

        if ($expr instanceof Expr\FuncCall && $expr->name instanceof Name && in_array($this->functionName($expr->name), self::BY_REF, true)) {
            return $this->byRefCall($expr, false);
        }

        return $this->expr($expr);
    }

    private function expr(Expr $e): string
    {
        return match (true) {
            $e instanceof Scalar\String_ => $this->string($e->value),
            $e instanceof Scalar\Int_ => (string) $e->value,
            $e instanceof Scalar\Float_ => $this->float($e->value),
            $e instanceof Scalar\InterpolatedString => $this->interpolated($e),
            $e instanceof Scalar\MagicConst => throw $this->error('Magic constants are not available in the browser', $e),
            $e instanceof Expr\ConstFetch => $this->constant($e),
            $e instanceof Expr\ClassConstFetch => $this->classConstant($e),
            $e instanceof Expr\Variable => $this->variable($e),
            $e instanceof Expr\Array_ => $this->array($e),
            $e instanceof Expr\ArrayDimFetch => $this->dimFetch($e),
            $e instanceof Expr\PropertyFetch, $e instanceof Expr\NullsafePropertyFetch => $this->propertyFetch($e),
            $e instanceof Expr\MethodCall, $e instanceof Expr\NullsafeMethodCall => $this->methodCall($e),
            $e instanceof Expr\FuncCall => $this->funcCall($e),
            $e instanceof Expr\Assign => $this->assign($e->var, $this->expr($e->expr), true),
            $e instanceof Expr\AssignOp => $this->assignOp($e, true),
            $e instanceof Expr\PreInc, $e instanceof Expr\PostInc, $e instanceof Expr\PreDec, $e instanceof Expr\PostDec => $this->increment($e, true),
            $e instanceof Expr\BinaryOp => $this->binary($e),
            $e instanceof Expr\BooleanNot => '!P.bool('.$this->expr($e->expr).')',
            $e instanceof Expr\UnaryMinus => 'P.neg('.$this->expr($e->expr).')',
            $e instanceof Expr\UnaryPlus => 'P.toNum('.$this->expr($e->expr).')',
            $e instanceof Expr\BitwiseNot => '(~P.toInt('.$this->expr($e->expr).'))',
            $e instanceof Expr\Ternary => $this->ternary($e),
            $e instanceof Expr\Cast => $this->cast($e),
            $e instanceof Expr\Isset_ => $this->isset($e),
            $e instanceof Expr\Empty_ => $this->empty($e),
            $e instanceof Expr\Match_ => $this->match($e),
            $e instanceof Expr\Closure => $this->closure($e),
            $e instanceof Expr\ArrowFunction => $this->arrowFunction($e),
            $e instanceof Expr\New_ => throw $this->error('Creating objects (new) is not supported in the browser: move this code to a #[Server] method', $e),
            $e instanceof Expr\StaticCall => $this->staticCall($e),
            $e instanceof Expr\Throw_ => throw $this->error('throw is not supported in the browser: move this code to a #[Server] method', $e),
            $e instanceof Expr\Instanceof_ => throw $this->error('instanceof is not supported in the browser (objects arrive as arrays)', $e),
            $e instanceof Expr\ErrorSuppress => $this->expr($e->expr),
            default => throw $this->error(sprintf('%s is not supported in the browser', $this->describe($e)), $e),
        };
    }

    private function string(string $value): string
    {
        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    }

    private function float(float $value): string
    {
        return match (true) {
            is_nan($value) => 'NaN',
            is_infinite($value) => $value > 0 ? 'Infinity' : '-Infinity',
            default => json_encode($value),
        };
    }

    /** A PHP value known at compile time (constants) as a JS expression. */
    private function literal(mixed $value, Node $node): string
    {
        return match (true) {
            $value === null => 'null',
            is_bool($value) => $value ? 'true' : 'false',
            is_int($value) => (string) $value,
            is_float($value) => $this->float($value),
            is_string($value) => $this->string($value),
            is_array($value) => array_is_list($value)
                ? '['.implode(', ', array_map(fn ($v) => $this->literal($v, $node), $value)).']'
                : 'P.arrayLiteral(['.implode(', ', array_map(fn ($k, $v) => '['.$this->literal($k, $node).', '.$this->literal($v, $node).']', array_keys($value), $value)).'])',
            default => throw $this->error(sprintf('A constant of type %s cannot be used in the browser', get_debug_type($value)), $node),
        };
    }

    private function interpolated(Scalar\InterpolatedString $e): string
    {
        $parts = [];

        foreach ($e->parts as $part) {
            $parts[] = $part instanceof Node\InterpolatedStringPart ? $this->string($part->value) : $this->expr($part);
        }

        return 'P.concat('.implode(', ', $parts).')';
    }

    private function constant(Expr\ConstFetch $e): string
    {
        $name = $e->name->toString();

        return match (strtolower($name)) {
            'true' => 'true',
            'false' => 'false',
            'null' => 'null',
            default => array_key_exists(ltrim($name, '\\'), self::CONSTANTS)
                ? $this->literal(self::CONSTANTS[ltrim($name, '\\')], $e)
                : throw $this->error("The constant {$name} is not available in the browser", $e),
        };
    }

    /**
     * Str::slug($title): Laravel's Str helpers, ported to js/functions (Str_slug). Str is the
     * Illuminate\Support\Str class, as a view (Blade's alias) or a use statement names it.
     */
    private function staticCall(Expr\StaticCall $e): string
    {
        $class = $e->class instanceof Name ? ltrim(($e->class->getAttribute('resolvedName') ?? $e->class)->toString(), '\\') : null;

        if (! in_array($class, ['Str', Str::class], true) || ! $e->name instanceof Identifier) {
            throw $this->error('Static calls are not supported in the browser (Str:: is): move this code to a #[Server] method', $e);
        }

        $function = 'Str_'.$e->name->toString();

        if (! Functions::has($function)) {
            throw $this->error("Str::{$e->name->toString()}() is not available in the browser: move this code to a #[Server] method", $e);
        }

        $this->uses['functions'][$function] = true;

        return 'P.fns.'.$function.'('.implode(', ', $this->args($e->args)).')';
    }

    private function classConstant(Expr\ClassConstFetch $e): string
    {
        if (! $e->class instanceof Name || ! in_array($e->class->toLowerString(), ['self', 'static'], true) || ! $e->name instanceof Identifier) {
            throw $this->error('Only self::CONSTANT and static::CONSTANT of the component can be used in the browser', $e);
        }

        $name = $e->name->toString();

        if (! array_key_exists($name, $this->scope->constants)) {
            throw $this->error("Undefined constant self::{$name}", $e);
        }

        return $this->literal($this->scope->constants[$name], $e);
    }

    private function variable(Expr\Variable $e): string
    {
        if (! is_string($e->name)) {
            throw $this->error('Variable variables ($$name) are not supported in the browser', $e);
        }

        if ($e->name === 'this') {
            throw $this->error('$this can only be used as $this->property or $this->method() in the browser', $e);
        }

        if (isset($this->locals[$e->name])) {
            return '$'.$e->name;
        }

        if ($this->scope->variable !== null && ($resolved = ($this->scope->variable)($e->name)) !== null) {
            return $resolved;
        }

        if ($this->scope->variable !== null) {
            throw $this->error("Undefined variable \${$e->name}: a template can use the component's public properties and its own loop variables", $e);
        }

        return '$'.$e->name;
    }

    private function array(Expr\Array_ $e): string
    {
        $simpleList = true;

        foreach ($e->items as $item) {
            if ($item === null) {
                throw $this->error('Empty array items are not supported', $e);
            }

            if ($item->byRef) {
                throw $this->error('References in arrays are not supported in the browser', $item);
            }

            if ($item->key !== null || $item->unpack) {
                $simpleList = false;
            }
        }

        if ($simpleList) {
            return '['.implode(', ', array_map(fn ($item) => $this->expr($item->value), $e->items)).']';
        }

        $items = array_map(fn ($item) => '['.($item->key === null ? 'undefined' : $this->expr($item->key)).', '.$this->expr($item->value).', '.($item->unpack ? 'true' : 'false').']', $e->items);

        return 'P.arrayLiteral(['.implode(', ', $items).'])';
    }

    private function dimFetch(Expr\ArrayDimFetch $e): string
    {
        if ($e->dim === null) {
            throw $this->error('Cannot use [] for reading', $e);
        }

        return 'P.get('.$this->expr($e->var).', '.$this->expr($e->dim).')';
    }

    private function propertyFetch(Expr\PropertyFetch|Expr\NullsafePropertyFetch $e): string
    {
        if (! $e->name instanceof Identifier) {
            throw $this->error('Dynamic property names are not supported in the browser', $e);
        }

        $name = $e->name->toString();

        if ($this->isThis($e->var)) {
            /** #[Computed]: the method's result, kept until the state changes. */
            if ($this->scope->isComputed($name)) {
                $this->calledMethods[] = $name;

                return '$this.$computed('.$this->string($name).')';
            }

            if (! $this->scope->isProperty($name)) {
                throw $this->error("\$this->{$name} is not a public property of the component", $e);
            }

            return '$this.'.$name;
        }

        return 'P.prop('.$this->expr($e->var).', '.$this->string($name).')';
    }

    private function methodCall(Expr\MethodCall|Expr\NullsafeMethodCall $e): string
    {
        /** $this->dispatch(...)->to(Card::class) / ->self(), as Livewire's. */
        if ($e->var instanceof Expr\MethodCall && $this->isThis($e->var->var) && $e->var->name instanceof Identifier && $e->var->name->toString() === 'dispatch') {
            $dispatch = $this->methodCall($e->var);

            return match ($e->name instanceof Identifier ? $e->name->toString() : '') {
                'self' => $dispatch.'.self()',
                'to' => $dispatch.'.to('.$this->component($e->args[0] ?? null, $e).')',
                default => throw $this->error('A dispatch goes ->to(Component::class) or ->self()', $e),
            };
        }

        /** $slots->has('footer'), $slot->isEmpty(), $slot->isNotEmpty(): the slots the parent passed. */
        foreach (['slots' => ['has'], 'slot' => ['isEmpty', 'isNotEmpty']] as $variable => $methods) {
            if (! $e->var instanceof Expr\Variable || $e->var->name !== $variable || $this->scope->variable === null || isset($this->locals[$variable])) {
                continue;
            }

            $method = $e->name instanceof Identifier ? $e->name->toString() : '';

            return match (true) {
                $variable === 'slots' && $method === 'has' => '$this.$slots.has('.implode(', ', $this->args($e->args)).')',
                $method === 'isEmpty' => '!$this.$slots.has("default")',
                $method === 'isNotEmpty' => '$this.$slots.has("default")',
                default => throw $this->error("\${$variable} has ".implode('(), ', $methods).'() in the browser', $e),
            };
        }

        if ($this->isErrors($e->var)) {
            $method = $e->name instanceof Identifier ? $e->name->toString() : '';

            if (! in_array($method, Globals::ERROR_METHODS, true)) {
                throw $this->error('$errors supports '.implode('(), ', Globals::ERROR_METHODS).'()', $e);
            }

            return Globals::TEMPLATE_VARIABLES['errors'].'.'.$method.'('.implode(', ', $this->args($e->args)).')';
        }

        /** $photo->temporaryUrl(), ->getClientOriginalName(), ...: an Upload's, checked at run time as PHP would. */
        if (! $this->isThis($e->var) && $e->name instanceof Identifier && in_array($e->name->toString(), Globals::UPLOAD_METHODS, true)) {
            $call = 'P.upload('.$this->expr($e->var).', '.$this->string($e->name->toString()).', ['.implode(', ', $this->args($e->args)).'])';

            return $e instanceof Expr\NullsafeMethodCall ? '('.$this->expr($e->var).' === null ? null : '.$call.')' : $call;
        }

        if (! $this->isThis($e->var)) {
            throw $this->error('Only methods of the component ($this->method()) can be called in the browser, and an Upload\'s ('.implode('(), ', Globals::UPLOAD_METHODS).'()); data from the server arrives as arrays', $e);
        }

        if (! $e->name instanceof Identifier) {
            throw $this->error('Dynamic method names are not supported in the browser', $e);
        }

        $name = $e->name->toString();

        if ($this->scope->isServerMethod($name)) {
            throw $this->error("\$this->{$name}() runs on the server: call it as a statement, its return value is not available in the browser", $e);
        }

        if ($this->scope->isClientMethod($name)) {
            $this->calledMethods[] = $name;

            return '$this.'.$name.'('.implode(', ', $this->args($e->args)).')';
        }

        /** Globals::COMPONENT_METHODS */
        return match ($name) {
            'dispatch' => '$this.$dispatch('.$this->dispatchArgs($e->args, $e).')',
            'reset' => '$this.$reset(['.implode(', ', array_map(fn (Arg $a) => $this->propertyName($a, $e), $e->args)).'])',
            'redirect' => '$this.$redirect('.implode(', ', $this->args($e->args)).')',
            'js' => '$this.$js('.implode(', ', $this->args($e->args)).')',
            'shared' => 'B.shared('.implode(', ', $this->args($e->args)).')',
            default => throw $this->error("\$this->{$name}() is not a method of the component that can run in the browser", $e),
        };
    }

    /**
     * A component, for ->to() and $dispatchTo: Card::class (its name, from the application's
     * components) or its name.
     */
    private function component(mixed $arg, Node $node): string
    {
        if (! $arg instanceof Arg) {
            throw $this->error('->to() takes the component: Card::class or its name', $node);
        }

        $value = $arg->value;
        $class = match (true) {
            $value instanceof Expr\ClassConstFetch && $value->class instanceof Name && $value->name instanceof Identifier
                && $value->name->toLowerString() === 'class' => ($value->class->getAttribute('resolvedName') ?? $value->class)->toString(),
            $value instanceof Scalar\String_ && str_contains($value->value, '\\') => $value->value,
            default => null,
        };

        if ($class === null) {
            return $this->expr($value);
        }

        $name = $this->scope->componentName($class) ?? throw $this->error("{$class} is not a Fusion component", $node);

        return $this->string($name);
    }

    private function serverCall(Expr\MethodCall $e): string
    {
        return '$this.$call('.$this->string($e->name->toString()).', ['.implode(', ', $this->args($e->args)).'])';
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
        $this->scope = $scope->withVariables($scope->variable ?? fn () => null, lineOffset: $line - 1);
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

        $this->locals = array_fill_keys([...$locals, ...$declared], true);
        $this->temps = [];
        $this->loops = [];

        $for = $stmts[0];
        $head = 'for ('.implode(', ', array_map(fn ($e) => $this->statementExpr($e), $for->init)).'; '
            .($for->cond === [] ? 'true' : 'P.bool(('.implode(', ', array_map(fn ($e) => $this->expr($e), $for->cond)).'))').'; '
            .implode(', ', array_map(fn ($e) => $this->statementExpr($e), $for->loop)).')';

        if ($this->usesTemporaries()) {
            throw new CompileException('@for is too complex for a template: compute the values in a method', $scope->file, $line);
        }

        return [$head, $declared];
    }

    /** $this->dispatch('event', ...$args or named: arguments). */
    private function dispatchArgs(array $args, Node $node): string
    {
        if ($args === []) {
            throw $this->error('dispatch() needs an event name', $node);
        }

        $name = $this->expr(array_shift($args)->value);
        $named = [];
        $positional = [];

        foreach ($args as $arg) {
            if ($arg->unpack) {
                throw $this->error('Unpacking is not supported in dispatch()', $arg);
            }

            $arg->name !== null
                ? $named[] = '['.$this->string($arg->name->toString()).', '.$this->expr($arg->value).', false]'
                : $positional[] = $this->expr($arg->value);
        }

        if ($named !== [] && $positional !== []) {
            throw $this->error('dispatch() takes either named or positional parameters', $node);
        }

        return $name.', '.($named !== [] ? 'P.arrayLiteral(['.implode(', ', $named).'])' : '['.implode(', ', $positional).']');
    }

    private function propertyName(Arg $arg, Node $node): string
    {
        if (! $arg->value instanceof Scalar\String_ || ! $this->scope->isProperty($arg->value->value)) {
            throw $this->error('reset() takes the names of public properties', $node);
        }

        return $this->string($arg->value->value);
    }

    /** @param  list<Arg|Node\VariadicPlaceholder>  $args */
    private function args(array $args): array
    {
        $out = [];

        foreach ($args as $arg) {
            if (! $arg instanceof Arg) {
                throw $this->error('First-class callable syntax is not supported in the browser', $arg);
            }

            if ($arg->name !== null) {
                throw $this->error('Named arguments are not supported in the browser', $arg);
            }

            if ($arg->byRef) {
                throw $this->error('References are not supported in the browser', $arg);
            }

            $out[] = $arg->unpack ? '...P.spread('.$this->expr($arg->value).')' : $this->expr($arg->value);
        }

        return $out;
    }

    private function functionName(Name $name): string
    {
        $lower = strtolower(ltrim($name->toString(), '\\'));

        return self::ALIASES[$lower] ?? $lower;
    }

    private function funcCall(Expr\FuncCall $e): string
    {
        if ($e->name instanceof Expr\Variable) {
            return $this->expr($e->name).'('.implode(', ', $this->args($e->args)).')';
        }

        if (! $e->name instanceof Name) {
            throw $this->error('Unsupported function call', $e);
        }

        $name = $this->functionName($e->name);

        if (in_array($name, Globals::HELPERS, true)) {
            return $this->helper($name, $e);
        }

        if (in_array($name, self::BY_REF, true)) {
            return $this->byRefCall($e, true);
        }

        if (isset(self::UNSUPPORTED_REFS[$name]) && count($e->args) > self::UNSUPPORTED_REFS[$name]) {
            throw $this->error("{$name}()'s argument ".(self::UNSUPPORTED_REFS[$name] + 1).' (a reference) is not available in the browser', $e);
        }

        if (isset(self::OUT_PARAMS[$name])) {
            return $this->outParamCall($e, $name, self::OUT_PARAMS[$name]);
        }

        if (Functions::has($name)) {
            /** Viewi's: the bundle gets the functions its components call. */
            $this->uses['functions'][$name] = true;
        } elseif (! in_array($name, self::FUNCTIONS, true)) {
            throw $this->error("The function {$name}() is not available in the browser: move this code to a #[Server] method", $e);
        }

        return 'P.fns.'.$name.'('.implode(', ', $this->args($e->args)).')';
    }

    /**
     * route(), url(), asset(), __() / trans(), config(): Laravel's helpers, in blade.js. A route
     * name, a translation key and a config key are literals: the page ships what they name.
     */
    private function helper(string $name, Expr\FuncCall $e): string
    {
        $literal = function (string $what) use ($name, $e): string {
            $first = $e->args[0] ?? null;

            if (! $first instanceof Arg || $first->name !== null || ! $first->value instanceof Scalar\String_) {
                throw $this->error("{$name}() in the browser takes the {$what} as a string literal: the page ships what the code names", $e);
            }

            return $first->value->value;
        };

        $args = $this->args($e->args);

        switch ($name) {
            case 'route':
                $this->uses['routes'][$literal('route name')] = true;

                return 'B.route('.implode(', ', $args).')';
            case '__':
            case 'trans':
                if (count($args) > 2) {
                    throw $this->error("{$name}() in the browser uses the page's locale: pass the key and the replacements", $e);
                }

                $this->uses['lang'][$literal('translation key')] = true;

                return 'B.trans('.implode(', ', $args).')';
            case 'config':
                $this->uses['config'][$literal('config key')] = true;

                return 'B.config('.implode(', ', $args).')';
            default: // url, asset
                if ($args === []) {
                    throw $this->error("{$name}() in the browser needs a path", $e);
                }

                return 'B.'.$name.'('.implode(', ', $args).')';
        }
    }

    /** sort($x), array_pop($this->items), ...: [newArray, returnValue], assigned back to the target. */
    private function byRefCall(Expr\FuncCall $e, bool $valueUsed): string
    {
        $name = $this->functionName($e->name);

        if ($e->args === [] || ! $e->args[0] instanceof Arg) {
            throw $this->error("{$name}() needs an array", $e);
        }

        [$root, $path] = $this->target($e->args[0]->value);
        $rest = $this->args(array_slice($e->args, 1));
        $current = $path === [] ? $root : "P.getIn({$root}, [".implode(', ', $path).'])';
        $call = 'P.fns.'.$name.'('.implode(', ', [$current, ...$rest]).')';
        $result = $this->temp();
        $write = $path === [] ? "{$root} = {$result}[0]" : "{$root} = P.setIn({$root}, [".implode(', ', $path)."], {$result}[0])";

        return "({$result} = {$call}, {$write}".($valueUsed ? ", {$result}[1])" : ')');
    }

    /**
     * preg_match($pattern, $subject, $matches): the function returns [result, output] in the
     * browser; the output is assigned where PHP's reference would write it.
     */
    private function outParamCall(Expr\FuncCall $e, string $name, int $position): string
    {
        $this->uses['functions'][$name] = true;
        $out = $e->args[$position] ?? null;

        if ($out !== null && (! $out instanceof Arg || $out->name !== null || $out->unpack)) {
            throw $this->error("{$name}()'s argument ".($position + 1).' must be a variable or property', $e);
        }

        $rest = $this->args([...array_slice($e->args, 0, $position), ...array_slice($e->args, $position + 1)]);
        $call = 'P.fns.'.$name.'('.implode(', ', $rest).')';

        if ($out === null) {
            return $call.'[0]';
        }

        [$root, $path] = $this->target($out->value);
        $result = $this->temp();
        $write = $path === [] ? "{$root} = {$result}[1]" : "{$root} = P.setIn({$root}, [".implode(', ', $path)."], {$result}[1])";

        return "({$result} = {$call}, {$write}, {$result}[0])";
    }

    // ------------------------------------------------------------ writes

    /**
     * What an assignment writes to: a root (`$x` or `$this.prop`) and the key path under it
     * (JS key expressions; null appends).
     *
     * @return array{0: string, 1: list<string>}
     */
    private function target(Expr $e): array
    {
        if ($e instanceof Expr\Variable) {
            if (! is_string($e->name) || $e->name === 'this') {
                throw $this->error('Cannot assign to this variable in the browser', $e);
            }

            if ($this->scope->variable !== null && ! isset($this->locals[$e->name])) {
                throw $this->error('A template cannot assign to variables', $e);
            }

            $this->locals[$e->name] = true;

            return ['$'.$e->name, []];
        }

        if ($e instanceof Expr\PropertyFetch && $this->isThis($e->var) && $e->name instanceof Identifier) {
            $name = $e->name->toString();

            if ($this->scope->isComputed($name)) {
                throw $this->error("\$this->{$name} is #[Computed]: it is worked out from the state, change the properties it reads instead", $e);
            }

            if (! $this->scope->isProperty($name)) {
                throw $this->error("\$this->{$name} is not a public property of the component", $e);
            }

            if ($this->scope->isLocked($name)) {
                throw $this->error("\$this->{$name} is #[Locked]: only the server (mount() or a #[Server] method) can change it", $e);
            }

            return ['$this.'.$name, []];
        }

        if ($e instanceof Expr\ArrayDimFetch) {
            [$root, $path] = $this->target($e->var);
            $path[] = $e->dim === null ? 'null' : $this->expr($e->dim);

            return [$root, $path];
        }

        if ($e instanceof Expr\PropertyFetch && $e->name instanceof Identifier) {
            [$root, $path] = $this->target($e->var);

            if (! str_starts_with($root, '$this.')) {
                throw $this->error('Assigning to a property of a value only works through a component property ($this->post->title = ...): values are copies in the browser', $e);
            }

            $path[] = $this->string($e->name->toString());

            return [$root, $path];
        }

        throw $this->error('Cannot assign to this expression in the browser', $e);
    }

    private function assign(Expr $var, string $value, bool $valueUsed): string
    {
        if ($var instanceof Expr\List_ || $var instanceof Expr\Array_) {
            return $this->destructure($var, $value, $valueUsed);
        }

        [$root, $path] = $this->target($var);

        if ($path === []) {
            return "({$root} = {$value})";
        }

        if (! $valueUsed) {
            return "{$root} = P.setIn({$root}, [".implode(', ', $path)."], {$value})";
        }

        $t = $this->temp();

        return "({$root} = P.setIn({$root}, [".implode(', ', $path)."], {$t} = {$value}), {$t})";
    }

    /**
     * [$a, $b] = $value, list($a, , $c) = ..., ['x' => $x] = ..., [[$a, $b], $c] = ...: as PHP
     * does it, the value first (so [$a, $b] = [$b, $a] swaps), then each target in order. Its
     * value is the value assigned.
     */
    private function destructure(Expr\List_|Expr\Array_ $var, string $value, bool $valueUsed): string
    {
        $source = $this->temp();
        $parts = ["{$source} = {$value}", ...$this->destructureInto($var, $source)];

        if ($valueUsed) {
            $parts[] = $source;
        }

        return '('.implode(', ', $parts).')';
    }

    /** @return list<string> the writes, in order */
    private function destructureInto(Expr\List_|Expr\Array_ $var, string $source): array
    {
        $writes = [];
        $position = 0;

        foreach ($var->items as $item) {
            /** list($a, , $c): a skipped position */
            if ($item === null) {
                $position++;

                continue;
            }

            if ($item->byRef) {
                throw $this->error('References are not supported in the browser', $item);
            }

            if ($item->unpack) {
                throw $this->error('Spread (...) cannot be used when destructuring', $item);
            }

            $element = 'P.listItem('.$source.', '.($item->key === null ? (string) $position++ : $this->expr($item->key)).')';

            if ($item->value instanceof Expr\List_ || $item->value instanceof Expr\Array_) {
                $nested = $this->temp();
                $writes[] = "{$nested} = {$element}";
                array_push($writes, ...$this->destructureInto($item->value, $nested));
            } else {
                $writes[] = $this->assign($item->value, $element, false);
            }
        }

        return $writes;
    }

    private function assignOp(Expr\AssignOp $e, bool $valueUsed): string
    {
        [$root, $path] = $this->target($e->var);
        [$root, $path, $prefix] = $this->stablePath($root, $path);
        $current = $path === [] ? $root : "P.getIn({$root}, [".implode(', ', $path).'])';
        $operand = $this->expr($e->expr);

        $value = match (true) {
            $e instanceof Expr\AssignOp\Plus => "P.add({$current}, {$operand})",
            $e instanceof Expr\AssignOp\Minus => "P.sub({$current}, {$operand})",
            $e instanceof Expr\AssignOp\Mul => "P.mul({$current}, {$operand})",
            $e instanceof Expr\AssignOp\Div => "P.div({$current}, {$operand})",
            $e instanceof Expr\AssignOp\Mod => "P.mod({$current}, {$operand})",
            $e instanceof Expr\AssignOp\Pow => "P.pow({$current}, {$operand})",
            $e instanceof Expr\AssignOp\Concat => "P.concat({$current}, {$operand})",
            $e instanceof Expr\AssignOp\Coalesce => "P.coalesce({$current}, () => {$operand})",
            $e instanceof Expr\AssignOp\BitwiseAnd => "(P.toInt({$current}) & P.toInt({$operand}))",
            $e instanceof Expr\AssignOp\BitwiseOr => "(P.toInt({$current}) | P.toInt({$operand}))",
            $e instanceof Expr\AssignOp\BitwiseXor => "(P.toInt({$current}) ^ P.toInt({$operand}))",
            $e instanceof Expr\AssignOp\ShiftLeft => "(P.toInt({$current}) << P.toInt({$operand}))",
            $e instanceof Expr\AssignOp\ShiftRight => "(P.toInt({$current}) >> P.toInt({$operand}))",
            default => throw $this->error('Unsupported assignment operator', $e),
        };

        $write = $this->write($root, $path, $value, $valueUsed);

        return $prefix === '' ? $write : "({$prefix}{$write})";
    }

    private function increment(Expr\PreInc|Expr\PostInc|Expr\PreDec|Expr\PostDec $e, bool $valueUsed): string
    {
        [$root, $path] = $this->target($e->var);
        [$root, $path, $prefix] = $this->stablePath($root, $path);
        $current = $path === [] ? $root : "P.getIn({$root}, [".implode(', ', $path).'])';
        $fn = $e instanceof Expr\PreInc || $e instanceof Expr\PostInc ? 'P.inc' : 'P.dec';
        $post = $e instanceof Expr\PostInc || $e instanceof Expr\PostDec;

        if (! $valueUsed) {
            $write = $this->write($root, $path, "{$fn}({$current})", false);

            return $prefix === '' ? $write : "({$prefix}{$write})";
        }

        $old = $this->temp();

        if ($post) {
            return "({$prefix}{$old} = {$current}, ".$this->write($root, $path, "{$fn}({$old})", false).", {$old})";
        }

        return "({$prefix}{$old} = {$fn}({$current}), ".$this->write($root, $path, $old, false).", {$old})";
    }

    private function write(string $root, array $path, string $value, bool $valueUsed): string
    {
        if ($path === []) {
            return $valueUsed ? "({$root} = {$value})" : "{$root} = {$value}";
        }

        if (! $valueUsed) {
            return "{$root} = P.setIn({$root}, [".implode(', ', $path)."], {$value})";
        }

        $t = $this->temp();

        return "({$root} = P.setIn({$root}, [".implode(', ', $path)."], {$t} = {$value}), {$t})";
    }

    /**
     * Keys read and then written ($a[f()] += 1) are evaluated once, into temporaries.
     *
     * @return array{0: string, 1: list<string>, 2: string}
     */
    private function stablePath(string $root, array $path): array
    {
        $prefix = '';

        foreach ($path as $i => $key) {
            if ($key === 'null') {
                throw new CompileException('[] cannot be used with a compound assignment', $this->scope->file);
            }

            if (! preg_match('/^(\$\w+|-?\d+(\.\d+)?|"(?:[^"\\\\]|\\\\.)*")$/', $key)) {
                $t = $this->temp();
                $prefix .= "{$t} = {$key}, ";
                $path[$i] = $t;
            }
        }

        return [$root, $path, $prefix];
    }

    // ------------------------------------------------------------ operators

    private function binary(Expr\BinaryOp $e): string
    {
        $l = fn () => $this->expr($e->left);
        $r = fn () => $this->expr($e->right);

        return match (true) {
            $e instanceof Expr\BinaryOp\Plus => 'P.add('.$l().', '.$r().')',
            $e instanceof Expr\BinaryOp\Minus => 'P.sub('.$l().', '.$r().')',
            $e instanceof Expr\BinaryOp\Mul => 'P.mul('.$l().', '.$r().')',
            $e instanceof Expr\BinaryOp\Div => 'P.div('.$l().', '.$r().')',
            $e instanceof Expr\BinaryOp\Mod => 'P.mod('.$l().', '.$r().')',
            $e instanceof Expr\BinaryOp\Pow => 'P.pow('.$l().', '.$r().')',
            $e instanceof Expr\BinaryOp\Concat => 'P.concat('.$l().', '.$r().')',
            $e instanceof Expr\BinaryOp\Equal => 'P.eq('.$l().', '.$r().')',
            $e instanceof Expr\BinaryOp\NotEqual => 'P.neq('.$l().', '.$r().')',
            $e instanceof Expr\BinaryOp\Identical => 'P.identical('.$l().', '.$r().')',
            $e instanceof Expr\BinaryOp\NotIdentical => 'P.notIdentical('.$l().', '.$r().')',
            $e instanceof Expr\BinaryOp\Smaller => 'P.lt('.$l().', '.$r().')',
            $e instanceof Expr\BinaryOp\SmallerOrEqual => 'P.lte('.$l().', '.$r().')',
            $e instanceof Expr\BinaryOp\Greater => 'P.gt('.$l().', '.$r().')',
            $e instanceof Expr\BinaryOp\GreaterOrEqual => 'P.gte('.$l().', '.$r().')',
            $e instanceof Expr\BinaryOp\Spaceship => 'P.cmp('.$l().', '.$r().')',
            $e instanceof Expr\BinaryOp\BooleanAnd, $e instanceof Expr\BinaryOp\LogicalAnd => '(P.bool('.$l().') && P.bool('.$r().'))',
            $e instanceof Expr\BinaryOp\BooleanOr, $e instanceof Expr\BinaryOp\LogicalOr => '(P.bool('.$l().') || P.bool('.$r().'))',
            $e instanceof Expr\BinaryOp\LogicalXor => '(P.bool('.$l().') !== P.bool('.$r().'))',
            $e instanceof Expr\BinaryOp\Coalesce => 'P.coalesce('.$l().', () => '.$r().')',
            $e instanceof Expr\BinaryOp\BitwiseAnd => '(P.toInt('.$l().') & P.toInt('.$r().'))',
            $e instanceof Expr\BinaryOp\BitwiseOr => '(P.toInt('.$l().') | P.toInt('.$r().'))',
            $e instanceof Expr\BinaryOp\BitwiseXor => '(P.toInt('.$l().') ^ P.toInt('.$r().'))',
            $e instanceof Expr\BinaryOp\ShiftLeft => '(P.toInt('.$l().') << P.toInt('.$r().'))',
            $e instanceof Expr\BinaryOp\ShiftRight => '(P.toInt('.$l().') >> P.toInt('.$r().'))',
            default => throw $this->error('Unsupported operator', $e),
        };
    }

    private function ternary(Expr\Ternary $e): string
    {
        if ($e->if === null) {
            $t = $this->temp();

            return "(P.bool({$t} = ".$this->expr($e->cond).") ? {$t} : ".$this->expr($e->else).')';
        }

        return '(P.bool('.$this->expr($e->cond).') ? '.$this->expr($e->if).' : '.$this->expr($e->else).')';
    }

    private function cast(Expr\Cast $e): string
    {
        $inner = $this->expr($e->expr);

        return match (true) {
            $e instanceof Expr\Cast\Int_ => "P.toInt({$inner})",
            $e instanceof Expr\Cast\Double => "P.toFloat({$inner})",
            $e instanceof Expr\Cast\String_ => "P.toStr({$inner})",
            $e instanceof Expr\Cast\Bool_ => "P.bool({$inner})",
            $e instanceof Expr\Cast\Array_ => "P.toArr({$inner})",
            default => throw $this->error('This cast is not supported in the browser', $e),
        };
    }

    /**
     * isset($a['x']['y'], $this->prop['k']): a base and a key path, checked without reading
     * missing keys.
     */
    private function isset(Expr\Isset_ $e): string
    {
        return '('.implode(' && ', array_map(fn ($var) => $this->existence('P.isset', $var), $e->vars)).')';
    }

    private function empty(Expr\Empty_ $e): string
    {
        return $this->existence('P.empty', $e->expr);
    }

    private function existence(string $fn, Expr $e): string
    {
        $path = [];

        while (true) {
            if ($e instanceof Expr\ArrayDimFetch && $e->dim !== null) {
                array_unshift($path, $this->expr($e->dim));
                $e = $e->var;
            } elseif (($e instanceof Expr\PropertyFetch || $e instanceof Expr\NullsafePropertyFetch)
                && $e->name instanceof Identifier && ! $this->isThis($e->var)) {
                array_unshift($path, $this->string($e->name->toString()));
                $e = $e->var;
            } else {
                break;
            }
        }

        $base = $e instanceof Expr\Variable && is_string($e->name) && ! isset($this->locals[$e->name]) && $this->scope->variable === null
            ? '$'.$e->name
            : $this->expr($e);

        return "{$fn}({$base}, [".implode(', ', $path).'])';
    }

    private function match(Expr\Match_ $e): string
    {
        $subject = $this->temp();
        $out = "(({$subject}) => {\n";
        $default = null;

        foreach ($e->arms as $arm) {
            if ($arm->conds === null) {
                $default = $this->expr($arm->body);

                continue;
            }

            $conds = array_map(fn ($c) => "P.identical({$subject}, ".$this->expr($c).')', $arm->conds);
            $out .= 'if ('.implode(' || ', $conds).') return '.$this->expr($arm->body).";\n";
        }

        $out .= $default !== null
            ? "return {$default};\n"
            : "throw new P.PhpError('UnhandledMatchError', 'Unhandled match case ' + P.jsonEncode({$subject}));\n";

        return $out.'})('.$this->expr($e->cond).')';
    }

    private function closure(Expr\Closure $e): string
    {
        $captured = [];

        foreach ($e->uses as $use) {
            if ($use->byRef) {
                throw $this->error('Closures capturing by reference (use (&$x)) are not supported in the browser', $e);
            }

            $captured[] = '$'.$use->var->name;
        }

        $outerLocals = $this->locals;
        $function = $this->functionWithCaptures($e->params, $e->stmts, array_map(fn ($c) => substr($c, 1), $captured));
        $this->locals = $outerLocals;

        if ($captured === []) {
            return "({$function})";
        }

        $list = implode(', ', $captured);

        return "(({$list}) => {$function})({$list})";
    }

    private function arrowFunction(Expr\ArrowFunction $e): string
    {
        /** Arrow functions capture the outer variables they use, by value. */
        $params = array_map(fn (Param $p) => $p->var->name, $e->params);
        $used = array_diff($this->variablesIn([$e->expr]), $params);
        $captured = array_values(array_filter($used, fn ($v) => isset($this->locals[$v])));

        $outerLocals = $this->locals;
        $function = $this->functionWithCaptures($e->params, [new Stmt\Return_($e->expr)], $captured);
        $this->locals = $outerLocals;

        if ($captured === []) {
            return "({$function})";
        }

        $list = implode(', ', array_map(fn ($c) => '$'.$c, $captured));

        return "(({$list}) => {$function})({$list})";
    }

    /**
     * A closure body: its own locals plus the captured variables (parameters of the capturing
     * wrapper), keeping the template's variable resolution.
     *
     * @param  list<Param>  $params
     * @param  list<Stmt>  $stmts
     * @param  list<string>  $captured
     */
    private function functionWithCaptures(array $params, array $stmts, array $captured): string
    {
        [$savedLocals, $savedTemps, $savedLoops] = [$this->locals, $this->temps, $this->loops];
        $this->locals = array_fill_keys($captured, true);
        $this->temps = [];
        $this->loops = [];

        $signature = [];
        $coercions = '';

        foreach ($params as $param) {
            $signature[] = $this->param($param);
            $coercions .= $this->coercion($param, '{closure}');
            $this->locals[$param->var->name] = true;
        }

        $own = $this->locals;

        foreach ($this->variablesIn($stmts) as $variable) {
            if (! isset($own[$variable]) && $this->scope->variable !== null && ($this->scope->variable)($variable) !== null) {
                continue; // a template variable (component property), resolved when used
            }

            $this->locals[$variable] = true;
        }

        $body = $this->stmts($stmts);
        $declared = array_diff_key($this->locals, $own, ['this' => true]);
        $head = ($declared === [] ? '' : 'let '.implode(', ', array_map(fn ($v) => '$'.$v, array_keys($declared))).";\n")
            .$this->declareTemps().$coercions;

        [$this->locals, $this->temps, $this->loops] = [$savedLocals, $savedTemps, $savedLoops];

        return 'function ('.implode(', ', $signature).") {\n{$head}{$body}}";
    }

    // ------------------------------------------------------------ handlers

    private function handlerCall(Expr $e): string
    {
        /** A bare name ("save") is a method with no arguments. */
        if ($e instanceof Expr\ConstFetch) {
            $e = new Expr\MethodCall(new Expr\Variable('this'), new Identifier($e->name->toString()), [], $e->getAttributes());
        }

        if ($e instanceof Expr\FuncCall && $e->name instanceof Expr\Variable && is_string($e->name->name)) {
            return $this->magicAction($e->name->name, $e);
        }

        /** $parent->remove($id): a method of the component around this one (in the browser or on the server). */
        if ($e instanceof Expr\MethodCall && $e->var instanceof Expr\Variable && $e->var->name === 'parent' && ! isset($this->locals['parent'])) {
            if (! $e->name instanceof Identifier) {
                throw $this->error('$parent takes a method name', $e);
            }

            return '$this.$parent('.$this->string($e->name->toString()).', ['.implode(', ', $this->args($e->args)).'])';
        }

        if ($e instanceof Expr\Variable && $e->name === 'refresh') {
            return '$this.$refresh()';
        }

        if ($e instanceof Expr\FuncCall && $e->name instanceof Name && ! str_contains($e->name->toString(), '\\')) {
            $e = new Expr\MethodCall(new Expr\Variable('this'), new Identifier($e->name->toString()), $e->args, $e->getAttributes());
        }

        if (! $e instanceof Expr\MethodCall || ! $this->isThis($e->var) || ! $e->name instanceof Identifier) {
            throw $this->error('An event handler is a method call, such as "save" or "remove($id)"', $e);
        }

        $name = $e->name->toString();

        if ($this->scope->isServerMethod($name)) {
            return $this->serverCall($e);
        }

        return $this->methodCall($e);
    }

    /** $set, $toggle, $refresh, $dispatch (Globals::ACTIONS). */
    private function magicAction(string $name, Expr\FuncCall $e): string
    {
        if (! array_key_exists($name, Globals::ACTIONS)) {
            throw $this->error("Unknown action \${$name}: handlers can use \$".implode(', $', array_keys(Globals::ACTIONS)), $e);
        }

        $arity = Globals::ACTIONS[$name];

        if ($arity !== null && count($e->args) !== $arity) {
            throw $this->error("\${$name} takes {$arity} argument(s)", $e);
        }

        /** $dispatchTo('card', 'event', ...), $dispatchSelf('event', ...): Livewire's. */
        if ($name === 'dispatchTo') {
            $to = $this->component($e->args[0] ?? null, $e);

            return '$this.$dispatch('.$this->dispatchArgs(array_slice($e->args, 1), $e).').to('.$to.')';
        }

        if ($name === 'dispatchSelf') {
            return '$this.$dispatch('.$this->dispatchArgs($e->args, $e).').self()';
        }

        $args = match ($name) {
            'set' => [$this->propertyPath($e->args[0], $e), $this->expr($e->args[1]->value)],
            'toggle' => [$this->propertyPath($e->args[0], $e)],
            'dispatch' => [$this->dispatchArgs($e->args, $e)],
            default => [],
        };

        return '$this.$'.$name.'('.implode(', ', $args).')';
    }

    /** 'prop' or 'prop.key.key' naming a public property (and keys under it). */
    private function propertyPath(Arg $arg, Node $node): string
    {
        if (! $arg->value instanceof Scalar\String_) {
            throw $this->error('The property must be a string literal', $node);
        }

        $root = explode('.', $arg->value->value)[0];

        if (! $this->scope->isProperty($root)) {
            throw $this->error("{$root} is not a public property of the component", $node);
        }

        return $this->string($arg->value->value);
    }

    // ------------------------------------------------------------ helpers

    private function isThis(Expr $e): bool
    {
        return $e instanceof Expr\Variable && $e->name === 'this';
    }

    /** $errors in a template (not a local of that name). */
    private function isErrors(Expr $e): bool
    {
        return $e instanceof Expr\Variable && $e->name === 'errors' && $this->scope->variable !== null && ! isset($this->locals['errors']);
    }

    private function forbidWrites(Expr $expr): void
    {
        $walk = function ($node) use (&$walk) {
            if (is_array($node)) {
                array_walk($node, $walk);

                return;
            }

            if (! $node instanceof Node || $node instanceof Expr\Closure || $node instanceof Expr\ArrowFunction) {
                return;
            }

            if ($node instanceof Expr\Assign || $node instanceof Expr\AssignOp || $node instanceof Expr\PreInc
                || $node instanceof Expr\PostInc || $node instanceof Expr\PreDec || $node instanceof Expr\PostDec) {
                throw $this->error('A template expression cannot assign or increment: change state in a component method', $node);
            }

            if ($node instanceof Expr\MethodCall && $this->isThis($node->var) && $node->name instanceof Identifier
                && $this->scope->isServerMethod($node->name->toString())) {
                throw $this->error("\$this->{$node->name}() runs on the server and cannot be called while rendering", $node);
            }

            foreach ($node->getSubNodeNames() as $sub) {
                $walk($node->$sub);
            }
        };

        $walk($expr);
    }

    private function describe(Node $node): string
    {
        $class = substr(strrchr(get_class($node), '\\'), 1);

        return rtrim($class, '_');
    }

    private function error(string $message, Node $node): CompileException
    {
        $line = $node->getStartLine();

        return new CompileException($message, $this->scope->file, $line > 0 ? $line + $this->scope->lineOffset : null);
    }
}
