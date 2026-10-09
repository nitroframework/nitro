<?php

namespace Nitro\Tests\Fixtures\Pages;

use Nitro\Attributes\On;
use Nitro\Attributes\Server;
use Nitro\Component;

/**
 * Broadcast events (#[On('echo...')]): a public channel, a private one named by the state, a
 * presence channel's members, notifications. The browser tests give it a stand-in Laravel Echo.
 */
class Live extends Component
{
    public array $order = ['id' => 7];

    public array $placed = [];

    public array $shipped = [];

    public array $members = [];

    public string $note = '';

    #[On('echo:orders,OrderPlaced')]
    public function orderPlaced(array $event): void
    {
        $this->placed[] = $event['number'];
    }

    /** A #[Server] method: the event's data comes with the call. */
    #[On('echo-private:orders.{order.id},OrderShipped')]
    #[Server]
    public function orderShipped(array $event): void
    {
        $this->shipped[] = $event['number'].' (server)';
    }

    #[On('echo-presence:room,here')]
    public function here(array $users): void
    {
        $this->members = array_column($users, 'name');
    }

    #[On('echo-presence:room,joining')]
    public function joining(array $user): void
    {
        $this->members[] = $user['name'];
    }

    #[On('echo-notification:users.{order.id}')]
    public function notified(array $notification): void
    {
        $this->note = $notification['message'];
    }
}
