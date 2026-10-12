<?php

namespace Nitro\Tests\Browser;

use Nitro\Tests\Support\Node;
use PHPUnit\Framework\TestCase;

/**
 * lazy() (nitro/lazy): a framework's engine registered at once, its adapter imported with the
 * first view that loads, once. The browser tests run the fixture's Solid views through it.
 */
class LazyEngineTest extends TestCase
{
    public function test_the_adapter_loads_with_the_first_view_and_is_kept(): void
    {
        $failures = Node::run('js/test/support/run-lazy.mjs', []);

        $this->assertSame([], $failures);
    }
}
