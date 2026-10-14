<?php

/**
 * Nitro's own conformance cases, beside the reviewer's (cases.php), in the same form: what a fix
 * of Nitro's covers beyond them. BladeConformanceTest renders both.
 */
return [
    'components' => [],
    'partials' => [],
    'cases' => [
        'left: a loop\'s item after it' => '<p>@foreach ($tags as $t){{ $t }}@endforeach|{{ $t }}</p>',
        'left: a loop\'s key and item after it' => '<p>@foreach ($map as $k => $v)@endforeach{{ $k }}={{ $v }}</p>',
        'left: a loop that ran no iteration keeps the value' => '<p>@foreach ($empty as $name)@endforeach{{ $name }}</p>',
        'left: the item a @break left' => "<p>@foreach (\$items as \$it)@break(\$it['id'] === 2)@endforeach{{ \$it['label'] }}</p>",
        'left: @for\'s variable after it' => '<p>@for ($i = 0; $i < $count; $i++)-@endfor{{ $i }}</p>',
        'left: an inner loop\'s, of the outer loop\'s name' => '<p>@foreach ($items as $x)@foreach ($tags as $x)@endforeach{{ $x }};@endforeach</p>',
        'left: @forelse\'s item, after it' => "<p>@forelse (\$tags as \$t)a @empty b @endforelse{{ \$t ?? 'none' }}</p>",
        'left: read in a block after the loop' => '<p>@foreach ($tags as $t)@endforeach @if ($flag)[{{ $t }}]@endif</p>',
        'implied: a tbody and rows' => "<table>\n<tr><td>{{ \$name }}</td></tr>\n<tr><td>b</td></tr>\n</table>",
        'implied: a row of cells' => '<table><tbody><td>a</td><td>{{ $count }}</td></tbody></table>',
        'implied: a colgroup, then rows' => '<table><col><col><tr><td>x</td></tr></table>',
        'implied: rows of a loop after a row' => "<table><tr><td>h</td></tr>@foreach (\$tags as \$t)<tr><td>{{ \$t }}</td></tr>@endforeach</table>",
    ],
    'refused' => [],
];
