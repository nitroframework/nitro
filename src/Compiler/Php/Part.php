<?php

namespace Nitro\Fusion\Compiler\Php;

use Nitro\Fusion\Compiler\Transpiler;

/**
 * A part of the transpiler: one concern of compiling PHP to JavaScript (statements, writes,
 * calls, ...). Each part gets the Transpiler, to compile the expressions and statements nested in
 * what it compiles (through Transpiler::expr() and stmts(), which send each node to its part),
 * and the Context the parts share. Parts reach each other only through the Transpiler's
 * properties ($this->transpiler->writes->target(...)), never through shared private state.
 *
 * @internal
 */
abstract class Part
{
    public function __construct(
        protected readonly Transpiler $transpiler,
        protected readonly Context $context,
    ) {}
}
