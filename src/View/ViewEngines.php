<?php

namespace Nitro\View;

use LogicException;
use Nitro\Compiler\ComponentMeta;

/**
 * The engines components' views are written for, by name: what renders, compiles and watches a
 * component's view.
 */
final class ViewEngines
{
    /** @var array<string, ViewEngine> */
    private array $engines = [];

    public function add(ViewEngine $engine): void
    {
        $this->engines[$engine->name()] = $engine;
    }

    public function of(ComponentMeta $meta): ViewEngine
    {
        return $this->engines[$meta->engine] ?? throw new LogicException("Nitro component [{$meta->name}]: no view engine [{$meta->engine}].");
    }

    public function named(string $name): ?ViewEngine
    {
        return $this->engines[$name] ?? null;
    }

    /**
     * The engines for JavaScript frameworks: the adapters and the app's own.
     *
     * @return list<ScriptEngine>
     */
    public function scripts(): array
    {
        return array_values(array_filter($this->engines, fn (ViewEngine $engine) => $engine instanceof ScriptEngine));
    }
}
