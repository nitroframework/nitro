<?php

namespace Nitro\Fusion\Compiler\Template\Parts;

use Nitro\Fusion\Compiler\Template\TemplateNode;

/**
 * The HTML scanner: the view's static text read as the browser's HTML parser reads it, into
 * elements, attributes, text and comments. General HTML, a state machine whose state carries over
 * from one Blade token to the next (Context::$state):
 *
 *  - data: text, up to a "<" that starts a tag, an end tag or a comment
 *  - tag: a start tag's attributes, up to ">" or "/>"; names are lower-cased in HTML, kept as
 *    written in SVG and MathML (and HTML again inside <foreignObject>)
 *  - value: a quoted attribute value, which echoes ({{ }}) may split into parts
 *  - comment, endtag: up to "-->" or ">"
 *  - raw (<script>, <style>) and rcdata (<textarea>, <title>): text up to the element's end tag,
 *    character references decoded in rcdata only
 *
 * Void elements (<br>, <input>) have no content; a self-closed element opens nothing in SVG or
 * MathML; </br> closes nothing. Every element must close, in order: an end tag that does not
 * match the open element is a compile error, as is a <tr> directly in a <table> (the browser
 * would insert a <tbody>, and the trees would differ).
 *
 * Fusion's syntax inside HTML is handed on: <fusion:...> tags and <fusion:slot> to
 * ChildComponents, fusion: attributes to FusionAttributes. Two Fusion details stay here, in
 * quoted values: an obfuscated build's names for the property in fusion:model and the targets
 * in fusion:target.
 *
 * @internal
 */
final class HtmlScanner extends Part
{
    /** A text token of the view, scanned from wherever the last one left the scanner. */
    public function textToken(string $text): void
    {
        $c = $this->context;

        if ($c->eatNewline) {
            $text = preg_replace('/\A\r?\n/', '', $text, 1, $eaten);
            $c->line += $eaten;
        }

        $length = strlen($text);
        $i = 0;
        $c->scanned = 0;

        while ($i < $length) {
            $this->lineAt($text, $i);

            $i = match ($c->state) {
                'data' => $this->data($text, $i),
                'tag' => $this->tag($text, $i),
                'value' => $this->value($text, $i),
                'comment' => $this->commentText($text, $i),
                'endtag' => $this->endTagText($text, $i),
                'raw', 'rcdata' => $this->rawText($text, $i),
                default => throw $c->error("Unknown scanner state {$c->state}"),
            };
        }
    }

    /** @param  array<string, mixed>  $part  static text ({c}) or an expression ({e, code}) of the quoted value being read */
    public function attributePart(array $part): void
    {
        $parts = &$this->context->tag->attributes[$this->context->attribute]['h'];
        $last = array_key_last($parts);

        if (isset($part['c']) && $last !== null && isset($parts[$last]['c'])) {
            $parts[$last]['c'] .= $part['c'];

            return;
        }

        $parts[] = $part;
    }

    /** A quoted attribute value begins: the attribute is added, its value read in the "value" state. */
    public function openValue(string $name, string $quote, int $i): int
    {
        $c = $this->context;
        $c->tag->attributes[] = ['c' => $name, 'h' => []];
        $c->attribute = count($c->tag->attributes) - 1;
        $c->quote = $quote;
        $c->state = 'value';

        return $i;
    }

    /** HTML spans lines: errors and handlers report the line they are on, not the text's first. */
    private function lineAt(string $text, int $offset): void
    {
        $c = $this->context;

        if ($offset > $c->scanned) {
            $c->line += substr_count($text, "\n", $c->scanned, $offset - $c->scanned);
            $c->scanned = $offset;
        }
    }

