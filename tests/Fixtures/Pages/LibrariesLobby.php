<?php

namespace Nitro\Tests\Fixtures\Pages;

use Nitro\Attributes\Title;
use Nitro\Component;

/** A page in the same layout as /libraries, loading the same libraries: a visit to it is the way in. */
#[Title('Lobby')]
class LibrariesLobby extends Component
{
}
