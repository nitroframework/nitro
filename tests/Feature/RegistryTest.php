<?php

namespace Nitro\Tests\Feature;

use Nitro\Support\Registry;
use Nitro\Tests\TestCase;

/**
 * The component list nitro:build caches: used while the component directories are as they were,
 * found again once a file was added, removed or renamed in them (a component made after a build).
 */
class RegistryTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir().'/nitro-registry-'.bin2hex(random_bytes(4));
        mkdir($this->root.'/Components/Shop', 0777, true);
    }

    protected function tearDown(): void
    {
        foreach (['Components/Shop/Basket.php', 'Components/Alpha.php', 'Components/Beta.php', 'cache.php'] as $file) {
            @unlink($this->root.'/'.$file);
        }
        @rmdir($this->root.'/Components/Shop');
        @rmdir($this->root.'/Components');
        @rmdir($this->root);
        parent::tearDown();
    }

    public function test_the_cached_list_is_used_until_a_directory_changes(): void
    {
        $namespace = 'NitroRegistryTest'.bin2hex(random_bytes(4));
        $this->component($namespace, 'Alpha');

        file_put_contents($this->root.'/cache.php', $this->registry($namespace)->export());
        $cached = require $this->root.'/cache.php';
        $this->assertSame(['alpha'], array_keys($cached['components']));

        /** Unchanged directories: the cache is the list (a name only it has proves it was read). */
        $cached['components']['from-the-cache'] = $namespace.'\\Alpha';
        file_put_contents($this->root.'/cache.php', '<?php return '.var_export($cached, true).';');
        $this->assertNotNull($this->registry($namespace)->class('from-the-cache'));

        /** A component added since: its directory changed, so the components are found again. */
        $this->component($namespace, 'Beta');
        touch($this->root.'/Components', time() + 10);
        $registry = $this->registry($namespace);
        $this->assertSame($namespace.'\\Beta', $registry->class('beta'));
        $this->assertNull($registry->class('from-the-cache'), 'the stale list is not used');

        /** In a subdirectory too. */
        file_put_contents($this->root.'/cache.php', $registry->export());
        $this->component($namespace.'\\Shop', 'Basket', 'Components/Shop');
        touch($this->root.'/Components/Shop', time() + 20);
        $this->assertSame($namespace.'\\Shop\\Basket', $this->registry($namespace)->class('shop.basket'));
    }

    private function registry(string $namespace): Registry
    {
        return new Registry(
            ['namespace' => $namespace, 'path' => $this->root.'/Components', 'views' => 'nitro.components'],
            ['namespace' => $namespace.'\\Pages', 'path' => $this->root.'/Pages', 'views' => 'nitro.pages'],
            $this->root.'/cache.php',
        );
    }

    /** A component class in a file of its own, loaded (the namespace is not autoloaded). */
    private function component(string $namespace, string $class, string $directory = 'Components'): void
    {
        $file = $this->root.'/'.$directory.'/'.$class.'.php';
        file_put_contents($file, "<?php\n\nnamespace {$namespace};\n\nclass {$class} extends \\Nitro\\Component\n{\n}\n");
        require $file;
    }
}
