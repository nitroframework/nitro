<li @class(['todo', 'done' => $todo['done'], 'editing' => $editing])>
    <input type="checkbox" @checked($todo['done']) fusion:change="$dispatch('todo-toggled', $todo['id'])">
    <span fusion:dblclick="toggleEdit">{{ $todo['title'] }}</span>
    <button class="remove" fusion:click="remove">&times;</button>
</li>
