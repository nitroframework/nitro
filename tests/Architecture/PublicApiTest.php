<?php

namespace Nitro\Fusion\Tests\Architecture;

use Nitro\Fusion\Component;
use Nitro\Fusion\Fusion;
use Nitro\Fusion\Store;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionIntersectionType;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionType;
use ReflectionUnionType;

/**
 * Fusion's public API is what an app's code names: the root namespace (Component, Store, Upload,
 * Once, Merge, Head, PendingDispatch...) and Attributes, Browser, Concerns, Facades and Testing. Every
 * other folder is internal, free to move. A class an app receives or passes, through what it
 * calls on a component, a store or the Fusion facade, must be public, or moving an internal
 * class would break apps.
 */
final class PublicApiTest extends TestCase
{
    /** Where public classes live, beside the root namespace. */
    public const PUBLIC_FOLDERS = ['Attributes', 'Browser', 'Concerns', 'Facades', 'Testing'];

    /** What an app calls: a component's and a store's methods ($this->...), the facade's. */
    private const SURFACES = [Component::class, Store::class, Fusion::class];

    public function test_what_an_app_receives_or_passes_is_public(): void
    {
        $exposed = [];

        foreach (self::SURFACES as $surface) {
            foreach ((new ReflectionClass($surface))->getMethods() as $method) {
                /** An app never constructs these, and a method marked @internal is not its to call. */
                if ($method->isPrivate() || $method->isConstructor() || str_contains((string) $method->getDocComment(), '@internal')
                    || ($method->isProtected() && $surface === Fusion::class)) {
                    continue;
                }

                foreach ($this->classesOf($method) as $class) {
                    if (! $this->isPublic($class)) {
                        $exposed[(new ReflectionClass($surface))->getShortName().'::'.$method->getName()][] = $class;
                    }
                }
            }
        }

        $exposed = array_map(fn (array $uses) => implode(', ', array_unique($uses)), $exposed);
        $known = Baseline::of('public api', 'exposed');

        $this->assertSame([], array_diff_key($exposed, $known), 'An internal class reached by an app\'s code: make the method internal, or the class public');
        $this->assertSame([], array_keys(array_diff_key($known, $exposed)), 'Fixed: take it out of known-breaks.php');
    }

    /** @return list<class-string> Fusion's classes the method takes or gives */
    private function classesOf(ReflectionMethod $method): array
    {
        $types = [$method->getReturnType()];

        foreach ($method->getParameters() as $parameter) {
            $types[] = $parameter->getType();
        }

        $classes = [];

        foreach ($types as $type) {
            foreach ($this->named($type) as $name) {
                if (str_starts_with($name, 'Nitro\\Fusion\\')) {
                    $classes[] = $name;
                }
            }
        }

        return $classes;
    }

    /** @return list<string> */
    private function named(?ReflectionType $type): array
    {
        return match (true) {
            $type instanceof ReflectionNamedType => $type->isBuiltin() ? [] : [$type->getName()],
            $type instanceof ReflectionUnionType, $type instanceof ReflectionIntersectionType => array_merge(...array_map(fn ($inner) => $this->named($inner), $type->getTypes())),
            default => [],
        };
    }

    private function isPublic(string $class): bool
    {
        $relative = explode('\\', substr($class, strlen('Nitro\\Fusion\\')));

        return count($relative) === 1 || in_array($relative[0], self::PUBLIC_FOLDERS, true);
    }
}
