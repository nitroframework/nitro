<?php

namespace Nitro\Fusion\Attributes;

use Attribute;
use InvalidArgumentException;

/**
 * The component's view, when it does not follow the convention (App\Fusion\Students\Form ->
 * fusion.students.form), or when it is not Blade:
 *
 *     #[View('shared.form')]               a Blade view
 *     #[View(vue: 'cart/badge')]           resources/views/vue/cart/badge.vue
 *     #[View(react: 'chart')]              resources/views/react/chart.jsx (or .tsx)
 *     #[View(solid: 'clock')]              resources/views/solid/clock.jsx (or .tsx)
 *     #[View(svelte: 'cart/total')]        resources/views/svelte/cart/total.svelte
 *     #[View('map', engine: 'leaflet')]    an engine of the app's own (fusion.views)
 */
#[Attribute(Attribute::TARGET_CLASS)]
final class View
{
    /** The view's name, for its engine. */
    public readonly string $name;

    /** Its engine: 'blade', 'vue', 'react', 'solid', 'svelte', or one in fusion.views. */
    public readonly string $engine;

    public function __construct(?string $name = null, ?string $vue = null, ?string $react = null, ?string $solid = null, ?string $svelte = null, ?string $engine = null)
    {
        $named = array_filter(['vue' => $vue, 'react' => $react, 'solid' => $solid, 'svelte' => $svelte], fn ($view) => $view !== null);

        if (count($named) > 1 || ($named !== [] && ($name !== null || $engine !== null))) {
            throw new InvalidArgumentException('#[View] names one view: #[View(\'name\')], #[View(vue: \'name\')], #[View(react: ...)], #[View(solid: ...)], #[View(svelte: ...)] or #[View(\'name\', engine: \'...\')]');
        }

        if ($named !== []) {
            $this->engine = (string) array_key_first($named);
            $this->name = (string) $named[$this->engine];

            return;
        }

        if ($name === null) {
            throw new InvalidArgumentException('#[View] needs the view\'s name');
        }

        $this->name = $name;
        $this->engine = $engine ?? 'blade';
    }
}
