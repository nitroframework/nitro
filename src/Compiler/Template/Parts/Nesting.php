<?php

namespace Nitro\Compiler\Template\Parts;

use Nitro\Compiler\Template\TemplateNode;

/**
 * Where an element can be, as the browser's HTML parser builds the tree (its "in body" rules):
 * an element whose start tag makes the parser close or move what is open above it would give a
 * DOM other than the view's tree, so it is a compile error. The rules:
 *
 *  - a <p> in button scope is closed by <div>, <ul>, <li>, <section>, ... (CLOSES_P)
 *  - <li> closes an open <li>, <dd>/<dt> an open <dd>/<dt> (until a "special" element)
 *  - <button> closes a <button> in scope; <a> closes an <a> (until a formatting marker);
 *    a <form> inside a <form> is dropped
 *  - a heading directly in a heading, <option>/<optgroup> directly in an <option>, are closed;
 *    any other element in an <option> is read differently by browsers (most keep its text only)
 *  - <tr> directly in <table>, <td>/<th> directly in <table>, <tbody>, <thead>, <tfoot>, <col>
 *    directly in <table>: the parser adds the <tbody> / <tr> / <colgroup> the view does not have
 *  - a row, a cell, a table section or a caption inside an open cell or row of the same table
 *    ("in cell", "in row"): the parser ends the cell (and the row) first
 *
 * Across a Blade component's call (its view compiled once, ComponentViews): what is open where
 * the tag is decides too. An element whose rule reaches the top of the view undecided is kept
 * (with the elements above it in the view) for the call to decide; so is a slot's place in the
 * view, and an element of a slot's content (in <nitro-slot>, decided where the view places the
 * slot). Not checked: content the parser moves out of a <table> (foster parenting), SVG and
 * MathML content.
 *
 * HTML: the WHATWG parser's rules, by its element lists.
 *
 * @internal
 */
final class Nesting extends Part
{
    /** Their start tag closes an open <p> in button scope. */
    public const CLOSES_P = [
        'address', 'article', 'aside', 'blockquote', 'center', 'dd', 'details', 'dialog', 'dir', 'div', 'dl', 'dt',
        'fieldset', 'figcaption', 'figure', 'footer', 'form', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'header', 'hgroup', 'hr',
        'li', 'listing', 'main', 'menu', 'nav', 'ol', 'p', 'plaintext', 'pre', 'search', 'section', 'summary', 'table',
        'ul', 'xmp',
    ];

    /** The parser's default scope: an element above these is out of scope. */
    public const DEFAULT_SCOPE = ['applet', 'caption', 'html', 'marquee', 'object', 'table', 'td', 'template', 'th'];

    /** Its "special" elements: <li>, <dd> and <dt> look for an open one up to these (but address, div, p). */
    public const SPECIAL = [
        'address', 'applet', 'area', 'article', 'aside', 'base', 'basefont', 'bgsound', 'blockquote', 'body', 'br',
        'button', 'caption', 'center', 'col', 'colgroup', 'dd', 'details', 'dir', 'div', 'dl', 'dt', 'embed', 'fieldset',
        'figcaption', 'figure', 'footer', 'form', 'frame', 'frameset', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'head', 'header',
        'hgroup', 'hr', 'html', 'iframe', 'img', 'input', 'keygen', 'li', 'link', 'listing', 'main', 'marquee', 'menu',
        'meta', 'nav', 'noembed', 'noframes', 'noscript', 'object', 'ol', 'p', 'param', 'plaintext', 'pre', 'script',
        'search', 'section', 'select', 'source', 'style', 'summary', 'table', 'tbody', 'td', 'template', 'textarea',
        'tfoot', 'th', 'thead', 'title', 'tr', 'track', 'ul', 'wbr', 'xmp',
    ];

    /** The markers in its list of active formatting elements: an <a> before one is not looked at. */
    public const FORMATTING_MARKERS = ['applet', 'caption', 'marquee', 'object', 'td', 'template', 'th'];

    private const HEADINGS = ['h1', 'h2', 'h3', 'h4', 'h5', 'h6'];

    /** In a chain: an SVG or MathML element (their content is parsed by other rules: nothing above it is looked at). */
    private const FOREIGN = '#foreign';

