<script>
    let { greeting, knocks, knock, shout } = $props();
    let shouted = $state('');
</script>

<section class="svelte-page">
    <h1 class="greeting">{greeting}</h1>
    <span class="knocks">{knocks}</span>
    <button type="button" class="knock" onclick={() => knock()}>Knock</button>
    <button type="button" class="shout" onclick={async () => (shouted = await shout())}>Shout</button>
    <span class="shouted">{shouted}</span>
</section>
