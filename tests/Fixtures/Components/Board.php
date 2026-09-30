<?php

namespace Nitro\Fusion\Tests\Fixtures\Components;

use Nitro\Fusion\Attributes\LazyLoad;
use Nitro\Fusion\Attributes\Title;
use Nitro\Fusion\Component;

/**
 * A routed page that places a child with mount() (the todo list): a fusion:navigate visit gets
 * that child's state from the server, since the browser cannot run mount().
 */
#[Title('Board')]
#[LazyLoad]
class Board extends Component
{
    public array $todos = [
        ['id' => 1, 'title' => 'Plan', 'done' => true],
        ['id' => 2, 'title' => 'Build', 'done' => false],
    ];

    public string $heading = 'Board';
}
