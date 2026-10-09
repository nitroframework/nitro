<?php

namespace Nitro\Compiler\Template;

/**
 * A piece of a Blade template: text, an echo ({{ }} / {!! !!}) or a directive (@name(args)).
 */
final class Token
{
    public const TEXT = 'text';

    public const ECHO = 'echo';

    public const RAW = 'raw';

    public const DIRECTIVE = 'directive';

    /** Text from @verbatim: output as it is, never scanned for tags. */
    public const VERBATIM = 'verbatim';

    public function __construct(
        public readonly string $kind,
        public readonly string $value,
        public readonly int $line,
        /** Directive arguments without the outer parentheses; null when there are none. */
        public readonly ?string $args = null,
    ) {
    }

    public function is(string $kind, ?string $value = null): bool
    {
        return $this->kind === $kind && ($value === null || $this->value === $value);
    }
}
