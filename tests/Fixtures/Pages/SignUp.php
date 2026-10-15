<?php

namespace Nitro\Tests\Fixtures\Pages;

use Nitro\Attributes\Locked;
use Nitro\Attributes\Server;
use Nitro\Attributes\Validate;
use Nitro\Component;
use Nitro\Tests\Fixtures\Components\Store;

/**
 * A sign-up form whose fields are checked as they are left (a check: UpdateController::check()):
 * a field's own rules, one against another field (confirmed), the rows of a list, and a locked plan.
 * Its boot() and hydrate() record that they ran, so a check can be seen to run neither.
 */
class SignUp extends Component
{
    #[Validate('required|email', as: 'email address')]
    public string $email = '';

    #[Validate('required|min:8|confirmed')]
    public string $password = '';

    public string $password_confirmation = '';

    /** The seats ordered with the account: one row each. */
    #[Validate(['lines' => 'array|max:5', 'lines.*.qty' => 'integer|min:1'])]
    public array $lines = [];

    #[Locked]
    public string $plan = 'free';

    public function boot(): void
    {
        Store::$log[] = 'boot';
    }

    public function hydrate(): void
    {
        Store::$log[] = 'hydrate';
    }

    #[Server]
    public function save(): void
    {
        $this->validate();
        Store::$log[] = "saved {$this->email}";
    }
}
