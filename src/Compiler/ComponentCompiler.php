<?php

namespace Nitro\Fusion\Compiler;

use Closure;
use Nitro\Fusion\Compiler\Template\TemplateCompiler;
use Nitro\Fusion\Support\Hooks;
use Nitro\Fusion\Support\Wire;
use PhpParser\Node\Stmt;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\ParserFactory;
use ReflectionMethod;
use Throwable;

/**
 * Compiles a component (its class and its view) to the definition the browser runtime loads:
 *
 *   // App\Fusion\Students\Form · view fusion.students.form
 *   Fusion.define("students.form", {
 *       props: {...}, server: [...], listeners: {...}, mount: true,
 *       methods: { reset: function () {...} },
 *       template: {t: "r", h: [...]},           // the view's markup, as a tree (TemplateCompiler)
 *       expressions: [function ($this) {...}],  // its dynamic parts
 *       subs: [["count"], ...],                 // what each reads (Subscriptions)
 *   });
 *
 * Only methods the browser can reach are compiled: those the view calls or binds as handlers,
 * #[On] listeners, updatedProperty() hooks, and what those call in turn. A server-only helper
 * never has to compile.
 */
final class ComponentCompiler
{
    /**
     * @param  Closure(string): bool  $isBladeDirective
     * @param  bool  $parseTemplates  templates as JSON strings for JSON.parse (fusion:build), not object literals
     * @param  (Closure(string): ?string)|null  $componentName  a component class to its name (dispatch()->to(X::class))
     */
    public function __construct(
        private readonly Closure $isBladeDirective,
        private readonly bool $parseTemplates = false,
        private readonly ?Closure $componentName = null,
    ) {
    }

    /**
     * What the compiled code's Laravel helpers name, across every component compiled so far: the
     * page ships these (route definitions, translations, config values).
     *
     * @var array{routes: array<string, true>, lang: array<string, true>, config: array<string, true>, functions: array<string, true>}
     */
    public array $uses = ['routes' => [], 'lang' => [], 'config' => [], 'functions' => []];

