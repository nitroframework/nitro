<li @class(['todo', 'done' => $todo['done'], 'editing' => $editing])>
    <input type="checkbox" @checked($todo['done']) nitro:change="$dispatch('todo-toggled', $todo['id'])">
    <span nitro:dblclick="toggleEdit">{{ $todo['title'] }}</span>
    <button class="remove" nitro:click="remove">&times;</button>
</li>
