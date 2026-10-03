<?php

namespace Nitro\Fusion;

use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Nitro\Fusion\Compiler\CompileException;
use Nitro\Fusion\Support\Naming;
use PhpParser\ConstExprEvaluator;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr;
use PhpParser\Node\Stmt;
use PhpParser\ParserFactory;
use PhpParser\PhpVersion;
use Symfony\Component\Finder\Finder;
use Throwable;

/**
 * Pages that are only a view: a controller returns one with Fusion::render('employees.index',
 * [...]), as Inertia::render() returns a page, and its actions are the controller's routes
 * ($post(), $put(), $patch(), $delete() in the view). No page class is written.
 *
 * The view declares what it receives and what it keeps, at its top:
 *
 *   @page(
 *       title: 'Employees',
 *       props: ['employees', 'headcount' => ['total' => 0]],   // from the controller: read-only here
 *       state: ['hiring' => false, 'form' => ['name' => '']],  // the page's own, the browser changes it
 *       remember: ['form'],                                    // state kept in the history entry
 *       url: ['tab'],                                          // state kept in the query string
 *   )
 *
 * Fusion turns that into a page class (generated, in the compiled directory, made again when the
 * view changes): props are #[Locked] properties, state plain ones, remember and url #[Remember]
 * and #[Url], title #[Title]. Everything else (rendering, the bundle, signed state, partial
 * reloads, tests) treats it as any page. A view page is a view under the pages' view path
 * (fusion.pages.views) with @page(...) and no page class of its own name.
 */
final class ViewPages
{
    public const NAMESPACE = 'Nitro\\Fusion\\ViewPages';

    /** What @page(...) may name. */
    private const KEYS = ['title', 'props', 'state', 'remember', 'url'];

    public function __construct(
        private readonly ViewFactory $views,
        /** The pages' view path, as a view name prefix ("fusion.pages") */
        private readonly string $prefix,
        /** Where the generated classes go */
        private readonly string $directory,
    ) {
    }

    /**
     * Every view page: name ("pages::employees.index") => its generated class.
     *
     * @return array<string, class-string<Component>>
     */
    public function discover(): array
    {
        $pages = [];

        foreach ($this->roots() as $root) {
            foreach (Finder::create()->files()->in($root)->name('*.blade.php') as $file) {
                if (self::declaration((string) file_get_contents($file->getPathname())) === null) {
                    continue;
                }

                $relative = substr(str_replace('\\', '/', $file->getRelativePathname()), 0, -strlen('.blade.php'));
                $name = Naming::PAGES.str_replace('/', '.', $relative);

                if (Naming::isValid(Naming::bare($name))) {
                    $pages[$name] ??= self::className($name);
                }
            }
        }

        return $pages;
    }

    /** "pages::fee-plans.index" => Nitro\Fusion\ViewPages\FeePlans_Index. */
    public static function className(string $name): string
    {
        return self::NAMESPACE.'\\'.implode('_', array_map(fn (string $segment) => Str::studly($segment), explode('.', Naming::bare($name))));
    }

    /** The page a generated class is for, or null for a class that is not one. */
    public static function pageName(string $class): ?string
    {
        $class = ltrim($class, '\\');

        if (! str_starts_with($class, self::NAMESPACE.'\\')) {
            return null;
        }

        return Naming::PAGES.implode('.', array_map(fn (string $segment) => Str::kebab($segment), explode('_', substr($class, strlen(self::NAMESPACE) + 1))));
    }

    /** The autoloader: a generated class, made (again) from its view when the view is newer. */
    public function load(string $class): bool
    {
        $name = self::pageName($class);
        $view = $name === null ? null : $this->viewFile($name);

        if ($view === null) {
            return false;
        }

        $file = $this->directory.DIRECTORY_SEPARATOR.'view-pages'.DIRECTORY_SEPARATOR.substr($class, strlen(self::NAMESPACE) + 1).'.php';

        if (! is_file($file) || filemtime($file) < filemtime($view)) {
            $source = $this->generate($name, $view);
            @mkdir(dirname($file), 0777, true);

            /** Written whole, then moved into place: a request never reads half a class. */
            $temporary = $file.'.'.bin2hex(random_bytes(4)).'.tmp';
            file_put_contents($temporary, $source);
            rename($temporary, $file);
        }

        require_once $file;

        return class_exists($class, false);
    }

