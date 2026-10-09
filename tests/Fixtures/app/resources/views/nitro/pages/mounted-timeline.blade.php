<div class="timeline">
    <ul class="posts">
        @foreach ($posts as $post)
            <li>{{ $post['title'] }}</li>
        @endforeach
    </ul>
    @if ($this->hasMore('posts'))
        <button class="more" nitro:click="$loadMore('posts')">More</button>
    @endif
    <p class="tags">{{ implode(',', array_column($tags, 'name')) }}</p>
    <p class="settings">{{ $settings['theme']['color'] ?? '' }}/{{ $settings['theme']['size'] ?? '' }}</p>
    <button class="reload-tags" nitro:click="$reload('tags')">Tags</button>
    <button class="reload-settings" nitro:click="$reload('settings')">Settings</button>
    <button class="touch" nitro:click="touch">{{ $touched }}</button>
</div>
