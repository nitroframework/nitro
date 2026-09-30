<?php

namespace Nitro\Fusion\Compiler;

use RuntimeException;

/**
 * Code that cannot run in the browser, reported at the file and line it came from.
 */
class CompileException extends RuntimeException
{
    /** What is wrong, without where (the message adds "in <file> on line <n>"). */
    public readonly string $reason;

    public function __construct(string $message, public readonly ?string $sourceFile = null, public readonly ?int $sourceLine = null)
    {
        $this->reason = $message;

        parent::__construct($sourceFile === null ? $message : sprintf('%s in %s on line %d', $message, $sourceFile, $sourceLine ?? 0));
    }
}