    private function data(string $text, int $i): int
    {
        $c = $this->context;
        $tree = $this->compiler->tree;
        $children = $this->compiler->childComponents;
        $lt = strpos($text, '<', $i);

        if ($lt === false) {
            $c->text .= substr($text, $i);

            return strlen($text);
        }

        $c->text .= substr($text, $i, $lt - $i);
        $this->lineAt($text, $lt);

        if (substr_compare($text, '<!--', $lt, 4) === 0) {
            $tree->flushText();
            $c->comment = '';
            $c->state = 'comment';

            return $lt + 4;
        }

        if (substr_compare($text, '<fusion:slot', $lt, 12, true) === 0 && preg_match('/\G<fusion:slot[\s\/>]/i', $text, $_, 0, $lt)) {
            return $children->slotOpen($text, $lt);
        }

        if (substr_compare($text, '<fusion:', $lt, 8, true) === 0) {
            return $children->child($text, $lt);
        }

        if (substr_compare($text, '</fusion:', $lt, 9, true) === 0) {
            return $children->childClose($text, $lt);
        }

        if (substr_compare($text, '</', $lt, 2) === 0) {
            $tree->flushText();
            $c->endTag = '';
            $c->state = 'endtag';

            return $lt + 2;
        }

        if (preg_match('/\G<([a-zA-Z][a-zA-Z0-9\-]*)/', $text, $m, 0, $lt)) {
            $tree->flushText();
            $this->startTag($m[1]);

            return $lt + strlen($m[0]);
        }

        $c->text .= '<';

        return $lt + 1;
    }

    private function startTag(string $name): void
    {
        $c = $this->context;
        $parent = $this->compiler->tree->element();
        $ns = $parent['ns'] ?? 'html';

        if ($ns === 'html' && in_array(strtolower($name), ['svg', 'math'], true)) {
            $ns = strtolower($name);
        }

        $tag = $ns === 'html' ? strtolower($name) : $name;

        if ($tag === 'tr' && $parent !== null && $parent['tag'] === 'table') {
            throw $c->error('Put the rows of a <table> inside <tbody>, <thead> or <tfoot> (the browser adds a <tbody> when it is missing)');
        }

        $c->tag = new TemplateNode(['t' => 't', 'c' => $tag]);
        $c->tagLine = $c->line;
        $c->state = 'tag';
    }

    /** The tag's ">" (or "/>"): the element opens, unless it is void or a self-closed SVG one. */
    private function finishTag(bool $selfClosing): void
    {
        $c = $this->context;
        $tree = $this->compiler->tree;
        $node = $c->tag;
        $c->tag = null;
        $tree->current()->children[] = $node;

        $tag = $node->fields['c'];
        $parentNs = $tree->element()['ns'] ?? 'html';
        $ns = $parentNs === 'html' && in_array($tag, ['svg', 'math'], true) ? $tag : $parentNs;

        if (in_array($tag, Syntax::VOID, true) || ($selfClosing && $ns !== 'html')) {
            $c->state = 'data';

            return;
        }

        /** The children of an SVG <foreignObject> are HTML again. */
        $childNs = $ns === 'svg' && strtolower($tag) === 'foreignobject' ? 'html' : $ns;
        $c->stack[] = ['kind' => 'element', 'node' => $node, 'line' => $c->tagLine, 'locals' => $c->locals, 'tag' => $tag, 'ns' => $childNs];

        $lower = strtolower($tag);
        $c->state = $ns !== 'html' ? 'data' : (in_array($lower, Syntax::RAW_TEXT, true) ? 'raw' : (in_array($lower, Syntax::RCDATA, true) ? 'rcdata' : 'data'));
    }

    private function tag(string $text, int $i): int
    {
        $c = $this->context;

        if (preg_match('/\G\s+/', $text, $m, 0, $i)) {
            return $i + strlen($m[0]);
        }

        if (substr_compare($text, '/>', $i, 2) === 0) {
            $this->finishTag(true);

            return $i + 2;
        }

        if ($text[$i] === '>') {
            $this->finishTag(false);

            return $i + 1;
        }

        if (preg_match('/\Gfusion:([\w\-.]+)/', $text, $m, 0, $i)) {
            return $this->compiler->fusionAttributes->fusionAttribute($text, $i, $m[1]);
        }

        if (preg_match('/\G([^\s"\'>\/=]+)(\s*=\s*)?/', $text, $m, 0, $i)) {
            $name = ($this->compiler->tree->element()['ns'] ?? 'html') === 'html' && ! in_array($c->tag->fields['c'], ['svg', 'math'], true)
                ? strtolower($m[1])
                : $m[1];
            $i += strlen($m[0]);

            if (($m[2] ?? '') === '') {
                $c->tag->attributes[] = ['c' => $name];

                return $i;
            }

            if ($i < strlen($text) && ($text[$i] === '"' || $text[$i] === "'")) {
                return $this->openValue($name, $text[$i], $i + 1);
            }

            /** An unquoted value runs to whitespace or >. */
            preg_match('/\G[^\s>]*/', $text, $v, 0, $i);
            $c->tag->attributes[] = ['c' => $name, 'h' => [['c' => html_entity_decode($v[0], ENT_QUOTES | ENT_HTML5, 'UTF-8')]]];

            return $i + strlen($v[0]);
        }

        return $i + 1;
    }