    public function compile(ComponentMeta $meta, string $viewSource, string $viewFile): string
    {
        $scope = $this->componentName === null ? $meta->scope() : $meta->scope()->withComponentNames($this->componentName);
        $templates = new TemplateCompiler($this->isBladeDirective);
        $templates->compile($viewSource, $scope, $viewFile);
        $this->use($templates->uses());
        $template = $templates->fragment($this->parseTemplates);

        /** mounted() and destroyed(): the browser calls them (compiled, or #[Server]). */
        $hooks = array_values(array_intersect(Hooks::BROWSER, [...$meta->browserMethods, ...$meta->serverMethods]));

        $methods = $this->methods($meta, $scope, [
            ...$templates->calledMethods,
            ...$this->listeners($meta),
            ...$this->updatedHooks($meta),
            ...$hooks,
        ]);

        $definition = [
            'props' => $this->props($meta),
            'server' => $meta->serverMethods,
            'listeners' => (object) $meta->listeners,
            'mount' => $meta->hasMount,
            'modelable' => $meta->modelable,
            'hooks' => $hooks,
        ];

        $fields = [];

        foreach ($definition as $name => $value) {
            $fields[] = $name.': '.json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        }

        $methodList = implode(",\n", array_map(fn ($name, $js) => json_encode($name).': '.$js, array_keys($methods), $methods));

        $subscriptions = new Subscriptions($methods, array_keys($meta->properties));
        $subs = [];

        foreach ($subscriptions->ofTemplate($templates->expressions(), $templates->expressionSources()) as $index => $keys) {
            $subs[] = "/* {$index} */ ".json_encode($keys, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        }

        return '// '.$meta->class.' · view '.$meta->view."\n"
            .'Fusion.define('.json_encode($meta->name).", {\n".implode(",\n", $fields).",\nmethods: {\n{$methodList}\n},\n{$template},\nsubs: [\n".implode(",\n", $subs)."\n]\n});";
    }

    /** @param  array{routes: array<string, true>, lang: array<string, true>, config: array<string, true>, functions: array<string, true>}  $uses */
    private function use(array $uses): void
    {
        foreach ($uses as $kind => $names) {
            $this->uses[$kind] += $names;
        }
    }

    /** @return array<string, array{locked: bool, default?: mixed, url?: array<string, mixed>}> */
    private function props(ComponentMeta $meta): array
    {
        $props = [];

        foreach ($meta->properties as $name => $property) {
            $props[$name] = ['locked' => $property->locked];

            if ($property->hasDefault && $property->kind === PropertyMeta::VALUE) {
                $props[$name]['default'] = Wire::encode($property->default);
            }

            /** #[Url]: the query key, and when the value is left out of the URL. */
            if ($property->url !== null) {
                $props[$name]['url'] = array_filter([
                    'as' => $property->url->as ?? $name,
                    'history' => $property->url->history ?: null,
                    'keep' => $property->url->keep ?: null,
                ], fn ($v) => $v !== null) + ($property->url->hasExcept() ? ['except' => Wire::encode($property->url->except)] : []);
            }
        }

        return $props;
    }

    /** @return list<string> */
    private function listeners(ComponentMeta $meta): array
    {
        return array_values(array_filter(array_merge(...array_values($meta->listeners) ?: [[]]), fn ($m) => ! $meta->isServerMethod($m)));
    }

    /** updatedEmail() runs after fusion:model or $set changes $email, and updated($property, $value) after any change. */
    private function updatedHooks(ComponentMeta $meta): array
    {
        return array_values(array_filter($meta->browserMethods, fn ($m) => preg_match('/^updated([A-Z]|$)/', $m) === 1));
    }

    /**
     * Compile what the browser can reach, following $this->method() calls.
     *
     * @param  list<string>  $seeds
     * @return array<string, string>
     */
    private function methods(ComponentMeta $meta, Scope $scope, array $seeds): array
    {
        $compiled = [];
        $queue = array_values(array_unique($seeds));

        while ($queue !== []) {
            $name = array_shift($queue);

            if (isset($compiled[$name]) || ! in_array($name, $meta->browserMethods, true)) {
                continue;
            }

            $node = $this->methodNode($meta->class, $name);
            $file = (new ReflectionMethod($meta->class, $name))->getFileName() ?: $meta->file;
            $transpiler = new Transpiler($scope);

            try {
                $compiled[$name] = $transpiler->method($node, $scope->forFile($file));
            } catch (CompileException $e) {
                throw new CompileException(
                    "{$name}() runs in the browser (the view or another browser method uses it) but cannot be compiled: "
                    .$e->getMessage().'. Mark it #[Server] if it needs the server.',
                );
            }

            array_push($queue, ...$transpiler->calledMethods);
            $this->use($transpiler->uses);
        }

        return $compiled;
    }

    private function methodNode(string $class, string $method): Stmt\ClassMethod
    {
        $reflection = new ReflectionMethod($class, $method);
        $file = (string) $reflection->getFileName();

        try {
            $ast = (new ParserFactory)->createForNewestSupportedVersion()->parse((string) file_get_contents($file)) ?? [];
        } catch (Throwable $e) {
            throw new CompileException('Cannot parse the component: '.$e->getMessage(), $file);
        }

        /** Names as the file's imports make them (Card::class: its full name), kept as written. */
        (new NodeTraverser(new NameResolver(null, ['replaceNodes' => false])))->traverse($ast);

        /** Reflection starts at "function"; a node with attributes starts at its first attribute. */
        $line = $reflection->getStartLine();

        foreach ((new NodeFinder)->findInstanceOf($ast, Stmt\ClassMethod::class) as $node) {
            if ($node->name->toString() === $method && $node->getStartLine() <= $line && $line <= $node->getEndLine()) {
                return $node;
            }
        }

        throw new CompileException("Cannot find the source of {$method}()", $file, $reflection->getStartLine());
    }
}
