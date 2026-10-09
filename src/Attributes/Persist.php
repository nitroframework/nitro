<?php

namespace Nitro\Attributes;

use Attribute;

/**
 * A store's state kept in the browser's localStorage, so it outlasts a reload: on the store, every
 * property the browser can change; on a property, that one. When the store is made in a tab, the
 * values kept are set as the browser's own changes: the server checks them (their type, #[Locked],
 * #[Validate]) on the next call as any change. A #[Locked] property cannot be kept: the server sets it.
 *
 * Run by the runtime's persist plugin (js/src/plugins/persist.js).
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_PROPERTY)]
final class Persist
{
}
