<?php

namespace Nitro\Compiler\Template;

/**
 * What the views share once the application has booted (View::share() in a provider): the same
 * whether a view compiles during a request or in nitro:build. What a request's middleware shares
 * later is not among it, so a view compiles the same either way (BladeInliner).
 *
 * @internal
 */
final class SharedAtBoot
{
    /** @param  list<string>  $names */
    public function __construct(public readonly array $names)
    {
    }
}
