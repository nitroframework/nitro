<php>
use Nitro\Tests\Fixtures\Components\Store;

/** A component written in its Vue view: Nitro makes its class (ColocatedViews). */
new class extends Component {
    public array $marks = ['present' => 0, 'absent' => 0];

    #[Computed]
    public function total(): int
    {
        return array_sum($this->marks);
    }

    public function mark(string $as): void
    {
        $this->marks[$as]++;
    }

    #[Server]
    public function take(): string
    {
        Store::$saved['tally'] = $this->marks;

        return "taken: {$this->total()}";
    }
};
</php>

<script setup>
import { ref } from 'vue';
import { useNitro } from 'nitro/vue';

const { marks, total, mark, take } = useNitro();
const said = ref('');
</script>

<template>
    <div class="colocated-tally">
        <span class="present">{{ marks.present }}</span> <span class="absent">{{ marks.absent }}</span> <span class="total">{{ total }}</span>
        <button type="button" class="here" @click="mark('present')">Here</button>
        <button type="button" class="away" @click="mark('absent')">Away</button>
        <button type="button" class="take" @click="take().then((s) => (said = s))">Take</button>
        <span class="said">{{ said }}</span>
    </div>
</template>
