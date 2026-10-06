<?php

namespace Nitro\Fusion\View;

use Nitro\Fusion\Compiler\ComponentMeta;
use Nitro\Fusion\Component;

/**
 * What a component's view is written in: Blade (BladeEngine), which Fusion compiles for the
 * browser, or a Vue, React or Solid component, which the app's own build compiles.
 *
 * The server renders the view first; the build watches its files; the browser shows it.
 */
interface ViewEngine
{
    /** Its name, as a component names it: 'blade', 'vue', 'react', 'solid'. */
    public function name(): string;

    /**
     * The component's HTML on the server: its root element, not yet marked with its id, name
     * and state (Renderer marks it).
     */
    public function render(Component $component, ComponentMeta $meta): string;

    /** The view's file. */
    public function file(ComponentMeta $meta): string;

    /**
     * Whether Fusion compiles the view for the browser (and reads it for the children it
     * places); otherwise the app's own build does, and the view places no Fusion children.
     */
    public function compiledByFusion(): bool;

    /**
     * The files the view is made of: a change to one makes a new build.
     *
     * @return list<string>
     */
    public function files(ComponentMeta $meta): array;
}
