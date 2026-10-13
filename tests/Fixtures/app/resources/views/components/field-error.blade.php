@props(['name'])
<p @class(['field', 'invalid' => $errors->has($name)])>
    @error($name)
        {{ $message }}
    @enderror
</p>