    /**
     * The page class for a view page's @page(...).
     *
     * @throws CompileException when the declaration is not what @page takes
     */
    public function generate(string $name, string $view): string
    {
        $declared = self::declaration((string) file_get_contents($view))
            ?? throw new CompileException('A view page starts with @page(props: [...], state: [...])', $view);

        $class = substr(self::className($name), strlen(self::NAMESPACE) + 1);
        $lines = [];

        foreach ($declared['props'] as $property => $default) {
            $lines[] = '    #[\\Nitro\\Fusion\\Attributes\\Locked]';
            $lines[] = '    public mixed $'.$property.' = '.self::export($default).';';
            $lines[] = '';
        }

        foreach ($declared['state'] as $property => $default) {
            if (in_array($property, $declared['url'], true)) {
                $lines[] = '    #[\\Nitro\\Fusion\\Attributes\\Url]';
            }

            if (in_array($property, $declared['remember'], true)) {
                $lines[] = '    #[\\Nitro\\Fusion\\Attributes\\Remember]';
            }

            $lines[] = '    public mixed $'.$property.' = '.self::export($default).';';
            $lines[] = '';
        }

        $title = $declared['title'] === null ? '' : '#[\\Nitro\\Fusion\\Attributes\\Title('.var_export($declared['title'], true).")]\n";

        return "<?php\n\nnamespace ".self::NAMESPACE.";\n\n"
            ."/**\n * Generated by Fusion from ".str_replace('*/', '*\\/', $view)."'s @page(...): do not edit.\n * It is made again when the view changes.\n */\n"
            .$title.'final class '.$class." extends \\Nitro\\Fusion\\Component\n{\n".rtrim(implode("\n", $lines))."\n}\n";
    }

    /**
     * A view's @page(...), read: its props and state with their defaults, the state kept in the
     * history entry (remember) and in the query string (url), its title. Null when the view has
     * no @page.
     *
     * @return array{title: ?string, props: array<string, mixed>, state: array<string, mixed>, remember: list<string>, url: list<string>}|null
     *
     * @throws CompileException when @page is malformed
     */
    public static function declaration(string $source): ?array
    {
        if (! preg_match('/(?<!@)@page\s*\(/', $source, $m, PREG_OFFSET_CAPTURE)) {
            return null;
        }

        $args = self::arguments($source, $m[0][1] + strlen($m[0][0]));

        try {
            $stmts = (new ParserFactory)->createForVersion(PhpVersion::fromComponents(8, 3))->parse('<?php page('.$args.');') ?? [];
        } catch (Throwable $e) {
            throw new CompileException('@page(...) is not valid PHP: '.$e->getMessage());
        }

        $call = $stmts[0] ?? null;

        if (! $call instanceof Stmt\Expression || ! $call->expr instanceof Expr\FuncCall) {
            throw new CompileException('@page takes named arguments: @page(props: [...], state: [...])');
        }

        $evaluator = new ConstExprEvaluator;
        $declared = ['title' => null, 'props' => [], 'state' => [], 'remember' => [], 'url' => []];

        foreach ($call->expr->args as $arg) {
            if (! $arg instanceof Arg || $arg->name === null || ! in_array($arg->name->toString(), self::KEYS, true)) {
                throw new CompileException('@page takes named arguments: '.implode(', ', array_map(fn ($k) => "{$k}:", self::KEYS)));
            }

            try {
                $declared[$arg->name->toString()] = $evaluator->evaluateDirectly($arg->value);
            } catch (Throwable) {
                throw new CompileException("@page's {$arg->name}: is written out (strings, numbers, arrays, true, false, null): the class is made from it");
            }
        }

        /** props: ['a', 'b' => default]: a name alone has null. */
        $props = [];
        foreach ((array) $declared['props'] as $key => $value) {
            is_int($key) ? $props[(string) $value] = null : $props[$key] = $value;
        }

        $declared['props'] = $props;
        $declared['state'] = (array) $declared['state'];
        $declared['remember'] = array_values((array) $declared['remember']);
        $declared['url'] = array_values((array) $declared['url']);

        foreach ([...array_keys($declared['props']), ...array_keys($declared['state'])] as $property) {
            if (! preg_match('/^[a-zA-Z_]\w*$/', (string) $property)) {
                throw new CompileException("@page: [{$property}] is not a property name");
            }
        }

        if (($both = array_intersect_key($declared['props'], $declared['state'])) !== []) {
            throw new CompileException('@page: ['.implode(', ', array_keys($both)).'] is both a prop and state: a prop comes from the controller, state is the page\'s own');
        }

        foreach (['remember', 'url'] as $kept) {
            if (($unknown = array_diff($declared[$kept], array_keys($declared['state']))) !== []) {
                throw new CompileException("@page's {$kept}: names state, not [".implode(', ', $unknown).']');
            }
        }

        if ($declared['title'] !== null && ! is_string($declared['title'])) {
            throw new CompileException("@page's title: is a string");
        }

        return $declared;
    }

