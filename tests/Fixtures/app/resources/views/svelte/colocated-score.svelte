<php>
use Illuminate\Support\Str;

/** A component written in its Svelte view: Fusion makes its class (ColocatedViews). */
new class extends Component {
    public int $score = 0;

    #[Computed]
    public function grade(): string
    {
        return $this->score >= 50 ? 'pass' : 'fail';
    }

    public function add(int $points): void
    {
        $this->score += $points;
    }

    #[Server]
    public function submit(): string
    {
        return Str::upper("submitted {$this->score}");
    }
};
</php>

<script>
    let { score, grade, add, submit } = $props();
    let said = $state('');
</script>

<div class="colocated-score">
    <span class="score">{score}</span> <span class="grade">{grade}</span>
    <button type="button" class="add" onclick={() => add(30)}>+30</button>
    <button type="button" class="submit" onclick={async () => (said = await submit())}>Submit</button>
    <span class="said">{said}</span>
</div>
