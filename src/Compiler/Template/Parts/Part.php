<?php

namespace Nitro\Fusion\Compiler\Template\Parts;

use Nitro\Fusion\Compiler\Template\TemplateCompiler;

/**
 * A part of the template compiler: one concern of turning a Blade view into a node tree and an
 * expression table (the HTML scanner, Blade's blocks, child components, ...). Each part gets the
 * TemplateCompiler, to reach the other parts through its properties
 * ($this->compiler->tree->append(...)), and the Context the parts share; never another part's
 * private state.
 *
 * @internal
 */
abstract class Part
{
    public function __construct(
        protected readonly TemplateCompiler $compiler,
        protected readonly Context $context,
    ) {}
}
