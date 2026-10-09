<?php

namespace Nitro\Attributes;

use Attribute;

/**
 * A store's state shared with the browser's other tabs of the site: on the store, every property
 * the browser can change; on a property, that one. A change in one tab is set in the others as
 * their own browser change (the server checks it on their next call, as any change). A #[Locked]
 * property cannot be shared: the server sets it.
 *
 * Run by the runtime's sync plugin (js/src/plugins/sync.js).
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_PROPERTY)]
final class Sync
{
}
