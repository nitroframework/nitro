<?php

namespace Nitro\Fusion\Rendering;

use InvalidArgumentException;

/**
 * Portals: the directive @portal('modals') marks where content goes, @teleport('modals') renders
 * content into a buffer for it, and once the response is rendered fill() puts each portal's
 * content in its place. A part of Fusion, which the directives call.
 */
final class Portals
{
    /**
     * By portal name: whether @portal placed it, and the @teleport content for it (with the order
     * the browser hydrates it in).
     *
     * @var array<string, array{defined: bool, contents: list<array{order: int, seq: int, html: string}>}>
     */
    private array $portals = [];

    /** @var list<string> the @teleport blocks being rendered, innermost last: their portal names */
    private array $teleporting = [];

    public function __construct(private readonly ComponentRenderer $renderer)
    {
    }

    /**
     * The @portal('modals') directive: its marks, and where the content teleported to it goes
     * once the response is rendered (fill()).
     */
    public function portal(string $name): string
    {
        $scope = $this->portals[$name] ??= ['defined' => false, 'contents' => []];

        if ($scope['defined']) {
            throw new InvalidArgumentException("Portal '{$name}' is already defined. Must be only one portal with such name.");
        }

        $this->portals[$name]['defined'] = true;
        $name = e($name);

        return "<i id=\"portal_{$name}\" style=\"display: none !important;\"></i>"
            ."PORTAL_{$name}"
            ."<i id=\"portal_{$name}_end\" style=\"display: none !important;\"></i>";
    }

    /** The @teleport('modals') directive: its content renders into a buffer, for the portal. */
    public function startTeleport(string $name): string
    {
        $this->teleporting[] = $name;
        ob_start();

        return '';
    }

    public function endTeleport(): string
    {
        $name = array_pop($this->teleporting) ?? throw new InvalidArgumentException('@endteleport closes no @teleport');
        $this->portals[$name] ??= ['defined' => false, 'contents' => []];
        $this->portals[$name]['contents'][] = [
            'order' => $this->renderer->order(),
            'seq' => count($this->portals[$name]['contents']),
            'html' => (string) ob_get_clean(),
        ];

        return '';
    }

    /**
     * Each portal's placeholder becomes the content teleported to it: in the order the browser
     * hydrates it (a component's children hydrate after it, the server renders them inside it),
     * then as it was rendered. The portals are done with after.
     */
    public function fill(string $body): string
    {
        foreach ($this->portals as $name => $scope) {
            $contents = $scope['contents'];
            usort($contents, fn (array $left, array $right) => [$left['order'], $left['seq']] <=> [$right['order'], $right['seq']]);
            $name = e($name);
            $body = str_replace("PORTAL_{$name}<i id=\"portal_{$name}_end\"", implode('', array_column($contents, 'html'))."<i id=\"portal_{$name}_end\"", $body);
        }

        $this->portals = [];

        return $body;
    }

    /**
     * A render of its own (a layout rendered at build time): the portals start empty, and what
     * they held before comes back after.
     *
     * @template T
     *
     * @param  \Closure(): T  $render
     * @return T
     */
    public function apart(\Closure $render): mixed
    {
        $previous = [$this->portals, $this->teleporting];
        $this->portals = [];
        $this->teleporting = [];

        try {
            return $render();
        } finally {
            [$this->portals, $this->teleporting] = $previous;
        }
    }
}
