<?php

namespace Nitro\Fusion\Tests\Feature;

use Illuminate\Support\Facades\Route;
use Nitro\Fusion\Build\Bundle;
use Nitro\Fusion\Compiler\CompileException;
use Nitro\Fusion\Component;
use Nitro\Fusion\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * In development, a component that does not compile for the browser fails the pages that use it,
 * with its error; the other pages work. A build still fails on it.
 */
class BrokenComponentTest extends TestCase
{
    private const VIEWS = self::APP.'/resources/views';

    #[DataProvider('frameworks')]
    public function test_only_the_pages_that_use_it_fail(string $framework): void
    {
        $this->boot($framework);
        file_put_contents(self::VIEWS.'/fusion/components/broken-thing.blade.php', "<div>\n    @php \$x = 1; @endphp\n</div>\n");
        file_put_contents(self::VIEWS.'/broken-page.blade.php', "<html><body><fusion:broken-thing />@fusionScripts</body></html>\n");

        try {
            app(\Nitro\Fusion\Support\Registry::class)->register('broken-thing', BrokenThing::class);
            Route::middleware('web')->get('/broken-page', fn () => view('broken-page'));

            $this->assertSame(200, $this->get('/counter')->getStatusCode(), 'a page without it works');
            $this->assertSame(200, $this->get('/_fusion/app.js?v='.$this->app->make(Bundle::class)->tag())->getStatusCode(), 'and so does the bundle');
            $this->assertSame(500, $this->get('/broken-page')->getStatusCode(), 'a page with it fails');

            try {
                $this->app->make(Bundle::class)->assertCompiled(['counter', 'broken-thing']);
                $this->fail('its error is raised');
            } catch (CompileException $e) {
                $this->assertStringContainsString('broken-thing.blade.php', (string) $e->sourceFile);
                $this->assertSame(2, $e->sourceLine);
            }

            $this->expectException(CompileException::class);
            $this->app->make(Bundle::class)->compileAll(build: true);
        } finally {
            @unlink(self::VIEWS.'/fusion/components/broken-thing.blade.php');
            @unlink(self::VIEWS.'/broken-page.blade.php');
        }
    }
}

class BrokenThing extends Component
{
}
