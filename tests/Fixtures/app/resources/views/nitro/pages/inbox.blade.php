<div class="inbox">
    <h1 class="heading">Inbox</h1>
    @deferred('messages')
        <ul class="messages">
            @foreach ($messages as $message)
                <li>{{ $message }}</li>
            @endforeach
        </ul>
    @placeholder
        <p class="loading">Loading messages</p>
    @enddeferred
    @deferred('unread')
        <p class="unread">{{ $unread }} unread</p>
    @enddeferred
    <input class="draft" nitro:model="draft">
    <button class="send" nitro:click="send">{{ $sent }}</button>
    <a class="to-greeting" href="/greeting" nitro:navigate>Greeting</a>
</div>
