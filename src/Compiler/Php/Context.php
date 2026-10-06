<?php

namespace Nitro\Fusion\Compiler\Php;

use Closure;
use Nitro\Fusion\Compiler\CompileException;
use Nitro\Fusion\Compiler\Scope;
use PhpParser\Node;
use PhpParser\Node\Expr;

/**
 * The state the parts of the transpiler share while compiling one piece of code (a method, a
 * template expression, an event handler, a @for head), and the small helpers every part needs.
 *
 * General PHP-to-JavaScript state: the variables declared in the function being compiled, its
 * temporaries ($$1, $$2: values computed once and reused), the labels of the loops around the
 * code (for break 2 / continue 2), and how errors name the file and line.
 *
 * Fusion state: the component's Scope (its properties, methods, constants, the browser's names
 * of an obfuscated build), the variables holding a Fusion::browser() API, the component methods
 * the code calls, and what the Laravel helpers name (routes, translations, config) so the page
 * ships them.
 *
 * Transpiler binds its public $calledMethods, $uses and $substitute to the properties here (by
 * reference), so the ComponentCompiler and TemplateCompiler keep reading and setting them on the
 * Transpiler.
 *
 * @internal
 */
final class Context
{
    /** @var array<string, true> variables declared in the function being compiled */
    public array $locals = [];

    /** @var list<string> temporaries of the function being compiled */
    public array $temps = [];

    public int $tempCounter = 0;

    /** @var list<string> labels of the enclosing loops and switches, innermost last */
    public array $loops = [];

    public int $labelCounter = 0;

    /** @var array<string, class-string> variables holding a Fusion::browser() API: variable => its class */
    public array $browserVariables = [];

    /** @var list<string> component methods the compiled code calls (read and reset by the caller) */
    public array $calledMethods = [];

    /**
     * In a view inlined by BladeInliner: a variable's replacement (null keeps it), applied to
     * every template expression, handler and @for as it is parsed. Set by the TemplateCompiler.
     *
     * @var (Closure(string): ?Expr)|null
     */
    public ?Closure $substitute = null;

    /** A view's imports (its use directives), as PHP use statements ("use App\Enums\Status;"). Set by the TemplateCompiler. */
    public string $imports = '';

    /** @var array{routes: array<string, true>, lang: array<string, true>, config: array<string, true>, functions: array<string, true>, classes: array<string, true>} what the Laravel helpers name, and the functions of js/functions called */
    public array $uses = ['routes' => [], 'lang' => [], 'config' => [], 'functions' => [], 'classes' => []];

    /** @var class-string|null compiling a #[Browser] class's or enum's method: the class (self::, static::) */
    public ?string $class = null;

    /** Compiling an enum's instance method: $this is the case (its ->name, ->value and methods). */
    public bool $enumCase = false;

    /** Compiling updated(): its string literals that name a property are the browser's names (an obfuscated build). */
    public bool $updatedHook = false;

    public function __construct(public Scope $scope) {}

    // ------------------------------------------------------------ temporaries

    /** A new temporary of the function being compiled ($$1, $$2, ...), declared at its top. */
    public function temp(): string
    {
        $name = '$$'.(++$this->tempCounter);
        $this->temps[] = $name;

        return $name;
    }

    public function usesTemporaries(): bool
    {
        return count($this->temps) > 0;
    }

    public function declareTemps(): string
    {
        return $this->temps === [] ? '' : 'let '.implode(', ', $this->temps).";\n";
    }

    /** Whether the code compiled since the temporaries were reset needed any. */
    public function hasTemps(): bool
    {
        return $this->temps !== [];
    }

    /** An expression that needed temporaries, as one: a function declaring them, called at once. */
    public function withTemps(string $body, bool $arrow): string
    {
        return '(() => {'.$this->declareTemps().$body.'})()';
    }

    // ------------------------------------------------------------ JavaScript values

    /** A PHP string as a JavaScript string literal. */
    public function string(string $value): string
    {
        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    }

    /** A PHP float as a JavaScript number (NaN and the infinities included). */
    public function float(float $value): string
    {
        return match (true) {
            is_nan($value) => 'NaN',
            is_infinite($value) => $value > 0 ? 'Infinity' : '-Infinity',
            default => json_encode($value),
        };
    }

    // ------------------------------------------------------------ what a node is

    /** $this: the component, in the browser. */
    public function isThis(Expr $e): bool
    {
        return $e instanceof Expr\Variable && $e->name === 'this';
    }

    /** $errors in a template (not a local of that name). */
    public function isErrors(Expr $e): bool
    {
        return $e instanceof Expr\Variable && $e->name === 'errors' && $this->scope->variable !== null && ! isset($this->locals['errors']);
    }

    /** A node's kind for an error message: "Foreach", "Goto". */
    public function describe(Node $node): string
    {
        $class = substr(strrchr(get_class($node), '\\'), 1);

        return rtrim($class, '_');
    }

    /** A compile error at the node: the component's file and the line in it. */
    public function error(string $message, Node $node): CompileException
    {
        $line = $node->getStartLine();

        return new CompileException($message, $this->scope->file, $line > 0 ? $line + $this->scope->lineOffset : null);
    }
}
