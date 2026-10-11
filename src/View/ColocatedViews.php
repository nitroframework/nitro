<?php

namespace Nitro\View;

use FilesystemIterator;
use Nitro\Compiler\CompileException;
use Nitro\Support\Naming;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Stmt;
use PhpParser\NodeFinder;
use PhpParser\ParserFactory;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Components written in their view's file: a JavaScript view with its PHP in it.
 *
 *     <php>                                     resources/views/vue/cart/badge.vue
 *     new class extends Component {             is <nitro:cart.badge />
 *         public int $count = 0;
 *         #[Server] public function save(): void { ... }
 *     };
 *     </php>
 *
 * In a .jsx or .tsx file the block is a comment at the top, so the file stays JavaScript:
 * / * <php> ... </php> * /. The block holds an anonymous class (`new class ...`, `return` before
 * it if you like), the `use` statements it needs above it, and attributes on it
 * (`new #[Title('Cart')] class ...`). Component and Nitro's attributes need no `use`.
 *
 * Nitro makes each one a class of its own (Nitro\Views\Vue\Cart\Badge), written to its
 * compiled directory when the view changes, with #[View] naming the view. The class keeps the
 * block's line numbers, so an error in it names the line in the view.
 */
final class ColocatedViews
{
    public const NAMESPACE = 'Nitro\\Views';

    /** Names a block may use without importing them. */
    private const IMPORTS = [
        'Component' => \Nitro\Component::class,
        'Computed' => \Nitro\Attributes\Computed::class,
        'Locked' => \Nitro\Attributes\Locked::class,
        'Modelable' => \Nitro\Attributes\Modelable::class,
        'On' => \Nitro\Attributes\On::class,
        'Server' => \Nitro\Attributes\Server::class,
        'Title' => \Nitro\Attributes\Title::class,
        'Url' => \Nitro\Attributes\Url::class,
        'Validate' => \Nitro\Attributes\Validate::class,
    ];

    /** @var array<string, class-string>|null */
    private ?array $components = null;

    /**
     * @param  string  $directory  where the classes are written
     */
    public function __construct(private readonly ViewEngines $engines, private readonly string $directory)
    {
    }

    /**
     * The components written in their views, by name: their classes made (again, if the view
     * changed) and loaded.
     *
     * @return array<string, class-string>
     */
    public function components(): array
    {
        if ($this->components !== null) {
            return $this->components;
        }

        $this->components = [];

        foreach ($this->engines->scripts() as $engine) {
            foreach ([null, ...$engine->namespaces()] as $namespace) {
                foreach ($engine->paths($namespace) as $path) {
                    $this->scan($engine, rtrim($path, '/\\'), $namespace);
                }
            }
        }

        return $this->components;
    }

    /**
     * One of an engine's paths: its views that hold their component's PHP (as the engine writes
     * it), a component each (a name another path had already is its). A namespace's are named
     * in it: issues/cart/badge.vue is "issues::cart.badge", its class under Namespaced\Issues.
     */
    private function scan(ScriptEngine $engine, string $root, ?string $namespace = null): void
    {
        if (! is_dir($root)) {
            return;
        }

        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)) as $file) {
            if (! in_array($file->getExtension(), $engine->extensions(), true)) {
                continue;
            }

            $source = (string) file_get_contents($file->getPathname());
            $block = str_contains($source, '<php>') ? $engine->phpBlock($source, $file->getPathname()) : null;

            if ($block === null) {
                continue;
            }

            $view = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1, -strlen($file->getExtension()) - 1));
            $name = Naming::within($namespace, str_replace('/', '.', $view));

            if (isset($this->components[$name])) {
                continue;
            }

            $class = self::NAMESPACE.'\\'.($namespace === null ? '' : 'Namespaced\\'.self::studly($namespace).'\\').self::studly($engine->name()).'\\'.implode('\\', array_map(self::studly(...), explode('/', $view)));
            $this->components[$name] = $class;
            $this->load($class, $engine->name(), Naming::within($namespace, $view), $file->getPathname(), $block);
        }
    }

    /**
     * The class for a view's PHP block (its code, and the line it starts on in the view), as
     * written to its file: its line numbers the view's.
     *
     * @param  array{0: string, 1: int}  $block
     */
    public static function generate(string $class, string $engine, string $view, string $file, array $block): string
    {
        [$code, $line] = $block;
        $parser = (new ParserFactory)->createForNewestSupportedVersion();

        try {
            $statements = $parser->parse("<?php\n".$code) ?? [];
        } catch (\PhpParser\Error $exception) {
            throw new CompileException('The <php> block is not valid PHP: '.$exception->getRawMessage(), $file, $line + $exception->getStartLine() - 2);
        }

        $anonymous = (new NodeFinder)->findFirst($statements, fn (Node $node) => $node instanceof Expr\New_ && $node->class instanceof Stmt\Class_);

        if (! $anonymous instanceof Expr\New_ || ! $anonymous->class instanceof Stmt\Class_) {
            throw new CompileException('The <php> block holds the component as an anonymous class: new class extends Component { ... };', $file, $line);
        }

        $body = $anonymous->class;
        $offset = strlen("<?php\n");
        $text = substr($code, $body->getStartFilePos() - $offset, $body->getEndFilePos() - $body->getStartFilePos() + 1);
        $short = substr($class, strrpos($class, '\\') + 1);
        $text = (string) preg_replace('/\bclass\b/', '#[\\Nitro\\Attributes\\View('.var_export($view, true).', engine: '.var_export($engine, true).')] final class '.$short, $text, 1);

        if ($body->extends === null) {
            $text = (string) preg_replace('/(final class '.$short.')/', '$1 extends Component', $text, 1);
        }

        /** Its own imports, then Nitro's for the names it did not import. */
        $imported = [];
        $uses = [];

        foreach ($statements as $statement) {
            if ($statement instanceof Stmt\Use_ || $statement instanceof Stmt\GroupUse) {
                $uses[] = trim(substr($code, $statement->getStartFilePos() - $offset, $statement->getEndFilePos() - $statement->getStartFilePos() + 1));

                foreach ($statement->uses as $use) {
                    $imported[$use->getAlias()->toString()] = true;
                }
            }
        }

        foreach (self::IMPORTS as $alias => $name) {
            if (! isset($imported[$alias])) {
                $uses[] = "use {$name};";
            }
        }

        $namespace = substr($class, 0, strrpos($class, '\\'));
        $classLine = $line + $body->getStartLine() - 2;
        $head = "<?php namespace {$namespace}; ".implode(' ', $uses);

        return $head.str_repeat("\n", max(1, $classLine - 1)).$text."\n\n// Made by Nitro from ".str_replace('\\', '/', $file).": edit the view, not this file.\n";
    }

    /**
     * Write a view's class when the view is newer than it, and load it.
     *
     * @param  array{0: string, 1: int}  $block
     */
    private function load(string $class, string $engine, string $view, string $file, array $block): void
    {
        $path = $this->directory.DIRECTORY_SEPARATOR.str_replace('\\', DIRECTORY_SEPARATOR, substr($class, strlen(self::NAMESPACE) + 1)).'.php';

        if (! is_file($path) || filemtime($path) < filemtime($file)) {
            @mkdir(dirname($path), 0777, true);
            file_put_contents($path, self::generate($class, $engine, $view, $file, $block), LOCK_EX);
        }

        if (! class_exists($class, false)) {
            require_once $path;
        }
    }

    private static function studly(string $name): string
    {
        return str_replace(' ', '', ucwords(str_replace(['-', '_', '.'], ' ', $name)));
    }
}
