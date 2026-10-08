<?php

namespace Nitro\Fusion\Tests\Feature;

use Nitro\Fusion\Support\Signer;
use Nitro\Fusion\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * One signer for what Fusion signs: each signature for its purpose, and good after APP_KEY is
 * rotated while the old key is in APP_PREVIOUS_KEYS.
 */
class SignerTest extends TestCase
{
    #[DataProvider('frameworks')]
    public function test_a_signature_is_for_its_purpose(string $framework): void
    {
        $this->boot($framework);
        $signer = $this->app->make(Signer::class);
        $signature = $signer->sign('state', 'payload');

        $this->assertTrue($signer->verifies('state', 'payload', $signature));
        $this->assertFalse($signer->verifies('upload', 'payload', $signature), 'a snapshot\'s signature does not pass for an upload\'s');
        $this->assertFalse($signer->verifies('state', 'payload!', $signature));
    }

    #[DataProvider('frameworks')]
    public function test_a_signature_from_before_a_key_rotation_verifies_while_the_old_key_is_kept(string $framework): void
    {
        $this->boot($framework);
        $config = $this->app->make('config');
        $old = $config->get('app.key');
        $signature = $this->app->make(Signer::class)->sign('state', 'payload');

        $config->set('app.key', 'base64:'.base64_encode(random_bytes(32)));
        $this->app->forgetScopedInstances();
        $this->assertFalse($this->app->make(Signer::class)->verifies('state', 'payload', $signature), 'not with the new key alone');

        $config->set('app.previous_keys', [$old]);
        $this->app->forgetScopedInstances();
        $signer = $this->app->make(Signer::class);
        $this->assertTrue($signer->verifies('state', 'payload', $signature), 'with the old key in app.previous_keys');
        $this->assertNotSame($signature, $signer->sign('state', 'payload'), 'new signatures use the new key');
    }
}
