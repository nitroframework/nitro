<?php

namespace Nitro\Browser;

/**
 * A <canvas>'s 2D context, chained. The calls are kept and run, in order, once the component has
 * rendered: the canvas is there even when the same change adds it. Names and arguments are the
 * CanvasRenderingContext2D's; size() also scales for high-density screens, so the drawing uses
 * CSS pixels.
 */
final class Canvas
{
    /** The canvas's size in CSS pixels (this clears it). */
    public function size(int $width, int $height): static
    {
        throw BrowserOnlyException::make();
    }

    /** Clear the whole canvas. */
    public function clear(): static
    {
        throw BrowserOnlyException::make();
    }

    public function fillStyle(string $color): static
    {
        throw BrowserOnlyException::make();
    }

    public function strokeStyle(string $color): static
    {
        throw BrowserOnlyException::make();
    }

    public function lineWidth(float $width): static
    {
        throw BrowserOnlyException::make();
    }

    public function lineCap(string $cap): static
    {
        throw BrowserOnlyException::make();
    }

    public function lineJoin(string $join): static
    {
        throw BrowserOnlyException::make();
    }

    /** @param  list<float>  $segments */
    public function setLineDash(array $segments): static
    {
        throw BrowserOnlyException::make();
    }

    public function globalAlpha(float $alpha): static
    {
        throw BrowserOnlyException::make();
    }

    public function font(string $font): static
    {
        throw BrowserOnlyException::make();
    }

    public function textAlign(string $align): static
    {
        throw BrowserOnlyException::make();
    }

    public function textBaseline(string $baseline): static
    {
        throw BrowserOnlyException::make();
    }

    public function fillRect(float $x, float $y, float $width, float $height): static
    {
        throw BrowserOnlyException::make();
    }

    public function strokeRect(float $x, float $y, float $width, float $height): static
    {
        throw BrowserOnlyException::make();
    }

    public function clearRect(float $x, float $y, float $width, float $height): static
    {
        throw BrowserOnlyException::make();
    }

    public function fillText(string $text, float $x, float $y): static
    {
        throw BrowserOnlyException::make();
    }

    public function strokeText(string $text, float $x, float $y): static
    {
        throw BrowserOnlyException::make();
    }

    public function beginPath(): static
    {
        throw BrowserOnlyException::make();
    }

    public function closePath(): static
    {
        throw BrowserOnlyException::make();
    }

    public function moveTo(float $x, float $y): static
    {
        throw BrowserOnlyException::make();
    }

    public function lineTo(float $x, float $y): static
    {
        throw BrowserOnlyException::make();
    }

    public function rect(float $x, float $y, float $width, float $height): static
    {
        throw BrowserOnlyException::make();
    }

    /** A circle by default: angles in radians. */
    public function arc(float $x, float $y, float $radius, float $start = 0, float $end = M_PI * 2): static
    {
        throw BrowserOnlyException::make();
    }

    public function quadraticCurveTo(float $cpx, float $cpy, float $x, float $y): static
    {
        throw BrowserOnlyException::make();
    }

    public function bezierCurveTo(float $cp1x, float $cp1y, float $cp2x, float $cp2y, float $x, float $y): static
    {
        throw BrowserOnlyException::make();
    }

    public function fill(): static
    {
        throw BrowserOnlyException::make();
    }

    public function stroke(): static
    {
        throw BrowserOnlyException::make();
    }

    public function save(): static
    {
        throw BrowserOnlyException::make();
    }

    public function restore(): static
    {
        throw BrowserOnlyException::make();
    }

    public function translate(float $x, float $y): static
    {
        throw BrowserOnlyException::make();
    }

    public function rotate(float $angle): static
    {
        throw BrowserOnlyException::make();
    }

    public function scale(float $x, float $y): static
    {
        throw BrowserOnlyException::make();
    }
}
