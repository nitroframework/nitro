<?php

namespace Nitro\Fusion\Tests\Fixtures\Components;

use Illuminate\Validation\ValidationException;
use Nitro\Fusion\Attributes\Locked;
use Nitro\Fusion\Attributes\Server;
use Nitro\Fusion\Component;
use Nitro\Fusion\Tests\Fixtures\Attributes\Audited;

/**
 * The security fixture: a locked balance, typed server arguments, and methods the browser must
 * not reach on the server.
 */
class Wallet extends Component
{
    #[Locked]
    public int $balance = 100;

    public int $amount = 0;

    public ?float $ratio = null;

    public string $label = '';

    public function preview(): int
    {
        return $this->balance - $this->amount;
    }

    /** #[Audited]: an attribute that works as a feature, around this method's real calls (FeatureAttributeTest). */
    #[Server]
    #[Audited(as: 'wallet')]
    public function withdraw(int $amount, string $reason = 'none'): int
    {
        if ($amount > $this->balance) {
            throw ValidationException::withMessages(['amount' => 'Not enough money.']);
        }

        $this->balance -= $amount;
        Store::$saved['withdraw'] = [$amount, $reason];

        return $this->balance;
    }

    /** A browser method: callable in the page, never through the endpoint. */
    public function clientOnly(): void
    {
        $this->balance = 1000000;
    }

    public function drain(): void
    {
        Store::$saved['drained'] = true;
    }
}
