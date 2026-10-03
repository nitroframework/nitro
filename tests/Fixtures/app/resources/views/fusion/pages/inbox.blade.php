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
    <input class="draft" fusion:model="draft">
    <button class="send" fusion:click="send">{{ $sent }}</button>
    <a class="to-greeting" href="/greeting" fusion:navigate>Greeting</a>
</div>
