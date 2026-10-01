<div class="timeline">
    <ul class="posts">
        @foreach ($posts['data'] as $post)
            <li>{{ $post['title'] }}</li>
        @endforeach
    </ul>
    @if ($posts['scroll']['nextPage'] !== null)
        <button class="more" fusion:click="$loadMore('posts')">More</button>
    @endif
    <p class="tags">{{ implode(',', array_column($tags, 'name')) }}</p>
    <p class="settings">{{ $settings['theme']['color'] ?? '' }}/{{ $settings['theme']['size'] ?? '' }}</p>
    <button class="reload-tags" fusion:click="$reload('tags')">Tags</button>
    <button class="reload-settings" fusion:click="$reload('settings')">Settings</button>
    <button class="touch" fusion:click="touch">{{ $touched }}</button>
</div>