    /**
     * A page class for the view page, written as an application's own (fusion:page-class): the
     * view's @page made typed properties, for the page to grow browser methods, #[Computed],
     * hooks and uploads. A class of the page's name takes the view page's place.
     */
    public static function pageClass(string $source, string $namespace, string $class): string
    {
        $declared = self::declaration($source) ?? throw new CompileException('The view has no @page(...) to make a class from');
        $type = fn (mixed $default) => match (true) {
            $default === null => 'mixed',
            is_array($default) => 'array',
            is_bool($default) => 'bool',
            is_int($default) => 'int',
            is_float($default) => 'float',
            default => 'string',
        };

        $uses = ['Nitro\\Fusion\\Component'];
        $lines = [];

        if ($declared['props'] !== []) {
            $lines[] = '    // From the controller (Fusion::render()\'s props): read-only in the browser.';
            $lines[] = '';
        }

        foreach ($declared['props'] as $property => $default) {
            $uses[] = 'Nitro\\Fusion\\Attributes\\Locked';
            $lines[] = '    #[Locked]';
            $lines[] = '    public '.$type($default).' $'.$property.' = '.self::export($default).';';
            $lines[] = '';
        }

        if ($declared['state'] !== []) {
            $lines[] = '    // The page\'s own state.';
            $lines[] = '';
        }

        foreach ($declared['state'] as $property => $default) {
            if (in_array($property, $declared['url'], true)) {
                $uses[] = 'Nitro\\Fusion\\Attributes\\Url';
                $lines[] = '    #[Url]';
            }

            if (in_array($property, $declared['remember'], true)) {
                $uses[] = 'Nitro\\Fusion\\Attributes\\Remember';
                $lines[] = '    #[Remember]';
            }

            $lines[] = '    public '.$type($default).' $'.$property.' = '.self::export($default).';';
            $lines[] = '';
        }

        $title = '';
        if ($declared['title'] !== null) {
            $uses[] = 'Nitro\\Fusion\\Attributes\\Title';
            $title = '#[Title('.var_export($declared['title'], true).")]\n";
        }

        $uses = array_unique($uses);
        sort($uses);

        return "<?php\n\nnamespace {$namespace};\n\n".implode('', array_map(fn ($use) => "use {$use};\n", $uses))."\n"
            ."/**\n * The page's state, made from its view's @page(...) (php artisan fusion:page-class). Its controller\n"
            ." * still renders it (Fusion::render()) and takes its actions; this class adds what runs in the\n"
            ." * browser: methods, #[Computed], updated hooks, mounted(), uploads.\n */\n"
            .$title."class {$class} extends Component\n{\n".rtrim(implode("\n", $lines))."\n}\n";
    }

    /** The view without its @page(...) declaration (and the line it was on). */
    public static function withoutDeclaration(string $source): string
    {
        if (! preg_match('/(?<!@)@page\s*\(/', $source, $m, PREG_OFFSET_CAPTURE)) {
            return $source;
        }

        $start = $m[0][1];
        $end = $start + strlen($m[0][0]) + strlen(self::arguments($source, $start + strlen($m[0][0]))) + 1;

        return substr($source, 0, $start).ltrim(substr($source, $end), "\r\n");
    }

    /** A default as PHP code, arrays written short: ['title' => '']. */
    private static function export(mixed $value): string
    {
        if ($value === null) {
            return 'null';
        }

        if (! is_array($value)) {
            return var_export($value, true);
        }

        $items = array_is_list($value)
            ? array_map(fn ($item) => self::export($item), $value)
            : array_map(fn ($key, $item) => var_export($key, true).' => '.self::export($item), array_keys($value), $value);

        return '['.implode(', ', $items).']';
    }

    /** What is between @page's parentheses: up to the one that closes it, strings skipped. */
    private static function arguments(string $source, int $from): string
    {
        $depth = 1;
        $quote = null;

        for ($i = $from, $length = strlen($source); $i < $length; $i++) {
            $char = $source[$i];

            if ($quote !== null) {
                if ($char === '\\') {
                    $i++;
                } elseif ($char === $quote) {
                    $quote = null;
                }

                continue;
            }

            if ($char === '"' || $char === "'") {
                $quote = $char;
            } elseif ($char === '(') {
                $depth++;
            } elseif ($char === ')' && --$depth === 0) {
                return substr($source, $from, $i - $from);
            }
        }

        throw new CompileException('@page( is not closed');
    }

    /** The view file of a view page, or null when there is none. */
    private function viewFile(string $name): ?string
    {
        try {
            return $this->views->getFinder()->find(Naming::view($name, $this->prefix));
        } catch (InvalidArgumentException) {
            return null;
        }
    }

    /** The directories the pages' views are in (one per view path). */
    private function roots(): array
    {
        $relative = str_replace('.', DIRECTORY_SEPARATOR, $this->prefix);

        return array_values(array_filter(
            array_map(fn (string $path) => rtrim($path, '\\/').($relative === '' ? '' : DIRECTORY_SEPARATOR.$relative), $this->views->getFinder()->getPaths()),
            is_dir(...),
        ));
    }
}
