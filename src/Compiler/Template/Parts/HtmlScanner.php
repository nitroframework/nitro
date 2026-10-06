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
        $context = $this->context;

        if ($context->eatNewline) {
            $text = preg_replace('/\A\r?\n/', '', $text, 1, $eaten);
            $context->line += $eaten;
        }

        $length = strlen($text);
        $i = 0;
        $context->scanned = 0;

        while ($i < $length) {
            $this->lineAt($text, $i);

            $i = match ($context->state) {
                'data' => $this->data($text, $i),
                'tag' => $this->tag($text, $i),
                'value' => $this->value($text, $i),
                'comment' => $this->commentText($text, $i),
                'endtag' => $this->endTagText($text, $i),
                'raw', 'rcdata' => $this->rawText($text, $i),
                default => throw $context->error("Unknown scanner state {$context->state}"),
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
        $context = $this->context;
        $context->tag->attributes[] = ['c' => $name, 'h' => []];
        $context->attribute = count($context->tag->attributes) - 1;
        $context->quote = $quote;
        $context->state = 'value';

        return $i;
    }

    /** HTML spans lines: errors and handlers report the line they are on, not the text's first. */
    private function lineAt(string $text, int $offset): void
    {
        $context = $this->context;

        if ($offset > $context->scanned) {
            $context->line += substr_count($text, "\n", $context->scanned, $offset - $context->scanned);
            $context->scanned = $offset;
        }
    }

    private function data(string $text, int $i): int
    {
        $context = $this->context;
        $tree = $this->compiler->tree;
        $children = $this->compiler->childComponents;
        $tagStart = strpos($text, '<', $i);

        if ($tagStart === false) {
            $context->text .= substr($text, $i);

            return strlen($text);
        }

        $context->text .= substr($text, $i, $tagStart - $i);
        $this->lineAt($text, $tagStart);

        if (substr_compare($text, '<!--', $tagStart, 4) === 0) {
            $tree->flushText();
            $context->comment = '';
            $context->state = 'comment';

            return $tagStart + 4;
        }

        if (substr_compare($text, '<fusion:slot', $tagStart, 12, true) === 0 && preg_match('/\G<fusion:slot[\s\/>]/i', $text, $_, 0, $tagStart)) {
            return $children->slotOpen($text, $tagStart);
        }

        if (substr_compare($text, '<fusion:', $tagStart, 8, true) === 0) {
            return $children->child($text, $tagStart);
        }

        if (substr_compare($text, '</fusion:', $tagStart, 9, true) === 0) {
            return $children->childClose($text, $tagStart);
        }

        if (substr_compare($text, '</', $tagStart, 2) === 0) {
            $tree->flushText();
            $context->endTag = '';
            $context->state = 'endtag';

            return $tagStart + 2;
        }

        if (preg_match('/\G<([a-zA-Z][a-zA-Z0-9\-]*)/', $text, $match, 0, $tagStart)) {
            $tree->flushText();
            $this->startTag($match[1]);

            return $tagStart + strlen($match[0]);
        }

        $context->text .= '<';

        return $tagStart + 1;
    }

    private function startTag(string $name): void
    {
        $context = $this->context;
        $parent = $this->compiler->tree->element();
        $namespace = $parent['ns'] ?? 'html';

        if ($namespace === 'html' && in_array(strtolower($name), ['svg', 'math'], true)) {
            $namespace = strtolower($name);
        }

        $tag = $namespace === 'html' ? strtolower($name) : $name;

        if ($tag === 'tr' && $parent !== null && $parent['tag'] === 'table') {
            throw $context->error('Put the rows of a <table> inside <tbody>, <thead> or <tfoot> (the browser adds a <tbody> when it is missing)');
        }

        $context->tag = new TemplateNode(['t' => 't', 'c' => $tag]);
        $context->tagLine = $context->line;
        $context->state = 'tag';
    }

    /** The tag's ">" (or "/>"): the element opens, unless it is void or a self-closed SVG one. */
    private function finishTag(bool $selfClosing): void
    {
        $context = $this->context;
        $tree = $this->compiler->tree;
        $node = $context->tag;
        $context->tag = null;
        $tree->current()->children[] = $node;

        $tag = $node->fields['c'];
        $parentNs = $tree->element()['ns'] ?? 'html';
        $namespace = $parentNs === 'html' && in_array($tag, ['svg', 'math'], true) ? $tag : $parentNs;

        if ($parentNs === 'html' && in_array(strtolower($tag), Syntax::CLOSES_P, true)) {
            $this->assertNoOpenP($tag);
        }

        if (in_array($tag, Syntax::VOID, true) || ($selfClosing && $namespace !== 'html')) {
            $context->state = 'data';

            return;
        }

        /** The children of an SVG <foreignObject> are HTML again. */
        $childNs = $namespace === 'svg' && strtolower($tag) === 'foreignobject' ? 'html' : $namespace;
        $context->stack[] = ['kind' => 'element', 'node' => $node, 'line' => $context->tagLine, 'locals' => $context->locals, 'tag' => $tag, 'ns' => $childNs];

        $lower = strtolower($tag);
        $context->state = $namespace !== 'html' ? 'data' : (in_array($lower, Syntax::RAW_TEXT, true) ? 'raw' : (in_array($lower, Syntax::RCDATA, true) ? 'rcdata' : 'data'));
    }

    /**
     * <div> (and the other Syntax::CLOSES_P) inside an open <p>: the browser's HTML parser ends the
     * <p> there, so the server's HTML would not be the tree the view says.
     */
    private function assertNoOpenP(string $tag): void
    {
        $context = $this->context;

        foreach (array_reverse($context->stack) as $frame) {
            if (($frame['kind'] ?? null) !== 'element') {
                continue;
            }

            $open = strtolower($frame['tag']);

            if ($open === 'p') {
                throw $context->error("<{$tag}> inside <p> (line {$frame['line']}): the browser ends the <p> before a <{$tag}>, so it cannot be in one. Close the <p> first, or make it a <div>");
            }

            if (in_array($open, Syntax::P_SCOPE, true) || ($frame['ns'] ?? 'html') !== 'html') {
                return;
            }
        }
    }

    private function tag(string $text, int $i): int
    {
        $context = $this->context;

        if (preg_match('/\G\s+/', $text, $match, 0, $i)) {
            return $i + strlen($match[0]);
        }

        if (substr_compare($text, '/>', $i, 2) === 0) {
            $this->finishTag(true);

            return $i + 2;
        }

        if ($text[$i] === '>') {
            $this->finishTag(false);

            return $i + 1;
        }

        if (preg_match('/\Gfusion:([\w\-.]+)/', $text, $match, 0, $i)) {
            return $this->compiler->fusionAttributes->fusionAttribute($text, $i, $match[1]);
        }

        if (preg_match('/\G([^\s"\'>\/=]+)(\s*=\s*)?/', $text, $match, 0, $i)) {
            $name = ($this->compiler->tree->element()['ns'] ?? 'html') === 'html' && ! in_array($context->tag->fields['c'], ['svg', 'math'], true)
                ? strtolower($match[1])
                : $match[1];
            $i += strlen($match[0]);

            if (($match[2] ?? '') === '') {
                $context->tag->attributes[] = ['c' => $name];

                return $i;
            }

            if ($i < strlen($text) && ($text[$i] === '"' || $text[$i] === "'")) {
                return $this->openValue($name, $text[$i], $i + 1);
            }

            /** An unquoted value runs to whitespace or >. */
            preg_match('/\G[^\s>]*/', $text, $valueMatch, 0, $i);
            $context->tag->attributes[] = ['c' => $name, 'h' => [['c' => html_entity_decode($valueMatch[0], ENT_QUOTES | ENT_HTML5, 'UTF-8')]]];

            return $i + strlen($valueMatch[0]);
        }

        return $i + 1;
    }

    private function value(string $text, int $i): int
    {
        $context = $this->context;
        $end = strpos($text, $context->quote, $i);
        $static = $end === false ? substr($text, $i) : substr($text, $i, $end - $i);

        if ($static !== '') {
            $decoded = html_entity_decode($static, ENT_QUOTES | ENT_HTML5, 'UTF-8');

            /** fusion:model's property by the browser's name for it: the path's start (an obfuscated build). */
            $attribute = $context->tag->attributes[$context->attribute];

            if ($context->scope->names !== null && ($attribute['c'] === 'fusion:model' || str_starts_with($attribute['c'], 'fusion:model.')) && $attribute['h'] === []) {
                $decoded = $context->scope->browserPath($decoded);
            }

            /** fusion:target's methods, and properties or paths (an upload's, fusion:dirty's), by the browser's names for them (an obfuscated build). */
            if ($context->scope->names !== null && $context->tag->attributes[$context->attribute]['c'] === 'fusion:target') {
                $decoded = implode(',', array_map(fn (string $target) => match (true) {
                    $context->scope->isClientMethod(trim($target)) || $context->scope->isServerMethod(trim($target)) => $context->scope->browserMethod(trim($target)),
                    $context->scope->isProperty(explode('.', trim($target))[0]) => $context->scope->browserPath(trim($target)),
                    default => trim($target),
                }, explode(',', $decoded)));
            }

            $this->attributePart(['c' => $decoded]);
        }

        if ($end === false) {
            return strlen($text);
        }

        $context->state = 'tag';

        return $end + 1;
    }

    private function commentText(string $text, int $i): int
    {
        $context = $this->context;
        $end = strpos($text, '-->', $i);

        if ($end === false) {
            $context->comment .= substr($text, $i);

            return strlen($text);
        }

        $context->comment .= substr($text, $i, $end - $i);
        $this->compiler->tree->current()->children[] = new TemplateNode(['t' => 'm', 'c' => $context->comment]);
        $context->state = 'data';

        return $end + 3;
    }

    private function endTagText(string $text, int $i): int
    {
        $context = $this->context;
        $end = strpos($text, '>', $i);

        if ($end === false) {
            $context->endTag .= substr($text, $i);

            return strlen($text);
        }

        $context->endTag .= substr($text, $i, $end - $i);
        $context->state = 'data';
        $this->closeElement(trim($context->endTag));

        return $end + 1;
    }

    private function closeElement(string $name): void
    {
        $context = $this->context;
        $top = end($context->stack);
        $html = ($top['ns'] ?? 'html') === 'html' || in_array(strtolower($name), ['svg', 'math'], true);
        $tag = $html ? strtolower($name) : $name;

        /** </br>, </input>: nothing to close. */
        if (in_array(strtolower($tag), Syntax::VOID, true)) {
            return;
        }

        if ($top['kind'] !== 'element') {
            throw $context->error($top['kind'] === 'root'
                ? "</{$tag}> closes nothing that is open"
                : "</{$tag}> closes an element opened outside the @{$top['kind']} of line {$top['line']}: open and close an element inside the same block");
        }

        if (strcasecmp($top['tag'], $tag) !== 0) {
            throw $context->error("</{$tag}> does not match <{$top['tag']}> from line {$top['line']}: close every element, in order");
        }

        array_pop($context->stack);
    }

    /** Inside <script>/<style> (raw) or <textarea>/<title>: text up to the end tag. */
    private function rawText(string $text, int $i): int
    {
        $context = $this->context;
        $tag = $this->compiler->tree->element()['tag'];

        if (! preg_match('/<\/'.preg_quote($tag, '/').'\b/i', $text, $match, PREG_OFFSET_CAPTURE, $i)) {
            $context->text .= substr($text, $i);

            return strlen($text);
        }

        $at = $match[0][1];
        $context->text .= substr($text, $i, $at - $i);
        $this->compiler->tree->flushText();
        $context->endTag = '';
        $context->state = 'endtag';

        return $at + 2;
    }
}
