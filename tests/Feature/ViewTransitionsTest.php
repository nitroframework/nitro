<?php

namespace Nitro\Fusion\Tests\Feature;

use Nitro\Fusion\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * fusion.view_transitions: @fusionScripts tells the runtime, which changes the page on a visit
 * inside the browser's View Transitions (navigate.js transition()). fusion:navigate.transition
 * turns them on for one link (CompileErrorsTest).
 */
class ViewTransitionsTest extends TestCase
{
    #[DataProvider('frameworks')]
    public function test_the_runtime_is_told_when_they_are_on(string $framework): void
    {
        $this->boot($framework);

        $this->assertStringNotContainsString('data-view-transitions', (string) $this->get('/embed')->getContent(), 'off by default');

        $this->app->make('config')->set('fusion.view_transitions', true);
        $this->app->forgetScopedInstances();

        $this->assertStringContainsString(' data-view-transitions="1"', (string) $this->get('/embed')->getContent());
    }
}
