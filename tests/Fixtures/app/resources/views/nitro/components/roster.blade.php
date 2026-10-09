<div class="roster">
    <button class="by-name" nitro:click="sortBy('name')">Name {{ $this->sortIcon('name') }}</button>
    <ul>
        @foreach ($this->rows['items'] as $person)
            <li>{{ $person['name'] }}</li>
        @endforeach
    </ul>
    <p class="where">{{ $this->rows['from'] }}-{{ $this->rows['to'] }} of {{ $this->rows['total'] }}, page {{ $this->rows['page'] }} of {{ $this->rows['pages'] }}</p>
    <button class="prev" nitro:click="previousPage">Previous</button>
    <button class="next" nitro:click="nextPage">Next</button>
    <button class="third" nitro:click="gotoPage(3)">3</button>
    <button class="only-a" nitro:click="$set('search', 'a')">With an a</button>
</div>
