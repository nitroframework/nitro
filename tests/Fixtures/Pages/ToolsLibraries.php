<?php

namespace Nitro\Tests\Fixtures\Pages;

use Nitro\Attributes\Layout;
use Nitro\Attributes\View;

/** /libraries in a layout of its own, whose head loads the libraries: a visit into it brings them. */
#[Layout('components.layouts.tools')]
#[View('nitro.pages.libraries')]
class ToolsLibraries extends Libraries
{
}
