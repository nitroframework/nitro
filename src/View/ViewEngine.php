<?php

namespace Nitro\Fusion\View;

use Nitro\Fusion\Compiler\ComponentMeta;
use Nitro\Fusion\Component;

/**
 * What a component's view is written in: Blade (BladeEngine), which Fusion compiles for the
 * browser, or a JavaScript framework's component (fusion.views), which the app's own build compiles.
 *
 * The server renders the view first; the build watches its files; the browser shows it.
 */
interface ViewEngine
{
    /** Its name, as a component names it and fusion.views lists it ('blade', 'vue', ...). */
    public function name(): string;

    /** Its name for people: 'Blade', 'Vue' (fusion:build says what views are written in). */
    public function label(): string;

    /**
     * The component's HTML on the server: its root element, not yet marked with its id, name
     * and state (ComponentRenderer marks it).
     *
     * Blade renders the whole view here. A JavaScript view renders the element the browser
     * fills, with the view's HTML in it when the render service (fusion.ssr) gave it, for the
     * browser to hydrate.
     */
    public function render(Component $component, ComponentMeta $meta): string;

    /**
     * Names the view gets from Fusion besides the component's own, which the component's
     * properties and methods cannot also have: Fusion's JavaScript adapters' (js/adapters/names.json).
     *
     * @return list<string>
     */
    public function reservedNames(): array;

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
