<?php

/**
 * The Blade conformance cases (BladeConformanceTest): each a piece of a view, using the state every
 * case has (Conformance\Base: $name, $count, $price, $flag, $nothing, $items, $map, $empty, $html,
 * $tags), placed in every context and rendered by Blade and by the compiled view alike.
 *
 * 'components': the anonymous Blade components the cases use, as <x-conf::name>.
 * 'partials': the views they include, as conformance::partials.name.
 */
return [
    'components' => [
        'tag' => "@props(['tone' => 'plain'])\n<span {{ \$attributes->merge(['class' => 'tag tag-'.\$tone]) }}>{{ \$slot }}</span>",
        'bag' => "<span {{ \$attributes->only(['id']) }} data-has=\"{{ \$attributes->has('title') ? 'y' : 'n' }}\" data-get=\"{{ \$attributes->get('title', 'none') }}\">{{ \$slot }}</span>",
        'bag-except' => "<span {{ \$attributes->except(['id', 'title'])->class(['bag' => true, 'off' => false]) }}>x</span>",
        'card' => "@props(['title' => null])\n<section class=\"card\">\n    @isset(\$header)\n        <header>{{ \$header }}</header>\n    @endisset\n    @if (\$title)<h3>{{ \$title }}</h3>@endif\n    <div>{{ \$slot }}</div>\n    @if (\$slot->isEmpty())\n        <i>empty</i>\n    @endif\n</section>",
        'plain' => "<b>{{ \$label ?? 'none' }}</b>",
        'echo' => "@props(['value' => null])\n<i>{{ \$value }}</i>",
        'list' => "@props(['rows' => []])\n<ul>\n    @foreach (\$rows as \$row)\n        @if (isset(\$row['note']))\n            <li class=\"noted\">{{ \$row['note'] }}</li>\n        @else\n            <li>{{ \$row['label'] }}</li>\n        @endif\n    @endforeach\n</ul>",
        'field' => "@props(['name'])\n<p @class(['field', 'invalid' => \$errors->has(\$name)])>\n    @error(\$name)\n        <b>{{ \$message }}</b>\n    @enderror\n</p>",
        'model' => "@props(['name' => null])\n<label data-for=\"{{ \$name ?? \$attributes->get('nitro:model') }}\"><input {{ \$attributes }} /></label>",
        'relay' => "<div class=\"relay\"><x-conf::tag>{{ \$slot }}</x-conf::tag></div>",
        'slotted' => "<section class=\"slotted\">\n    @isset(\$header)<header {{ \$header->attributes->class(['head']) }}>{{ \$header }}</header>@endisset\n    @if (\$slot->isNotEmpty())<div>{{ \$slot }}</div>@endif\n</section>",
        'shorthand' => "@props(['name' => '', 'itemCount' => 0, 'disabled' => false])\n<i data-name=\"{{ \$name }}\" data-items=\"{{ \$itemCount }}\" @disabled(\$disabled)>{{ \$name }}</i>",
        'bag-more' => "<span {{ \$attributes->whereStartsWith('data-') }} data-first=\"{{ \$attributes->whereStartsWith('aria-')->first() }}\" data-any=\"{{ \$attributes->hasAny(['title', 'role']) ? 'y' : 'n' }}\" data-all=\"{{ \$attributes->has(['id', 'title']) ? 'y' : 'n' }}\">x</span>",
        'bag-prepends' => "<span {{ \$attributes->merge(['data-controller' => \$attributes->prepends('profile')]) }}>x</span>",
        'bag-filter' => "<span {{ \$attributes->filter(fn (\$value, \$key) => \$key !== 'title') }}>x</span>",
        'bools' => '<input {{ $attributes }} />',
        'aware-parent' => "@props(['color' => 'gray'])\n<ul class=\"{{ \$color }}\">{{ \$slot }}</ul>",
        'aware-child' => "@aware(['color' => 'gray'])\n<li class=\"{{ \$color }}\">{{ \$slot }}</li>",
        'accordion/index' => '<div class="accordion">{{ $slot }}</div>',
        'accordion/item' => '<p class="item">{{ $slot }}</p>',
        'spaced' => "\n\n@props(['label' => 'x'])\n\n<b>{{ \$label }}</b>\n\n",
        'spaced-plain' => "\n  <b>plain</b>  \n",
        'styled' => "<span {{ \$attributes->merge(['style' => 'color: red']) }}>x</span>",
        'classy' => "@props(['list' => []])\n<span {{ \$attributes->class(\$list) }}>x</span>",
        'depth' => "<b>@foreach ([1] as \$x){{ \$loop->depth }}@endforeach</b>",
        'maybe' => "<b>{{ \$slot ?? 'none' }}</b>",
        'relay-card' => '<x-conf::card>{{ $slot }}</x-conf::card>',
        'pidx' => "<b>@foreach ([1] as \$x){{ \$loop->parent->index }}@endforeach</b>",
        'cell' => '<td>c</td>',
    ],

    'partials' => [
        'label' => '<span class="label">{{ $label }}</span>',
        'row' => '<em>{{ $row["label"] }}{{ $suffix ?? "" }}</em>',
        'spaced' => "{{-- a note --}}\n\n   <em>{{ \$label }}</em>  \n",
        'item' => '<li>{{ $item["label"] }} ({{ $key }})</li>',
        'none' => '<li>no items</li>',
        'tag' => '<li>{{ $tag }} ({{ $key }})</li>',
        'looped' => "@foreach (\$tags as \$t)\n    <em>{{ \$t }}</em>\n@endforeach\n",
        'deep' => "<li>@foreach ([1] as \$x){{ \$loop->depth }}@endforeach</li>",
        'lead-if' => "@if (! \$flag)<i>z</i>@endif   <em>x</em>",
        'lead-echo' => "@if (! \$flag)<i>z</i>@endif{{ ' ' }}<em>x</em>",
        'cls' => "{{ \$flag ? 'on' : 'off' }}",
        'deep2' => "<li>@foreach ([1] as \$x){{ \$loop->depth }}{{ \$loop->parent->index }}@endforeach</li>",
        'rowloop' => "@foreach (\$tags as \$row){{ \$row }}@endforeach",
        'pidx' => "@foreach ([1] as \$x){{ \$loop->parent->index }}@endforeach",
        'lead-entity' => "@if (\$flag)@endif&#32;<em>x</em>",
    ],

    /**
     * The cases Nitro does not compile, and what the build says: Blade that needs the server as it
     * renders (the request, the session, layouts, the container), or not supported yet. Each must
     * fail the build with its message, at the view it is in; never compile to something else.
     */
    'refused' => [
        'auth and guest' => '@auth cannot render in the browser',
        'env and production' => '@env cannot render in the browser',
        'session directive' => '@session cannot render in the browser',
        'hasSection' => '@hasSection cannot render in the browser',
        'section and yield' => '@section cannot render in the browser',
        'push and stack' => '@push cannot render in the browser',
        'fragment' => '@fragment cannot render in the browser',
        'php block' => '@php cannot render in the browser',
        'php inline' => '@php cannot render in the browser',
        'inject' => '@inject cannot render in the browser',
        'dynamic component' => '<x-dynamic-component> names its component at run time',
        /** Not supported yet: each could be (Nitro's TASKS.md, conformance 3). */
        'js directive' => '@js cannot render in the browser',
        'Js::from' => 'Js::from() is not a browser method',
        'once' => '@once cannot render in the browser',
        /** [message, the file it points at]: what the build refuses is written in the component, not the case. */
        'component aware' => ['@aware reads a parent component at run time', 'aware-child.blade.php'],
        'component bag filters' => ['$attributes->whereStartsWith() is not supported in the browser', 'bag-more.blade.php'],
        'component bag prepends' => ['$attributes is used in a way the browser cannot render', 'bag-prepends.blade.php'],
        'component bag filter' => ['$attributes->filter() is not supported in the browser', 'bag-filter.blade.php'],
        /** Markup the HTML parser rebuilds, or browsers read differently. */
        'review block element in a paragraph through a component' => 'the browser ends the <p> before a <section>',
        'review2 row in a row' => '<tr> inside an open <tr>',
        'review2 cell component in a cell' => '<td> (in <x-conf::cell>) inside an open <td>',
        'review2 markup in an option' => '<b> inside <option>',
    ],

    'cases' => [
        /** Echoes */
        'echo a string' => '<p>{{ $name }}</p>',
        'echo scalars' => '<p>{{ $count }} {{ $price }} {{ $flag }} {{ $nothing }}</p>',
        'echo raw html' => '<p>{!! $html !!}</p>',
        'echo expressions' => "<p>{{ \$count + 1 }} {{ \$name.'!' }} {{ \$flag ? 'y' : 'n' }} {{ \$nothing ?? 'none' }} {{ -\$price }}</p>",
        'echo in attributes' => '<p title="{{ $name }}" data-count="{{ $count }}" class="a {{ $flag ? \'on\' : \'off\' }}">x</p>',
        'echo escaped by @' => '<p>@{{ $name }} and {{ $count }}</p>',
        'php functions' => "<p>{{ strtoupper(\$name) }} {{ count(\$items) }} {{ implode(',', \$tags) }} {{ number_format(\$price, 2) }} {{ str_repeat('-', \$count) }}</p>",
        'Str helpers' => "<p>{{ Str::limit(\$name, 3) }} {{ Str::slug(\$name) }} {{ Str::upper(\$name) }}</p>",
        'array reads' => "<p>{{ \$map['a'] ?? '-' }} {{ \$items[0]['label'] }} {{ \$items[1]['note'] ?? 'no note' }}</p>",

        /** Conditions */
        'if, elseif, else' => '<p>@if ($flag) <b>on</b> @elseif ($count > 2) <i>many</i> @else <u>none</u> @endif</p>',
        'unless' => '<p>@unless ($flag) <b>off</b> @endunless|</p>',
        'isset and empty directives' => '<p>@isset($nothing) <b>set</b> @endisset @empty($empty) <i>empty</i> @endempty</p>',
        'isset of a key' => "<p>@if (isset(\$map['a'])) a @endif @if (isset(\$map['zz'])) zz @endif</p>",
        'if inside an element and around one' => '<div>@if ($flag)<span>in</span>@endif</div>@if ($count)<p>{{ $count }}</p>@endif',

        /** Loops */
        'foreach' => "<ul>@foreach (\$items as \$item)<li>{{ \$item['label'] }}</li>@endforeach</ul>",
        'foreach with keys' => '<p>@foreach ($map as $key => $value){{ $key }}={{ $value }};@endforeach</p>',
        'loop properties' => "<ol>@foreach (\$items as \$item)<li>{{ \$loop->index }}/{{ \$loop->iteration }}/{{ \$loop->count }}/{{ \$loop->remaining }} {{ \$loop->first ? 'F' : '' }}{{ \$loop->last ? 'L' : '' }}{{ \$loop->even ? 'E' : 'O' }} d{{ \$loop->depth }}</li>@endforeach</ol>",
        'loop parent' => '<p>@foreach ($items as $item)@foreach ($tags as $tag){{ $loop->parent->index }}.{{ $loop->index }} @endforeach @endforeach</p>',
        'isset of a loop variable' => "<ul>@foreach (\$items as \$item)@if (isset(\$item['note']))<li>{{ \$item['note'] }}</li>@else<li>-</li>@endif @endforeach</ul>",
        'coalesce of a loop variable' => "<ul>@foreach (\$items as \$item)<li>{{ \$item['note'] ?? 'no note' }}</li>@endforeach</ul>",
        'nested loops' => "<ul>@foreach (\$items as \$item)<li>{{ \$item['label'] }}: @foreach (\$tags as \$tag){{ \$tag }}{{ \$item['id'] }} @endforeach</li>@endforeach</ul>",
        'forelse' => '<p>@forelse ($empty as $e) {{ $e }} @empty <i>nothing</i> @endforelse</p>',
        'forelse with items' => "<p>@forelse (\$tags as \$tag) {{ \$tag }} @empty <i>nothing</i> @endforelse</p>",
        'for' => '<p>@for ($i = 0; $i < $count; $i++){{ $i }} @endfor</p>',
        'continue and break' => "<p>@foreach (\$items as \$item)@if (\$loop->first) @continue @endif{{ \$item['id'] }} @break(\$loop->index === 1)@endforeach|</p>",
        'switch' => '<p>@switch($count) @case(3) three @break @case(0) zero @break @default other @endswitch</p>',

        /** Attribute directives */
        'class directive' => "<p @class(['a', 'b' => \$flag, 'c' => ! \$flag])>x</p>",
        'style directive' => "<p @style(['color: red' => \$flag, 'margin: 0'])>x</p>",
        'checked, disabled, readonly, required, selected' => "<form><input type=\"checkbox\" @checked(\$flag)><input @disabled(! \$flag) @readonly(\$flag) @required(\$flag)><select><option @selected(\$count === 3)>3</option></select></form>",

        /** Errors and forms */
        'error directive' => "<p>@error('name') <b>{{ \$message }}</b> @enderror @error('count') <i>{{ \$message }}</i> @enderror|</p>",
        'error of a path' => "<p>@error('items.1.label') {{ \$message }} @enderror|</p>",
        'errors bag' => "<p>{{ \$errors->has('name') ? 'bad' : 'ok' }} {{ \$errors->first('count') }} {{ \$errors->any() ? 'any' : '' }} {{ count(\$errors) }}</p>",
        'csrf and method' => "<form>@csrf @method('PUT')</form>",
        'json' => '<p>@json($map) @json($tags)</p>',
        'use' => "@use('Nitro\\Tests\\Fixtures\\Browser\\Money')\n<p>{{ Money::format(\$count) }}</p>",

        /** Includes */
        'include with data' => "<p>@include('conformance::partials.label', ['label' => \$name])</p>",
        'include when, if, unless' => "<p>@includeWhen(\$flag, 'conformance::partials.label', ['label' => 'when']) @includeIf('conformance::partials.missing') @includeUnless(\$flag, 'conformance::partials.label', ['label' => 'unless'])|</p>",
        'include with whitespace around it' => "<p>[@include('conformance::partials.spaced', ['label' => \$name])]</p>",
        'include in a loop' => "<p>@foreach (\$items as \$item)@include('conformance::partials.row', ['row' => \$item, 'suffix' => '.'])@endforeach</p>",

        /** Blade components */
        'component attributes merged' => '<p><x-conf::tag :tone="$name" class="x" data-n="{{ $count }}">{{ $name }}</x-conf::tag><x-conf::tag>plain</x-conf::tag></p>',
        'component attribute bag methods' => '<p><x-conf::bag id="b1" title="{{ $name }}" data-x="1">{{ $count }}</x-conf::bag><x-conf::bag>none</x-conf::bag><x-conf::bag-except id="b2" title="t" data-y="{{ $flag ? 1 : 0 }}" /></p>',
        'component named slot and empty slot' => '<div><x-conf::card title="T"><x-slot:header>H {{ $name }}</x-slot>{{ $count }}</x-conf::card><x-conf::card /></div>',
        'component attribute as variable' => '<p><x-conf::plain label="L {{ $count }}" /><x-conf::plain /></p>',
        'component prop of any value' => '<p><x-conf::echo :value="$nothing" /><x-conf::echo :value="$flag" /><x-conf::echo :value="$price" /><x-conf::echo value="literal" /></p>',
        'component loop testing its rows with isset' => '<div><x-conf::list :rows="$items" /></div>',
        'component class directive on its tag' => "<p><x-conf::tag @class(['on' => \$flag, 'off' => ! \$flag])>t</x-conf::tag></p>",
        'component given a nitro attribute' => '<p><x-conf::tag nitro:click="noop">t</x-conf::tag></p>',
        'component reading a nitro attribute' => '<div><x-conf::model nitro:model="name" placeholder="Name" /><x-conf::model name="count" /></div>',
        'component field named by its caller' => '<div><x-conf::field name="name" /><x-conf::field name="count" /></div>',
        'component passing its slot on' => '<div><x-conf::relay>{{ $name }} <b>{{ $count }}</b></x-conf::relay></div>',
        'component files with whitespace around them' => '<p>[<x-conf::spaced :label="$name" />][<x-conf::spaced-plain />]</p>',
        /** More of Blade: displaying */
        'verbatim' => '<p>@verbatim {{ $name }} @if @endverbatim {{ $count }}</p>',
        'comments' => '<p>a{{-- hidden {{ $name }} @if ($flag) --}}b</p>',
        'echo of entities' => "<p>{{ '&amp; &lt;b&gt;' }} {{ \$html }}</p>",
        'json with flags' => '<p>@json($map, JSON_PRETTY_PRINT) @json($name, JSON_HEX_TAG) @json($tags, JSON_FORCE_OBJECT) @json($empty, JSON_FORCE_OBJECT | JSON_PRETTY_PRINT) @json($items, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG)</p>',
        'js directive' => '<p data-v="@js($map)" data-n="@js($name)">x</p>',
        'Js::from' => '<p data-v="{{ Js::from($tags) }}">x</p>',

        /** More of Blade: conditions */
        'unless with else' => '<p>@unless ($flag) off @else on @endunless</p>',
        'isset of several' => '<p>@isset($name, $nothing) both @endisset @isset($name, $count) both2 @endisset</p>',
        'empty of falsy values' => "<p>@empty(\$count) zero @endempty @empty(\$tags) none @endempty @empty('0') str0 @endempty</p>",
        'switch falling through and strings' => "<p>@switch(\$name)\n@case('Grace')\n@case('Ada') ag @break\n@default other\n@endswitch @switch(\$count)\n@case(3) three\n@case(4) four @break\n@endswitch</p>",
        'auth and guest' => '<p>@auth in @endauth @guest out @endguest</p>',
        'env and production' => "<p>@env('testing') testing @endenv @production prod @endproduction</p>",
        'session directive' => "<p>@session('status') {{ \$value }} @endsession|</p>",
        'hasSection' => "<p>@hasSection('nav') nav @endif @sectionMissing('nav') no nav @endif</p>",

        /** More of Blade: loops */
        'loop odd and depth' => "<p>@foreach (\$tags as \$tag){{ \$loop->odd ? 'o' : 'e' }}{{ \$loop->depth }}@foreach (\$tags as \$inner){{ \$loop->depth }}{{ \$loop->parent->odd ? 'O' : 'E' }}@endforeach;@endforeach</p>",
        'break and continue levels' => "<p>@foreach (\$items as \$item)@foreach (\$tags as \$tag)@continue(\$tag === 'y'){{ \$tag }}@break(2)@endforeach @endforeach|</p>",
        'forelse with loop' => "<p>@forelse (\$tags as \$tag){{ \$loop->iteration }}/{{ \$loop->count }} @empty none @endforelse</p>",
        'foreach over numeric string keys' => "<p>@foreach (['10' => 'a', '2' => 'b', 'x' => 'c'] as \$key => \$value){{ \$key }}{{ \$value }}@endforeach</p>",
        'while' => '<p>@while (false) never @endwhile|</p>',
        'once' => "<p>@foreach (\$tags as \$tag)@once <b>first</b> @endonce{{ \$tag }}@endforeach</p>",

        /** More of Blade: attributes, forms */
        'class with duplicates and numbers' => "<p @class(['a', 'a', 'b' => 1, 'c' => 0, 'd' => \$nothing, 'e' => 'x'])>x</p>",
        'style with semicolons' => "<p @style(['color: red;', 'margin: 0' => \$flag, 'padding: 1px;' => ! \$flag])>x</p>",
        'attribute directives with values' => "<p><input @checked(\$count) @disabled(\$nothing) @required('0') @readonly(\$tags)></p>",
        'error of a bag' => "<p>@error('name', 'default') {{ \$message }} @enderror|</p>",
        'php block' => "<p>@php \$doubled = \$count * 2; @endphp{{ \$doubled }}</p>",
        'php inline' => '<p>@php($tripled = $count * 3){{ $tripled }}</p>',
        'inject' => "<p>@inject('config', 'config'){{ \$config->get('app.name') }}</p>",
        /** @lang and @choice print as they are (raw): their replacements here are text, as {!! !!}'s must be to match fragment by fragment. */
        'lang and choice' => "<p>@lang('messages.welcome', ['name' => 'ada']) @choice('messages.apples', \$count) @choice('messages.apples', \$items) {{ __('messages.welcome', ['name' => \$name]) }} {{ trans_choice('messages.apples', \$count, ['count' => \$count + 1]) }}</p>",

        /** More of Blade: includes */
        'includeFirst' => "<p>@includeFirst(['conformance::partials.missing', 'conformance::partials.label'], ['label' => \$name])</p>",
        'each' => "<ul>@each('conformance::partials.item', \$items, 'item', 'conformance::partials.none')@each('conformance::partials.tag', \$empty, 'tag', 'raw|<li>none</li>')</ul>",

        /** More of Blade: layouts and stacks in a component's view */
        'push and stack' => "<div>@push('scripts')<script>1</script>@endpush @stack('scripts')</div>",
        'section and yield' => "<div>@section('side')side @show @yield('main', 'default main')</div>",
        'fragment' => "<div>@fragment('part')<b>{{ \$count }}</b>@endfragment</div>",

        /** More of Blade: components */
        /** The default slot's content before the named slot: after it, Laravel makes a named slot with attributes a string (plain Blade fails too). */
        'component slot with attributes' => '<div><x-conf::slotted>body<x-slot:header class="big" data-h="{{ $count }}">Head {{ $count }}</x-slot></x-conf::slotted><x-conf::slotted><x-slot name="header">named</x-slot></x-conf::slotted><x-conf::slotted><x-slot:header>plain</x-slot></x-conf::slotted></div>',
        'component shorthand attributes' => '<p><x-conf::shorthand :$name :item-count="$count" disabled /><x-conf::shorthand :disabled="false" /></p>',
        'component bag filters' => '<p><x-conf::bag-more data-a="1" data-b="{{ $count }}" aria-label="L" id="i" title="t" /><x-conf::bag-more /></p>',
        'component bag prepends' => '<p><x-conf::bag-prepends data-controller="extra" /><x-conf::bag-prepends /></p>',
        'component bag filter' => '<p><x-conf::bag-filter title="t" data-x="1" /></p>',
        'component boolean attributes' => '<p><x-conf::bools required :disabled="$flag" :readonly="false" :data-null="$nothing" :data-n="$count" /></p>',
        'component aware' => '<div><x-conf::aware-parent color="red"><x-conf::aware-child>a</x-conf::aware-child></x-conf::aware-parent><x-conf::aware-parent><x-conf::aware-child>b</x-conf::aware-child></x-conf::aware-parent></div>',
        'component index view' => '<div><x-conf::accordion><x-conf::accordion.item>{{ $name }}</x-conf::accordion.item></x-conf::accordion></div>',
        'dynamic component' => '<div><x-dynamic-component component="conf::tag">t</x-dynamic-component></div>',
        'component with a use alias in an attribute' => "@use('Nitro\\Tests\\Fixtures\\Browser\\Money')\n<p><x-conf::echo :value=\"Money::format(\$count)\" /></p>",

        /** Review 2026-10-13: each expected to differ (or not compile) until fixed. */
        'review include starting with a directive' => "<p>[@include('conformance::partials.looped')]</p>",
        'review slot empty when it renders nothing' => "<div><x-conf::card>@if (\$flag){{ \$count }}@endif</x-conf::card></div>",
        'review merged style' => '<p><x-conf::styled style="margin: 0" /></p>',
        'review class of a variable' => "<p><x-conf::classy :list=\"['a', 'b' => \$flag]\" /></p>",
        'review json of a literal array' => "<p>@json(['a' => \$count, 'b' => 1])</p>",
        'review raw echo in an attribute and a textarea' => "<div title=\"{!! 'a &amp; b' !!}\"><textarea>{!! 'c &amp; d' !!}</textarea></div>",
        'review loop variable after the loop' => '<p>@foreach ($tags as $name){{ $name }}@endforeach|{{ $name }}</p>',
        'review block element in a paragraph through a component' => '<p><x-conf::card title="T">x</x-conf::card></p>',
        'review verbatim after a word' => '<p>x@verbatim{{ $name }}@endverbatim</p>',
        'review escaped directive with arguments' => '<p>@@if ($flag) x</p>',
        'review loop depth in a component in a loop' => '<p>@foreach ($tags as $t)<x-conf::depth />@endforeach</p>',
        'review loop depth in an each view' => "<ul>@each('conformance::partials.deep', \$tags, 'tag')</ul>",
        'review slot ending in a block' => '<div><x-conf::box>a @if ($flag) <b>b</b> @endif</x-conf::box></div>',
        'review slot of an echo with spaces' => "<div><x-conf::box>{{ \$flag ? ' on ' : ' off ' }}</x-conf::box></div>",
        'review slot read with a default' => '<p><x-conf::maybe>{{ $count }}</x-conf::maybe></p>',
        'review raw echo escaped by @' => '<p>@{!! $count !!}</p>',
        'review error of a field held in a variable' => "<p>@foreach (['name', 'count'] as \$f)@error(\$f){{ \$message }}@enderror {{ \$errors->first(\$f) }};@endforeach|</p>",

        /** Second review 2026-10-13. */
        'review2 include after a loop over its variable' => "<p>@foreach (\$tags as \$label){{ \$label }}@endforeach|@include('conformance::partials.label', ['label' => 'L'])</p>",
        'review2 two loops over one variable' => '<p>@foreach ($tags as $t){{ $t }}@endforeach|@foreach ($tags as $t){{ $t }}@endforeach</p>',
        'review2 include whose first text a block reveals' => "<p>[@include('conformance::partials.lead-if')]</p>",
        'review2 include starting with an encoded space' => "<p>[@include('conformance::partials.lead-entity')]</p>",
        'review2 slot of an encoded space' => '<div><x-conf::card>&#32;</x-conf::card></div>',
        'review2 pre starting with an echoed newline' => "<pre>{{ \$flag ? \"\\nA\" : 'B' }}</pre>",
        'review2 pre whose newline follows an echo' => "<pre>{{ \$nothing }}\nA</pre>",
        'review2 col in a table' => '<table><col><tbody><tr><td>x</td></tr></tbody></table>',
        'review2 markup in an option' => '<select><option><b>x</b></option></select>',
        'review2 include whose first echo a block reveals' => "<p>[@include('conformance::partials.lead-echo')]</p>",
        'review2 include in an attribute' => "<p class=\"@include('conformance::partials.cls')\">x</p>",
        'review2 loop parent in an each view in a loop' => "<div>@foreach (\$tags as \$t)<ul>@each('conformance::partials.deep2', \$tags, 'tag')</ul>@endforeach</div>",
        'review2 slot emptiness through a slot passed on' => "<div><x-conf::relay-card>@if (\$flag){{ \$count }}@endif</x-conf::relay-card></div>",
        'review2 quote in an attribute the view does not print' => "<p><x-conf::plain label='say \"hi\"' /></p>",
        'review2 slot of a comment' => '<div><x-conf::card><!-- c --></x-conf::card></div>',
        'review2 loop variable read in an else branch' => '<p>@if ($flag) @foreach ($tags as $name){{ $name }}@endforeach @else {{ $name }} @endif</p>',
        'review2 row in a row' => '<table><tbody><tr><tr><td>x</td></tr></tr></tbody></table>',
        'review2 cell component in a cell' => '<table><tbody><tr><td><x-conf::cell /></td></tr></tbody></table>',
        'review2 loop parent of a loop over a literal' => '<p>@foreach ($tags as $t)@foreach ([1] as $x){{ $loop->parent->index }}@endforeach @endforeach</p>',
        'review2 loop parent in a component in a loop' => '<p>@foreach ($tags as $t)<x-conf::pidx />@endforeach</p>',
        'review2 loop parent in an include in a loop' => "<p>@foreach (\$tags as \$t)@include('conformance::partials.pidx')@endforeach</p>",
        'review2 error of a nested field held in a variable' => "<p>@foreach (['items.1.label', 'name'] as \$f){{ \$errors->first(\$f) }};@endforeach|</p>",
        'review2 include looping over the variable around it' => "<p>@foreach (\$items as \$row)@include('conformance::partials.rowloop')/{{ \$row['label'] }}/{{ \$loop->index }};@endforeach</p>",
    ],
];
