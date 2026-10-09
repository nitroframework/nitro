<!DOCTYPE html>
<html>
<head>
    <title>Islands</title>
</head>
<body>
    <section class="now"><nitro:counter /></section>
    <section class="idle"><nitro:counter nitro:hydrate.idle /></section>
    <section class="media"><nitro:counter nitro:hydrate.media="(min-width: 1200px)" /></section>
    <section class="static"><nitro:counter nitro:static /></section>
    <section class="visible"><nitro:beacon nitro:hydrate.visible.100px /></section>
    <section class="interaction"><nitro:beacon nitro:hydrate.interaction /></section>
    <section class="typing"><nitro:beacon nitro:hydrate.interaction /></section>
    <section class="slotted">
        <nitro:card title="Slotted" nitro:hydrate.idle>
            <em class="from-blade">From the Blade view</em>
            <nitro:slot name="footer"><small class="foot">Its footer</small></nitro:slot>
        </nitro:card>
    </section>
    @nitroScripts
</body>
</html>
