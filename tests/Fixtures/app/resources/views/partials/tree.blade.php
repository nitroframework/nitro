<ul>
@foreach ($nodes as $node)
    <li>{{ $node['label'] }}@include('partials.tree', ['nodes' => $node['children']])@include('partials.tree', ['nodes' => []])</li>
@endforeach
</ul>
