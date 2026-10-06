<?php

namespace Nitro\Fusion\Compiler\Template;

use Closure;
use Nitro\Fusion\Compiler\CompileException;

/**
 * Splits a Blade template into tokens the way Blade reads it: comments are dropped, @verbatim
 * and escaped echoes (@{{ }}) stay text, and "@name" is a directive only when Blade has one of
 * that name (anything else, such as Alpine's @click or an e-mail address, stays text).
 */
final class Lexer
{
    private string $source = '';

    private int $position = 0;

    private int $line = 1;

    /** @var list<Token> */
    private array $tokens = [];

    private string $text = '';

    private int $textLine = 1;

    /** Where BladeInliner's last marker ended: a directive right after it is one (written text "@endif" after a word is not). */
    private int $afterMarker = -1;

    /** @param  Closure(string): bool  $isDirective  whether Blade compiles @name */
    public function __construct(private readonly Closure $isDirective, private readonly ?string $file = null)
    {
    }

    /** @return list<Token> */
    public function tokenize(string $source): array
    {
        $this->source = $source;
        $this->position = 0;
        $this->line = 1;
        $this->tokens = [];
        $this->text = '';
        $this->textLine = 1;

        while ($this->position < strlen($source)) {
            match (true) {
                $this->startsWith('{{--') => $this->comment(),
                $this->startsWith('@{{') => $this->escapedEcho(),
                $this->startsWith('{!!') => $this->echo(Token::RAW, '{!!', '!!}'),
                $this->startsWith('{{{') => $this->echo(Token::ECHO, '{{{', '}}}'),
                $this->startsWith('{{') => $this->echo(Token::ECHO, '{{', '}}'),
                $source[$this->position] === '@' => $this->at(),
                $this->startsWith('<fusion:') && $this->childTag() => null,
                default => $this->character(),
            };
        }

        $this->flush();

        return $this->tokens;
    }

    private function startsWith(string $prefix): bool
    {
        return substr_compare($this->source, $prefix, $this->position, strlen($prefix)) === 0;
    }

    private function comment(): void
    {
        $end = $this->find('--}}', $this->position + 4, 'Unclosed {{-- comment');
        $this->flush();
        $this->advance($end + 4);
        $this->textLine = $this->line;
    }

    /** Blade prints "{{ ... }}" as it is, without the @. */
    private function escapedEcho(): void
    {
        $end = $this->find('}}', $this->position + 3, 'Unclosed @{{');
        $this->text .= substr($this->source, $this->position + 1, $end + 2 - $this->position - 1);
        $this->advance($end + 2);
    }

    private function echo(string $kind, string $open, string $close): void
    {
        $this->flush();
        $end = $this->find($close, $this->position + strlen($open), "Unclosed {$open}");
        $expression = trim(substr($this->source, $this->position + strlen($open), $end - $this->position - strlen($open)));
        $this->tokens[] = new Token($kind, $expression, $this->line);
        $this->advance($end + strlen($close));
        $this->textLine = $this->line;
    }

    private function at(): void
    {
        $preceding = $this->position > 0 ? $this->source[$this->position - 1] : '';
        /** BladeInliner's markers sit wherever an inlined view begins or ends, after a word too. */
        $inlined = preg_match('/\G@(?:(?:end)?fusion(?:Scope|Slot|Frame)|fusionAttr)\b/', $this->source, $_, 0, $this->position) === 1;
        $afterMarker = $this->position === $this->afterMarker;

        if ((preg_match('/\w/', $preceding) && ! $inlined && ! $afterMarker) || ! preg_match('/\G@(@?)(\w+(?:::\w+)?)/', $this->source, $match, 0, $this->position)) {
            $this->character();

            return;
        }

        [$whole, $escaped, $name] = $match;

        if ($escaped === '@') {
            /** @@name prints "@name". */
            $this->text .= '@'.$name;
            $this->advance($this->position + strlen($whole));

            return;
        }

        if ($name === 'verbatim') {
            $end = $this->find('@endverbatim', $this->position + 9, 'Unclosed @verbatim');
            $this->flush();
            $this->tokens[] = new Token(Token::VERBATIM, substr($this->source, $this->position + 9, $end - $this->position - 9), $this->line);
            $this->advance($end + 12);
            $this->textLine = $this->line;

            return;
        }

        if (! ($this->isDirective)($name)) {
            $this->text .= $whole;
            $this->advance($this->position + strlen($whole));

            return;
        }

        $this->flush();
        $line = $this->line;
        $after = $this->position + strlen($whole);
        $args = null;

        if (preg_match('/\G[ \t]*\(/', $this->source, $open, 0, $after)) {
            $start = $after + strlen($open[0]);
            $close = $this->closingParenthesis($start);
            $args = substr($this->source, $start, $close - $start);
            $after = $close + 1;
        }

        $this->tokens[] = new Token(Token::DIRECTIVE, $name, $line, $args);
        $this->advance($after);
        $this->textLine = $this->line;

        if ($inlined) {
            $this->afterMarker = $after;
        }
    }

    /** Plain text, up to the next place a Blade construct could start ({ or @). */
    /**
     * A child's tag (<fusion:name ...>) is text as a whole, {{ }} in it too: ChildTag reads it as
     * the server's Blade precompiler does (fusion:model="lines.{{ $i }}.qty").
     */
    private function childTag(): bool
    {
        if (! preg_match(substr(ChildTag::PATTERN, 0, 1).'\G'.substr(ChildTag::PATTERN, 1), $this->source, $match, 0, $this->position)) {
            return false;
        }

        $this->text .= $match[0];
        $this->advance($this->position + strlen($match[0]));

        return true;
    }

    private function character(): void
    {
        $next = strcspn($this->source, '{@<', $this->position + 1) + $this->position + 1;
        $this->text .= substr($this->source, $this->position, $next - $this->position);
        $this->advance($next);
    }

    private function flush(): void
    {
        if ($this->text !== '') {
            $this->tokens[] = new Token(Token::TEXT, $this->text, $this->textLine);
        }

        $this->text = '';
        $this->textLine = $this->line;
    }

    private function advance(int $to): void
    {
        $this->line += substr_count($this->source, "\n", $this->position, $to - $this->position);
        $this->position = $to;
    }

    private function find(string $needle, int $from, string $message): int
    {
        $at = strpos($this->source, $needle, $from);

        if ($at === false) {
            throw new CompileException($message, $this->file, $this->line);
        }

        return $at;
    }

    /** The ) matching an opening parenthesis, skipping strings. */
    private function closingParenthesis(int $from): int
    {
        $depth = 1;
        $quote = null;

        for ($i = $from, $length = strlen($this->source); $i < $length; $i++) {
            $context = $this->source[$i];

            if ($quote !== null) {
                if ($context === '\\') {
                    $i++;
                } elseif ($context === $quote) {
                    $quote = null;
                }

                continue;
            }

            if ($context === '"' || $context === "'") {
                $quote = $context;
            } elseif ($context === '(') {
                $depth++;
            } elseif ($context === ')' && --$depth === 0) {
                return $i;
            }
        }

        throw new CompileException('Unclosed parenthesis in a directive', $this->file, $this->line);
    }
}