    private function value(string $text, int $i): int
    {
        $c = $this->context;
        $end = strpos($text, $c->quote, $i);
        $static = $end === false ? substr($text, $i) : substr($text, $i, $end - $i);

        if ($static !== '') {
            $decoded = html_entity_decode($static, ENT_QUOTES | ENT_HTML5, 'UTF-8');

            /** fusion:model's property by the browser's name for it: the path's start (an obfuscated build). */
            $attribute = $c->tag->attributes[$c->attribute];

            if ($c->scope->names !== null && ($attribute['c'] === 'fusion:model' || str_starts_with($attribute['c'], 'fusion:model.')) && $attribute['h'] === []) {
                $decoded = $c->scope->browserPath($decoded);
            }

            /** fusion:target's methods, and properties (an upload's), by the browser's names for them (an obfuscated build). */
            if ($c->scope->names !== null && $c->tag->attributes[$c->attribute]['c'] === 'fusion:target') {
                $decoded = implode(',', array_map(fn (string $target) => match (true) {
                    $c->scope->isClientMethod(trim($target)) || $c->scope->isServerMethod(trim($target)) => $c->scope->browserMethod(trim($target)),
                    $c->scope->isProperty(trim($target)) => $c->scope->browserProperty(trim($target)),
                    default => trim($target),
                }, explode(',', $decoded)));
            }

            $this->attributePart(['c' => $decoded]);
        }

        if ($end === false) {
            return strlen($text);
        }

        $c->state = 'tag';

        return $end + 1;
    }

    private function commentText(string $text, int $i): int
    {
        $c = $this->context;
        $end = strpos($text, '-->', $i);

        if ($end === false) {
            $c->comment .= substr($text, $i);

            return strlen($text);
        }

        $c->comment .= substr($text, $i, $end - $i);
        $this->compiler->tree->current()->children[] = new TemplateNode(['t' => 'm', 'c' => $c->comment]);
        $c->state = 'data';

        return $end + 3;
    }

    private function endTagText(string $text, int $i): int
    {
        $c = $this->context;
        $end = strpos($text, '>', $i);

        if ($end === false) {
            $c->endTag .= substr($text, $i);

            return strlen($text);
        }

        $c->endTag .= substr($text, $i, $end - $i);
        $c->state = 'data';
        $this->closeElement(trim($c->endTag));

        return $end + 1;
    }

    private function closeElement(string $name): void
    {
        $c = $this->context;
        $top = end($c->stack);
        $html = ($top['ns'] ?? 'html') === 'html' || in_array(strtolower($name), ['svg', 'math'], true);
        $tag = $html ? strtolower($name) : $name;

        /** </br>, </input>: nothing to close. */
        if (in_array(strtolower($tag), Syntax::VOID, true)) {
            return;
        }

        if ($top['kind'] !== 'element') {
            throw $c->error($top['kind'] === 'root'
                ? "</{$tag}> closes nothing that is open"
                : "</{$tag}> closes an element opened outside the @{$top['kind']} of line {$top['line']}: open and close an element inside the same block");
        }

        if (strcasecmp($top['tag'], $tag) !== 0) {
            throw $c->error("</{$tag}> does not match <{$top['tag']}> from line {$top['line']}: close every element, in order");
        }

        array_pop($c->stack);
    }

    /** Inside <script>/<style> (raw) or <textarea>/<title>: text up to the end tag. */
    private function rawText(string $text, int $i): int
    {
        $c = $this->context;
        $tag = $this->compiler->tree->element()['tag'];

        if (! preg_match('/<\/'.preg_quote($tag, '/').'\b/i', $text, $m, PREG_OFFSET_CAPTURE, $i)) {
            $c->text .= substr($text, $i);

            return strlen($text);
        }

        $at = $m[0][1];
        $c->text .= substr($text, $i, $at - $i);
        $this->compiler->tree->flushText();
        $c->endTag = '';
        $c->state = 'endtag';

        return $at + 2;
    }
}
