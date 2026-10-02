<!DOCTYPE html>
<html>
<head>
    <title>Islands</title>
</head>
<body>
    <section class="now"><fusion:counter /></section>
    <section class="idle"><fusion:counter fusion:hydrate.idle /></section>
    <section class="media"><fusion:counter fusion:hydrate.media="(min-width: 1200px)" /></section>
    <section class="static"><fusion:counter fusion:static /></section>
    <section class="visible"><fusion:beacon fusion:hydrate.visible.100px /></section>
    <section class="interaction"><fusion:beacon fusion:hydrate.interaction /></section>
    <section class="typing"><fusion:beacon fusion:hydrate.interaction /></section>
    <section class="slotted">
        <fusion:card title="Slotted" fusion:hydrate.idle>
            <em class="from-blade">From the Blade view</em>
            <fusion:slot name="footer"><small class="foot">Its footer</small></fusion:slot>
        </fusion:card>
    </section>
    @fusionScripts
</body>
</html>
