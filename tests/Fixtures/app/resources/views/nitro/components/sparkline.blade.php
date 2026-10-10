<div class="spark">
    <canvas nitro:ref="chart"></canvas>
    <span class="spark-total">{{ array_sum($points) }}</span>
    <button type="button" class="spark-add" nitro:click="add">Add</button>
    @nitroScript
    <script>
        /** Once for each instance: $refs, $el, $nitro as $this->js() has it, and $component, the handle. */
        window.sparks ??= { runs: 0, cleanups: 0 };
        window.sparks.runs++;
        const canvas = $refs.chart;
        canvas.dataset.drawn = JSON.stringify($component.get('points'));
        canvas.dataset.direct = String($nitro.points.length) + ':' + ($el.classList.contains('spark') ? 'root' : 'not root');
        const stop = $component.watch('points', (points) => { canvas.dataset.drawn = JSON.stringify(points); });

        return () => {
            stop();
            window.sparks.cleanups++;
        };
    </script>
    @endNitroScript
</div>
