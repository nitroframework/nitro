<?php

namespace Nitro\Attributes;

use Attribute;
use InvalidArgumentException;
use Nitro\Support\Protocol;

/**
 * The component's view, when it does not follow the convention (App\Nitro\Posts\Form ->
 * nitro.posts.form), or when it is not Blade:
 *
 *     #[View('shared.form')]               a Blade view
 *     #[View(vue: 'cart.badge')]           resources/views/vue/cart/badge.vue ('cart/badge' too)
 *     #[View(react: 'chart')]              resources/views/react/chart.jsx (or .tsx)
 *     #[View(solid: 'clock')]              resources/views/solid/clock.jsx (or .tsx)
 *     #[View(svelte: 'cart/total')]        resources/views/svelte/cart/total.svelte
 *     #[View('map', engine: 'leaflet')]    an engine of the app's own (nitro.views)
 *
 * A JavaScript view's dots and slashes are both folders, as a Blade view's dots are. A file with
 * dots in its own name (chart.client.jsx) is found by its name as written first; when a name is
 * two files, the build says so (View\ScriptEngine).
 */
#[Attribute(Attribute::TARGET_CLASS)]
final class View
{
    /** The view's name, for its engine. */
    public readonly string $name;

    /** Its engine: Blade's (Protocol::bladeEngine()), or one nitro.views lists. */
    public readonly string $engine;

    /** Each framework of Nitro's own is a parameter, named as its engine is: the signature is their one list here. */
    public function __construct(?string $name = null, ?string $vue = null, ?string $react = null, ?string $solid = null, ?string $svelte = null, ?string $engine = null)
    {
        $frameworks = array_diff_key(get_defined_vars(), ['name' => true, 'engine' => true]);
        $named = array_filter($frameworks, fn (?string $view) => $view !== null);

        if (count($named) > 1 || ($named !== [] && ($name !== null || $engine !== null))) {
            $ways = array_map(fn (string $framework) => "#[View({$framework}: 'name')]", array_keys($frameworks));

            throw new InvalidArgumentException("#[View] names one view: #[View('name')] (Blade), #[View('name', engine: '...')], or one of ".implode(', ', $ways));
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
        $this->engine = $engine ?? Protocol::bladeEngine();
    }
}
