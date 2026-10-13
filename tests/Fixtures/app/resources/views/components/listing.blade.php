@props(['rows' => []])
<ul class="listing">
    @foreach ($rows as $row)
        @if (isset($row['heading']))
            <li class="heading">{{ $row['heading'] }}</li>
        @else
            <li>{{ $row['label'] }}</li>
        @endif
    @endforeach
</ul>
