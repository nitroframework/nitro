<?php

namespace Nitro\Attributes;

use Attribute;

/**
 * HTML made on the server, for the view to place: highlighted code, rendered Markdown, a diff, an
 * SVG chart. The method runs on the server only, when the component's state is sent to the
 * browser (the first page, a visit, after each #[Server] call), and the view reads its result as
 * $this->name (Blade: {!! $this->code !!}; Vue: v-html="code").
 *
 * The HTML goes to the browser beside the signed state, never in it, and never back: the browser
 * returns only a fingerprint of each, and the server leaves out the HTML whose fingerprint still
 * matches. It is placed as it is, so it must be safe HTML (escape or sanitise what it is made
 * from). A method that is slow to run should keep its result (cache it by what it is made from).
 */
#[Attribute(Attribute::TARGET_METHOD)]
final class Html
{
}
