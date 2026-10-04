<?php

namespace Nitro\Fusion\Compiler;

use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use Nitro\Fusion\Attributes\Url;
use Nitro\Fusion\Upload;
use ReflectionNamedType;
use ReflectionProperty;
use ReflectionUnionType;

/**
 * A public property of a component: what it may hold and who may change it.
 *
 * Kinds:
 *   value       null, bool, int, float, string, array (checked against its declared type)
 *   model       an Eloquent model: the browser gets its attributes, the server re-reads it
 *   collection  an Eloquent collection of models, likewise
 *   upload      a file the browser uploaded (Nitro\Fusion\Upload): set from the browser with a
 *               signed reference only (Wire::decode checks it), so it is not locked
 */
final class PropertyMeta
{
    public const VALUE = 'value';

    public const MODEL = 'model';

    public const COLLECTION = 'collection';

    public const UPLOAD = 'upload';

    private const SCALARS = ['int', 'float', 'string', 'bool', 'array', 'mixed', 'null'];

    public function __construct(
        public readonly string $name,
        public readonly string $kind,
        /** @var list<string> declared types ([] when untyped) */
        public readonly array $types,
        public readonly bool $nullable,
        public readonly bool $locked,
        public readonly bool $hasDefault,
        public readonly mixed $default,
        /** Kept in the query string (#[Url]), or null. */
        public readonly ?Url $url = null,
        /** Kept in the browser's history entry (#[Remember]). */
        public readonly bool $remember = false,
        /** An array that takes files from the browser (#[Uploads]): a list of Uploads. */
        public readonly bool $uploads = false,
    ) {
    }

    public static function from(ReflectionProperty $property, bool $locked, ?string $file, ?Url $url = null, bool $remember = false, bool $uploads = false): self
    {
        $type = $property->getType();
        $types = [];
        $nullable = $type === null || $type->allowsNull();

        $declared = match (true) {
            $type === null => [],
            $type instanceof ReflectionNamedType => [$type],
            $type instanceof ReflectionUnionType => $type->getTypes(),
            default => throw new CompileException("The type of \${$property->getName()} is not supported by Fusion", $file, $property->getDeclaringClass()->getStartLine()),
        };

        foreach ($declared as $named) {
            if (! $named instanceof ReflectionNamedType) {
                throw new CompileException("The type of \${$property->getName()} is not supported by Fusion", $file, $property->getDeclaringClass()->getStartLine());
            }

            $types[] = $named->getName();
        }

        $kind = self::VALUE;

        foreach ($types as $name) {
            if (in_array($name, self::SCALARS, true)) {
                continue;
            }

            if (is_a($name, Upload::class, true) && count($types) === 1) {
                $kind = self::UPLOAD;
            } elseif (is_a($name, Model::class, true) && count($types) === 1) {
                $kind = self::MODEL;
            } elseif (is_a($name, EloquentCollection::class, true) && count($types) === 1) {
                $kind = self::COLLECTION;
            } else {
                throw new CompileException(
                    "\${$property->getName()} is a {$name}: component properties hold null, bool, int, float, string, array, an Eloquent model, an Eloquent collection or an Upload",
                    $file,
                    $property->getDeclaringClass()->getStartLine()
                );
            }
        }

        if ($url !== null && ($kind !== self::VALUE || $locked)) {
            throw new CompileException("\${$property->getName()} is #[Url]: the URL sets it, so it is a plain value the browser can change (not #[Locked], not a model)", $file, $property->getDeclaringClass()->getStartLine());
        }

        if ($uploads && ($kind !== self::VALUE || $types === [] || array_diff($types, ['array', 'null']) !== [] || $locked)) {
            throw new CompileException("\${$property->getName()} is #[Uploads]: it is an array the browser sends files to (public array \${$property->getName()} = []), not #[Locked]", $file, $property->getDeclaringClass()->getStartLine());
        }

        if ($remember && ($kind !== self::VALUE || $locked)) {
            throw new CompileException("\${$property->getName()} is #[Remember]: the browser brings its value back, so it is a plain value the browser can change (not #[Locked], not a model or an upload)", $file, $property->getDeclaringClass()->getStartLine());
        }

        return new self(
            $property->getName(),
            $kind,
            $types,
            $nullable,
            $locked || ! in_array($kind, [self::VALUE, self::UPLOAD], true),
            $property->hasDefaultValue(),
            $property->hasDefaultValue() ? $property->getDefaultValue() : null,
            $url,
            $remember,
            $uploads,
        );
    }

    /**
     * A value from the query string (a string, or an array of them), as the property's type:
     * [true, value], or [false, null] when it does not fit (the property keeps its value).
     *
     * @return array{0: bool, 1: mixed}
     */
    public function fromQuery(mixed $raw): array
    {
        if ($this->kind !== self::VALUE) {
            return [false, null];
        }

        if ($this->types === [] || in_array('mixed', $this->types, true)) {
            return [true, $raw];
        }

        foreach ($this->types as $type) {
            [$fits, $value] = match ($type) {
                'int' => [is_string($raw) && preg_match('/^-?\d+$/', $raw) === 1, (int) (is_string($raw) ? $raw : 0)],
                'float' => [is_string($raw) && is_numeric($raw), (float) (is_string($raw) ? $raw : 0)],
                'bool' => [is_string($raw) && in_array(strtolower($raw), ['1', '0', 'true', 'false', ''], true), in_array(strtolower(is_string($raw) ? $raw : ''), ['1', 'true'], true)],
                'string' => [is_string($raw), $raw],
                'array' => [is_array($raw), $raw],
                default => [false, null],
            };

            if ($fits) {
                return [true, $value];
            }
        }

        return [false, null];
    }

    /**
     * Whether a value from the browser fits the declared type. No coercion: a property is set
     * from the browser only with a value of its own type (ints are accepted for floats).
     */
    /** Whether an array from the browser has an Upload in it, at any depth. */
    public static function holdsUpload(mixed $value): bool
    {
        if ($value instanceof Upload) {
            return true;
        }

        if (is_array($value)) {
            foreach ($value as $item) {
                if (self::holdsUpload($item)) {
                    return true;
                }
            }
        }

        return false;
    }

    public function accepts(mixed $value): bool
    {
        /** An upload property takes an Upload (Wire::decode made it from a reference it checked) or null. */
        if ($this->kind === self::UPLOAD) {
            return $value instanceof Upload || ($value === null && $this->nullable);
        }

        if ($this->kind !== self::VALUE) {
            return false;
        }

        if ($value === null) {
            return $this->nullable;
        }

        /** #[Uploads]: a list of Uploads. Any other value holds none, at any depth (only these properties take files). */
        if ($this->uploads) {
            return is_array($value) && array_is_list($value) && array_filter($value, fn ($item) => ! $item instanceof Upload) === [];
        }

        if (self::holdsUpload($value)) {
            return false;
        }

        if ($this->types === [] || in_array('mixed', $this->types, true)) {
            return is_scalar($value) || is_array($value);
        }

        foreach ($this->types as $type) {
            $fits = match ($type) {
                'int' => is_int($value),
                'float' => is_float($value) || is_int($value),
                'string' => is_string($value),
                'bool' => is_bool($value),
                'array' => is_array($value),
                default => false,
            };

            if ($fits) {
                return true;
            }
        }

        return false;
    }

    /** A value accepted for a float property is stored as a float. */
    public function cast(mixed $value): mixed
    {
        $floatOnly = in_array('float', $this->types, true) && ! in_array('int', $this->types, true);

        return is_int($value) && $floatOnly ? (float) $value : $value;
    }
}