    /**
     * An element opens here: checked against what is open above it (prefix: elements between,
     * in a component's view; from: what it is from, for the message).
     *
     * @param  list<array{0: string, 1: ?int}>  $prefix  [tag, line], innermost first
     */
    public function opened(string $tag, array $prefix = [], string $from = ''): void
    {
        /** A call's and a slot's own elements: the call's view's elements are checked where it is called (called()). */
        if ($tag === ComponentCalls::TAG || $tag === ComponentCalls::SLOT) {
            return;
        }

        [$chain, $end] = $this->chain($prefix);
        [$error, $open] = self::verdict(strtolower($tag), $chain);

        /** What it is from, after the element the message begins with ("<section> (in <x-card>) inside <p> ..."), else at its end. */
        if ($error !== null) {
            throw $this->context->error(preg_match('/^<[^>]+>/', $error, $element) === 1 ? $element[0].$from.substr($error, strlen($element[0])) : $error.$from);
        }

        if ($open) {
            $this->keep($end, [$tag, $chain, $from]);
        }
    }

    /** A slot is placed here ({{ $slot }} in a Blade component's view): its content is checked where it lands. */
    public function slotPlaced(string $name, array $prefix = []): void
    {
        [$chain, $end] = $this->chain($prefix);

        if ($end instanceof TemplateNode) {
            $this->context->slotPassed[spl_object_id($end)][] = [$name, $chain];
        } elseif ($end === true) {
            $this->context->slotPlaces[$name][] = self::withoutLines($chain);
        }
    }

    /**
     * A Blade component's call, here: what its view keeps for the caller (its elements that reach
     * its top, its slots' places), with what the call gives (each slot's content).
     *
     * @param  array{reach: list<array{0: string, 1: list<array{0: string, 1: ?int}>, 2: string}>, slots: array<string, list<list<array{0: string, 1: ?int}>>>}  $view
     * @param  array<string, TemplateNode>  $slots  name => its <nitro-slot>
     */
    public function called(array $view, array $slots, string $label): void
    {
        foreach ($view['reach'] as [$tag, $chain, $from]) {
            $this->opened($tag, $chain, $from === '' ? " (in {$label})" : $from);
        }

        foreach ($slots as $name => $slot) {
            $id = spl_object_id($slot);

            foreach ($view['slots'][$name] ?? [] as $place) {
                foreach ($this->context->slotContent[$id] ?? [] as [$tag, $chain]) {
                    $this->opened($tag, [...$chain, ...$place], " (in the slot of {$label})");
                }

                foreach ($this->context->slotPassed[$id] ?? [] as [$passed, $chain]) {
                    $this->slotPlaced($passed, [...$chain, ...$place]);
                }
            }

            unset($this->context->slotContent[$id], $this->context->slotPassed[$id]);
        }
    }

    /**
     * What is open here, innermost first, up to where this view cannot see further: the top of a
     * component's view, or a <nitro-slot> (its content lands where the view places the slot).
     *
     * @param  list<array{0: string, 1: ?int}>  $prefix
     * @return array{0: list<array{0: string, 1: ?int}>, 1: TemplateNode|true|null} the chain, and where it ends: a slot, the view's top (true), the page (null)
     */
    private function chain(array $prefix): array
    {
        $chain = $prefix;

        foreach (array_reverse($this->context->stack) as $frame) {
            if (($frame['kind'] ?? null) !== 'element') {
                continue;
            }

            if ($frame['tag'] === ComponentCalls::SLOT) {
                return [$chain, $frame['node']];
            }

            $chain[] = [($frame['ns'] ?? 'html') === 'html' ? strtolower($frame['tag']) : self::FOREIGN, $frame['line']];
        }

        return [$chain, $this->compiler->componentView ? true : null];
    }

    /** An element's rule left undecided where this view ends: for its caller, or for where its slot lands. */
    private function keep(TemplateNode|bool|null $end, array $entry): void
    {
        if ($end instanceof TemplateNode) {
            $this->context->slotContent[spl_object_id($end)][] = [$entry[0], $entry[1]];
        } elseif ($end === true) {
            $this->context->nestingReach[] = [$entry[0], self::withoutLines($entry[1]), $entry[2]];
        }
    }

    /**
     * A chain kept for the caller: its lines are this view's file's, not the caller's.
     *
     * @param  list<array{0: string, 1: ?int}>  $chain
     * @return list<array{0: string, 1: null}>
     */
    private static function withoutLines(array $chain): array
    {
        return array_map(fn (array $element) => [$element[0], null], $chain);
    }

