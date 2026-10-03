<?php

namespace Nitro\Fusion\Compiler;

/**
 * What each template expression reads, as Viewi's subs: the properties the browser runtime
 * renders it again for when they change.
 *
 * Read from the compiled JavaScript, where the component is only ever reached as $this.name
 * ($this alone, dynamic names and variable variables do not compile): $this.count reads count,
 * $this.total() and $this.$computed("total") read what total() reads, $this.$errors reads the
 * validation messages ("$errors") and B.shared() the shared data ("$shared"). A method the
 * browser does not have reads anything ("*").
 * Reading too much only renders more; nothing that is read is missed.
 */
final class Subscriptions
{
    /** @var array<string, list<string>> */
    private array $methodKeys = [];

    /**
     * @param  array<string, string>  $methods  the compiled browser methods, by name
     * @param  list<string>  $properties  the component's public properties
     */
    public function __construct(private readonly array $methods, private readonly array $properties)
    {
    }

    /** @return list<string> */
    public function of(string $js): array
    {
        return $this->read($js, []);
    }

    /**
     * A template's expressions, as Viewi's collectSubscriptions with its nestedDependencies: what
     * each reads, and what its template variables come from reads ($item: its @foreach's data,
     * $message: the @error's field and "$errors").
     *
     * @param  list<string>  $expressions
     * @param  list<list<int|string>>  $sources  by expression: the expressions (by index) and keys its variables come from
     * @return list<list<string>>
     */
    public function ofTemplate(array $expressions, array $sources): array
    {
        $subs = [];

        foreach ($expressions as $index => $js) {
            $keys = array_fill_keys($this->of($js), true);

            /** A variable's source comes before it: its subs are complete already. */
            foreach ($sources[$index] ?? [] as $source) {
                foreach (is_string($source) ? [$source] : $subs[$source] ?? ['*'] as $key) {
                    $keys[$key] = true;
                }
            }

            $keys = array_keys($keys);
            sort($keys);
            $subs[$index] = $keys;
        }

        return $subs;
    }

    /**
     * @param  array<string, true>  $calling  methods whose reads are being collected (a recursive call adds nothing)
     * @return list<string>
     */
    private function read(string $js, array $calling): array
    {
        $keys = [];

        if (str_contains($js, '$this.$errors')) {
            $keys['$errors'] = true;
        }

        /** $this->shared(): Fusion::share()'s data, which the runtime marks "$shared" when it changes. */
        if (str_contains($js, 'B.shared(')) {
            $keys['$shared'] = true;
        }

        /** $this->flashed(): Fusion::flash()'s data, which the runtime marks "$flash" with each response. */
        if (str_contains($js, 'B.flashed(')) {
            $keys['$flash'] = true;
        }

        /** $this->hasMore(): where the page's scroll props are, which the runtime marks "$scroll" when it changes. */
        if (str_contains($js, '$this.$hasMore(')) {
            $keys['$scroll'] = true;
        }

        /** @deferred: the page's deferred properties, which the runtime marks "$deferred" once loaded. */
        if (str_contains($js, '$this.$deferring(')) {
            $keys['$deferred'] = true;
        }

        preg_match_all('/\$this\.(?:\$computed\("(\w+)"\)|([A-Za-z_]\w*)(\s*\()?)/', $js, $matches, PREG_SET_ORDER | PREG_UNMATCHED_AS_NULL);

        foreach ($matches as $match) {
            $method = $match[1] ?? ($match[3] !== null ? $match[2] : null);

            if ($method === null) {
                if (in_array($match[2], $this->properties, true)) {
                    $keys[$match[2]] = true;
                }

                continue;
            }

            foreach ($this->method($method, $calling) as $key) {
                $keys[$key] = true;
            }
        }

        $keys = array_keys($keys);
        sort($keys);

        return $keys;
    }

    /**
     * @param  array<string, true>  $calling
     * @return list<string>
     */
    private function method(string $name, array $calling): array
    {
        if (isset($this->methodKeys[$name])) {
            return $this->methodKeys[$name];
        }

        if (isset($calling[$name])) {
            return [];
        }

        if (! isset($this->methods[$name])) {
            return ['*'];
        }

        $keys = $this->read($this->methods[$name], $calling + [$name => true]);

        /** Complete only when nothing it calls was still being collected. */
        if ($calling === []) {
            $this->methodKeys[$name] = $keys;
        }

        return $keys;
    }
}
