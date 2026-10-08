<?php

namespace Nitro\Fusion\Compiler;

use InvalidArgumentException;
use Nitro\Fusion\Support\Naming;
use Nitro\Fusion\Support\Registry;

/**
 * What Fusion knows about the application's components and pages, by name or class: each one's
 * ComponentMeta, read once per class.
 */
final class Components
{
    /** @var array<class-string, ComponentMeta> */
    private array $metas = [];

    public function __construct(private readonly Registry $registry)
    {
    }

    /** What Fusion knows about a component, by name or class. */
    public function meta(string $component): ComponentMeta
    {
        $class = class_exists($component) ? ltrim($component, '\\') : $this->registry->class($component);

        if ($class === null) {
            throw new InvalidArgumentException("Unknown Fusion component [{$component}].");
        }

        if (isset($this->metas[$class])) {
            return $this->metas[$class];
        }

        $name = $this->registry->name($class);

        return $this->metas[$class] = ComponentReader::read($class, $name, $this->registry->view($name), Naming::isPage($name));
    }

    /** Whether a component with this name exists. */
    public function has(string $name): bool
    {
        return $this->registry->class($name) !== null;
    }
}
