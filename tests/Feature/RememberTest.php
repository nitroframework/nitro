<?php

namespace Nitro\Tests\Feature;

use Nitro\Build\Bundle;
use Nitro\Compiler\CompileException;
use Nitro\Compiler\ComponentReader;
use Nitro\Tests\Fixtures\Invalid\RememberedLocked;
use Nitro\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * #[Remember]: properties the browser keeps in the history entry (the runtime's part is in the
 * browser tests).
 */
class RememberTest extends TestCase
{
    #[DataProvider('frameworks')]
    public function test_a_remembered_property_is_marked_for_the_browser(string $framework): void
    {
        $this->boot($framework);

        $chunk = $this->app->make(Bundle::class)->compileAll()['greeting'];

        $this->assertStringContainsString('"shouted":{"locked":false,"default":""},"draft":{"locked":false,"default":"","remember":true}', $chunk);
    }

    public function test_a_locked_property_cannot_be_remembered(): void
    {
        $this->expectException(CompileException::class);
        $this->expectExceptionMessage('$total is #[Remember]: the browser brings its value back, so it is a plain value the browser can change');

        ComponentReader::read(RememberedLocked::class, 'remembered-locked', 'nitro.components.remembered-locked');
    }
}
