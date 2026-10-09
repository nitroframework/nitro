<?php

namespace Nitro\Tests\Fixtures\Pages;

use Nitro\Attributes\LazyLoad;
use Nitro\Attributes\Title;
use Nitro\Component;

/**
 * A routed page that places a child with mount() (the todo list): a nitro:navigate visit gets
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
