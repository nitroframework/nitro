<?php

namespace Nitro\Tests\Fixtures\Pages;

use Nitro\Attributes\Server;
use Nitro\Attributes\Validate;
use Nitro\Component;
use RuntimeException;
use Throwable;

/**
 * Every hook (Support/ComponentHooks.php, PropertyHooks.php) at work (/lifecycle): the
 * property hooks before and after a change, deep, on a snake_case property, one #[Server];
 * rendered() after each render, without running again for what it changes; exception() for a
 * #[Server] method that throws; a child's #[Server] destroyed() as it leaves.
 */
class Lifecycle extends Component
{
    public string $title = 'draft';

    /** @var array{email: string} */
    #[Validate(['form.email' => 'required|email'])]
    public array $form = ['email' => ''];

    /** Required, and left empty: validateOnly() of another property does not check it. */
    #[Validate('required')]
    public string $nickname = '';

    public string $first_name = '';

    public string $code = '';

    public string $checked = '';

    /** @var list<string> */
    public array $log = [];

    public int $renders = 0;

    public string $failure = '';

    public bool $child = true;

    public int $gone = 0;

    public function updatingTitle(string $value): void
    {
        $this->log[] = "updating title: {$this->title} -> {$value}";
    }

    public function updating(string $property, mixed $value): void
    {
        $this->log[] = "updating {$property}";
    }

    public function updatedTitle(string $value): void
    {
        $this->log[] = "updated title: {$this->title}";
    }

    public function updatedForm(mixed $value, ?string $key): void
    {
        $this->log[] = "updated form.{$key} = {$value}";
    }

    public function updatedFirstName(string $value): void
    {
        $this->log[] = "first_name = {$value}";
    }

    #[Server]
    public function updatedCode(string $value): void
    {
        $this->checked = strtoupper($value).' on the server';
    }

    public function rendered(): void
    {
        $this->renders++;
    }

    #[Server]
    public function explode(): void
    {
        throw new RuntimeException('it broke');
    }

    public function exception(Throwable $exception): void
    {
        $this->failure = 'handled: '.$exception->getMessage();
    }

    /** validateOnly(): the email's rules, not $nickname's. */
    #[Server]
    public function checkEmail(): void
    {
        $this->validateOnly('form.email');
        $this->log[] = 'email checked';
    }

    #[Server]
    public function countGone(): void
    {
        $this->gone = count((array) session('lifecycle.gone', []));
    }
}
