<?php

namespace Nitro\Tests\Fixtures\Features;

use Illuminate\Http\Request;
use Nitro\Component;
use Nitro\Feature;
use Nitro\Tests\Fixtures\Components\Store;

/** An application's feature: logs each hook beside the component's own (Store::$log), made by the container (its Request). */
class Recorder extends Feature
{
    public function __construct(private readonly Request $request) {}

    public function boot(Component $component): void
    {
        $this->log('boot', $component);
    }

    public function mounting(Component $component): void
    {
        $this->log('mounting', $component);
    }

    public function hydrate(Component $component): void
    {
        $this->log('hydrate', $component);
    }

    public function updated(Component $component, array $changed): bool
    {
        /** It runs after Nitro's own: it sees what #[Validate] found. */
        $errors = $component->nitro()->errors->keys();
        $this->log('updated '.implode(',', $changed).($errors === [] ? '' : ' (invalid: '.implode(',', $errors).')'), $component);

        return true;
    }

    public function calling(Component $component, string $method, array $arguments): void
    {
        $this->log("calling {$method}(".implode(',', $arguments).')', $component);
    }

    public function called(Component $component, string $method, mixed $result): void
    {
        $this->log("called {$method} -> ".json_encode($result), $component);
    }

    public function dehydrate(Component $component): void
    {
        $this->log('dehydrate', $component);
    }

    private function log(string $hook, Component $component): void
    {
        Store::$log[] = 'feature '.$hook.' '.class_basename($component).' '.$this->request->method();
    }
}
