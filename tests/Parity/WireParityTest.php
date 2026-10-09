<?php

namespace Nitro\Tests\Parity;

use Nitro\Support\Wire;
use Nitro\Tests\Fixtures\Browser\OrderStatus;
use Nitro\Tests\Support\Node;
use PHPUnit\Framework\TestCase;

/**
 * The value format between PHP and the browser is written twice, by design (Support/Wire.php,
 * js/src/php/wire.js): each side must read and write it. A value the server sends, held by the
 * browser and sent back, is the value it was: these are the cases where that is hard.
 */
final class WireParityTest extends TestCase
{
    /** @return array<string, mixed> */
    private static function values(): array
    {
        return [
            'null' => null,
            'true' => true,
            'false' => false,
            'zero' => 0,
            'a negative int' => -42,
            'the largest int both hold' => 9007199254740991,
            'a float' => 1.5,
            'a float with a zero fraction' => 2.0,
            'negative zero' => -0.0,
            'a small float' => 1.0E-7,
            'a string' => 'plain',
            'unicode' => "naïve café 中文 🎉",
            'a numeric string' => '007',
            'an empty string' => '',
            'a list' => [1, 2, 3],
            'an empty array' => [],
            'int keys out of order' => [3 => 'c', 1 => 'a', 2 => 'b'],
            'a list from 1' => [1 => 'a', 2 => 'b'],
            'string and int keys' => ['a' => 1, 5 => 'five', 'b' => [2]],
            'a negative key' => [-1 => 'x', 0 => 'y'],
            'nested maps' => ['outer' => ['inner' => [10 => ['deep' => true]]]],
            'a list of maps' => [['id' => 1, 'tags' => []], ['id' => 2, 'tags' => ['x']]],
            'a backed enum' => OrderStatus::Paid,
        ];
    }

    public function test_a_value_comes_back_as_it_went(): void
    {
        $values = self::values();
        $sent = array_map(fn (mixed $value) => Wire::encode($value), $values);
        $back = Node::run('js/test/support/wire.mjs', array_values(json_decode(json_encode(array_values($sent), JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR), true)));

        /**
         * JavaScript has one number type: a whole float is an int once in the browser (documented
         * in Limitations; a float property or parameter casts it back on the server).
         */
        $documented = ['a float with a zero fraction' => 2, 'negative zero' => 0];

        foreach (array_keys($values) as $index => $name) {
            $expected = match (true) {
                array_key_exists($name, $documented) => $documented[$name],
                $values[$name] instanceof \BackedEnum => $values[$name]->value,
                default => $values[$name],
            };
            $this->assertSame($expected, Wire::decode($back[$index]), "{$name}: changed on its way through the browser");
        }
    }
}