    /**
     * The parser's rules for a start tag under a chain of open elements: an error, or whether a
     * rule reached the chain's end undecided (open).
     *
     * @param  list<array{0: string, 1: ?int}>  $chain
     * @return array{0: ?string, 1: bool}
     */
    private static function verdict(string $tag, array $chain): array
    {
        $open = false;
        $walk = function (callable $step) use ($chain, &$open): ?string {
            foreach ($chain as [$element, $line]) {
                $result = $step($element, $line);

                if ($result !== null) {
                    return $result === '' ? null : $result;
                }
            }

            $open = true;

            return null;
        };
        $parent = $chain[0][0] ?? null;
        $errors = [];

        if (in_array($tag, self::CLOSES_P, true)) {
            $errors[] = $walk(fn (string $el, ?int $line) => match (true) {
                $el === 'p' => "<{$tag}> inside <p>".($line === null ? '' : " (line {$line})").": the browser ends the <p> before a <{$tag}>, so it cannot be in one. Close the <p> first, or make it a <div>",
                $el === self::FOREIGN || in_array($el, [...self::DEFAULT_SCOPE, 'button'], true) => '',
                default => null,
            });
        }

        $closes = match ($tag) {
            'li' => ['li'],
            'dd', 'dt' => ['dd', 'dt'],
            default => null,
        };

        if ($closes !== null) {
            $errors[] = $walk(fn (string $el) => match (true) {
                in_array($el, $closes, true) => "<{$tag}> inside an open <{$el}>: the browser ends the <{$el}> before it; close it first",
                $el === self::FOREIGN || (in_array($el, self::SPECIAL, true) && ! in_array($el, ['address', 'div', 'p'], true)) => '',
                default => null,
            });
        }

        /** In a table's cell or row ("in cell", "in row"): a row, a cell, a section or a caption ends the cell (and row) open around it. */
        if (in_array($tag, ['tr', 'td', 'th', 'tbody', 'thead', 'tfoot', 'caption', 'colgroup', 'col'], true)) {
            $errors[] = $walk(fn (string $el) => match (true) {
                in_array($el, ['td', 'th'], true) => "<{$tag}> inside an open <{$el}>: the browser ends the <{$el}> before it; close it first",
                $el === 'tr' && $tag !== 'td' && $tag !== 'th' => "<{$tag}> inside an open <tr>: the browser ends the <tr> before it; close it first",
                $el === self::FOREIGN || in_array($el, ['table', 'template', 'tr'], true) => '',
                default => null,
            });
        }

        /** In an <option> (up to its <select>): its text only; browsers parse markup in it differently (most keep only its text). */
        if ($tag !== 'option' && $tag !== 'optgroup') {
            $errors[] = $walk(fn (string $el) => match (true) {
                $el === 'option' => "<{$tag}> inside <option>: browsers read markup in an <option> differently (most keep only its text); put only text in it",
                $el === self::FOREIGN || $el === 'select' => '',
                default => null,
            });
        }

        $errors[] = match ($tag) {
            'button' => $walk(fn (string $el) => match (true) {
                $el === 'button' => '<button> inside <button>: the browser ends the outer button before it; buttons cannot nest',
                $el === self::FOREIGN || in_array($el, self::DEFAULT_SCOPE, true) => '',
                default => null,
            }),
            'a' => $walk(fn (string $el) => match (true) {
                $el === 'a' => '<a> inside <a>: the browser ends the outer link before it; links cannot nest',
                $el === self::FOREIGN || in_array($el, self::FORMATTING_MARKERS, true) => '',
                default => null,
            }),
            'form' => $walk(fn (string $el) => match (true) {
                $el === 'form' => '<form> inside <form>: the browser drops a form inside a form',
                $el === 'template' => '',
                default => null,
            }),
            'h1', 'h2', 'h3', 'h4', 'h5', 'h6' => self::direct($parent, $open, self::HEADINGS, "<{$tag}> directly inside <{$parent}>: the browser ends the <{$parent}> before it; close it first"),
            'option', 'optgroup' => self::direct($parent, $open, ['option'], "<{$tag}> directly inside <option>: the browser ends the <option> before it; close it first"),
            'tr' => self::direct($parent, $open, ['table'], 'Put the rows of a <table> inside <tbody>, <thead> or <tfoot> (the browser adds a <tbody> when it is missing)'),
            'td', 'th' => self::direct($parent, $open, ['table', 'tbody', 'thead', 'tfoot'], "Put a <{$tag}> inside a <tr> (the browser adds the <tr> when it is missing)"),
            'col' => self::direct($parent, $open, ['table'], 'Put a <col> inside a <colgroup> (the browser adds the <colgroup> when it is missing)'),
            default => null,
        };

        return [array_values(array_filter($errors))[0] ?? null, $open];
    }

    /** A rule on the element directly above: an error when it is one of $parents; open when there is none in the chain. */
    private static function direct(?string $parent, bool &$open, array $parents, string $error): ?string
    {
        if ($parent === null) {
            $open = true;

            return null;
        }

        return in_array($parent, $parents, true) ? $error : null;
    }
}
