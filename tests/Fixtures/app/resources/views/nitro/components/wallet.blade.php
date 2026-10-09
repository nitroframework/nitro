<div class="wallet">
    <p class="balance">{{ $balance }}</p>
    <input type="number" nitro:model.number="amount">
    <p class="preview">{{ $this->preview() }}</p>
    @error('amount') <p class="error">{{ $message }}</p> @enderror
    <button nitro:click="withdraw($amount)">Withdraw</button>
</div>
