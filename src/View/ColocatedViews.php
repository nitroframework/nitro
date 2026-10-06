<?php

namespace Nitro\Fusion\View;

use FilesystemIterator;
use Nitro\Fusion\Compiler\CompileException;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Stmt;
use PhpParser\NodeFinder;
use PhpParser\ParserFactory;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Components written in their view's file: a Vue, React or Solid view with its PHP in it.
 *
 *     <php>                                     resources/views/vue/cart/badge.vue
 *     new class extends Component {             is <fusion:cart.badge />
 *         public int $count = 0;
 *         #[Server] public function save(): void { ... }
 *     };
 *     </php>
 *
 * In a .jsx or .tsx file the block is a comment at the top, so the file stays JavaScript:
 * / * <php> ... </php> * /. The block holds an anonymous class (`new class ...`, `return` before
 * it if you like), the `use` statements it needs above it, and attributes on it
 * (`new #[Title('Cart')] class ...`). Component and Fusion's attributes need no `use`.
 *
 * Fusion makes each one a class of its own (Fusion\Views\Vue\Cart\Badge), written to its
 * compiled directory when the view changes, with #[View] naming the view. The class keeps the
 * block's line numbers, so an error in it names the line in the view.
 */
final class ColocatedViews
{
    public const NAMESPACE = 'Fusion\\Views';

    /** Names a block may use without importing them. */
    private const IMPORTS = [
        'Component' => \Nitro\Fusion\Component::class,
        'Computed' => \Nitro\Fusion\Attributes\Computed::class,
        'Locked' => \Nitro\Fusion\Attributes\Locked::class,
        'Modelable' => \Nitro\Fusion\Attributes\Modelable::class,
        'On' => \Nitro\Fusion\Attributes\On::class,
        'Server' => \Nitro\Fusion\Attributes\Server::class,
        'Title' => \Nitro\Fusion\Attributes\Title::class,
        'Url' => \Nitro\Fusion\Attributes\Url::class,
        'Validate' => \Nitro\Fusion\Attributes\Validate::class,
    ];

    /** @var array<string, class-string>|null */
    private ?array $components = null;

    /**
     * @param  array<string, array{path: string, extensions: list<string>}>  $engines  fusion.views
     * @param  string  $directory  where the classes are written
     */
    public function __construct(private readonly array $engines, private readonly string $directory)
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

        foreach ($this->engines as $engine => $config) {
            foreach ((array) $config['path'] as $path) {
                $this->scan((string) $engine, rtrim((string) $path, '/\\'), array_values((array) $config['extensions']));
            }
        }

        return $this->components;
    }

    /**
     * One of an engine's paths: its views with a <php> block, a component each (a name another
     * path had already is its).
     *
     * @param  list<string>  $extensions
     */
    private function scan(string $engine, string $root, array $extensions): void
    {
        if (! is_dir($root)) {
            return;
        }

        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)) as $file) {
            if (! in_array($file->getExtension(), $extensions, true)) {
                continue;
            }

            $source = (string) file_get_contents($file->getPathname());

            if (! str_contains($source, '<php>')) {
                continue;
            }

            $view = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1, -strlen($file->getExtension()) - 1));

            if (isset($this->components[str_replace('/', '.', $view)])) {
                continue;
            }

            $class = self::NAMESPACE.'\\'.self::studly($engine).'\\'.implode('\\', array_map(self::studly(...), explode('/', $view)));
            $this->components[str_replace('/', '.', $view)] = $class;
            $this->load($class, $engine, $view, $file->getPathname(), $source);
        }
    }

    /**
     * The class for a view's block, as written to its file: its line numbers the view's.
     */
    public static function generate(string $class, string $engine, string $view, string $file, string $source): string
    {
        $block = self::block($source, $file);

        if ($block === null) {
            throw new CompileException('No <php> block', $file);
        }

        [$code, $line] = $block;
        $parser = (new ParserFactory)->createForNewestSupportedVersion();

        try {
            $statements = $parser->parse("<?php\n".$code) ?? [];
        } catch (\PhpParser\Error $e) {
            throw new CompileException('The <php> block is not valid PHP: '.$e->getRawMessage(), $file, $line + $e->getStartLine() - 2);
        }

        $anonymous = (new NodeFinder)->findFirst($statements, fn (Node $node) => $node instanceof Expr\New_ && $node->class instanceof Stmt\Class_);

        if (! $anonymous instanceof Expr\New_ || ! $anonymous->class instanceof Stmt\Class_) {
            throw new CompileException('The <php> block holds the component as an anonymous class: new class extends Component { ... };', $file, $line);
        }

        $body = $anonymous->class;
        $offset = strlen("<?php\n");
        $text = substr($code, $body->getStartFilePos() - $offset, $body->getEndFilePos() - $body->getStartFilePos() + 1);
        $short = substr($class, strrpos($class, '\\') + 1);
        $text = (string) preg_replace('/\bclass\b/', '#[\\Nitro\\Fusion\\Attributes\\View('.$engine.': '.var_export($view, true).')] final class '.$short, $text, 1);

        if ($body->extends === null) {
            $text = (string) preg_replace('/(final class '.$short.')/', '$1 extends Component', $text, 1);
        }

        /** Its own imports, then Fusion's for the names it did not import. */
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

        return $head.str_repeat("\n", max(1, $classLine - 1)).$text."\n\n// Made by Fusion from ".str_replace('\\', '/', $file).": edit the view, not this file.\n";
    }

    /**
     * A view's PHP block: its code and the line it starts on; null when it has none.
     *
     * @return array{0: string, 1: int}|null
     */
    public static function block(string $source, string $file): ?array
    {
        $pattern = preg_match('/\.(jsx|tsx|js|ts)$/', $file) === 1
            ? '/\/\*\s*<php>(.*?)<\/php>\s*\*\//s'
            : '/<php>(.*?)<\/php>/s';

        if (preg_match($pattern, $source, $match, PREG_OFFSET_CAPTURE) !== 1) {
            return null;
        }

        return [$match[1][0], substr_count($source, "\n", 0, $match[1][1]) + 1];
    }

    /** Write a view's class when the view is newer than it, and load it. */
    private function load(string $class, string $engine, string $view, string $file, string $source): void
    {
        $path = $this->directory.DIRECTORY_SEPARATOR.str_replace('\\', DIRECTORY_SEPARATOR, substr($class, strlen(self::NAMESPACE) + 1)).'.php';

        if (! is_file($path) || filemtime($path) < filemtime($file)) {
            @mkdir(dirname($path), 0777, true);
            file_put_contents($path, self::generate($class, $engine, $view, $file, $source), LOCK_EX);
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
