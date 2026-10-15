<div class="sign-up">
    <input class="email" type="email" nitro:model="email">
    @error('email') <p class="error-email">{{ $message }}</p> @enderror
    <input class="password" type="password" nitro:model="password">
    @error('password') <p class="error-password">{{ $message }}</p> @enderror
    <input class="password-confirmation" type="password" nitro:model="password_confirmation">
    <p class="plan">{{ $plan }}</p>
    <button class="save" nitro:click="save">Sign up</button>
</div>
