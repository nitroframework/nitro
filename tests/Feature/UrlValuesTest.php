<?php

namespace Nitro\Tests\Feature;

use Nitro\Support\UrlValues;
use Nitro\Tests\Support\Node;
use PHPUnit\Framework\TestCase;

/**
 * A value from a URL means the same on both sides: the server (#[Url] properties, route
 * parameters) and the browser (client pages' route parameters) read it by one rule set.
 */
final class UrlValuesTest extends TestCase
{
    /** @return list<array{0: string, 1: string}> */
    private static function inputs(): array
    {
        $ints = ['0', '7', '-3', '+5', '007', '-0', '1.0', ' 7', '7 ', '', 'abc', '1e3', '9007199254740991', '9007199254740992', '-9007199254740991', '99999999999999999999'];
        $floats = ['0', '1.5', '-.5', '.5', '5.', '2e3', '-2E-3', '0x10', '1,5', ' 1', 'Infinity', 'NaN', '', '+1.25', '1e400'];
        $bools = ['1', '0', 'true', 'false', 'TRUE', '', 'yes', '2'];

        return [
            ...array_map(fn (string $raw) => ['int', $raw], $ints),
            ...array_map(fn (string $raw) => ['float', $raw], $floats),
            ...array_map(fn (string $raw) => ['bool', $raw], $bools),
            ['string', ' as it is '],
        ];
    }

    public function test_the_rules(): void
    {
        $this->assertSame([true, 7], UrlValues::read('int', '7'));
        $this->assertSame([true, -3], UrlValues::read('int', '-3'));
        $this->assertSame([false, null], UrlValues::read('int', '007'), 'no leading zero');
        $this->assertSame([false, null], UrlValues::read('int', ' 7'), 'no whitespace');
        $this->assertSame([false, null], UrlValues::read('int', '9007199254740992'), 'beyond what both sides hold exactly');
        $this->assertSame([true, 1.5], UrlValues::read('float', '1.5'));
        $this->assertSame([false, null], UrlValues::read('float', '0x10'), 'no hex');
        $this->assertSame([true, false], UrlValues::read('bool', ''));
        $this->assertSame([false, null], UrlValues::read('bool', 'yes'));
    }

    public function test_the_browser_reads_every_value_as_the_server_does(): void
    {
        $inputs = self::inputs();
        $browser = Node::run('js/test/support/url-values.mjs', $inputs);

        foreach ($inputs as $index => [$type, $raw]) {
            [$fits, $value] = UrlValues::read($type, $raw);
            [$browserFits, $browserValue] = $browser[$index];

            $this->assertSame($fits, $browserFits, "{$type} '{$raw}': accepted on one side only");

            if ($fits) {
                $this->assertEquals($value, $browserValue, "{$type} '{$raw}': read as another value");
            }
        }
    }
}
