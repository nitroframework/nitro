<?php

namespace Nitro\Tests\Fixtures\Pages;

use Nitro\Attributes\Server;
use Nitro\Attributes\Validate;
use Nitro\Component;

/**
 * <nitro:dynamic-component :is="...">: the child is the component its expression names, and
 * another one when the name changes (none for "").
 */
class Panels extends Component
{
    public string $panel = 'card';

    /** Each with its own key (the name is part of it): a name twice is two components. */
    public array $stack = ['card', 'search-box'];

    /** Rows of Stamp children; addRow() adds one in the browser. */
    #[Validate(['rows' => 'array|max:50', 'rows.*' => 'string|max:20'])]
    public array $rows = ['a'];

    /** The key last pressed in .keys: two nitro:keydown handlers on one element. */
    public string $key = '';

    /** Labels of Badge children (mount()): added by a #[Server] call, which creates them. */
    #[Validate(['badges' => 'array|max:50', 'badges.*' => 'string|max:20'])]
    public array $badges = [];

    public function addRow(): void
    {
        $this->rows[] = 'r'.count($this->rows);
    }

    #[Server]
    public function addBadge(): void
    {
        $this->badges[] = 'badge '.(count($this->badges) + 1);
    }
}
