<?php

namespace Nitro\Tests\Feature;

use Illuminate\Support\Facades\Route;
use InvalidArgumentException;
use Nitro\Compiler\CompileException;
use Nitro\Compiler\ComponentReader;
use Nitro\Tests\Fixtures\Components\Counter;
use Nitro\Tests\Fixtures\Invalid\TitledComponent;
use Nitro\Tests\Fixtures\Pages\Counter as CounterPage;
use Nitro\Tests\Fixtures\Pages\StudentPage;
use Nitro\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Pages and components, as Livewire 4 separates them: pages (nitro.pages, "pages::...") are
 * routed and have a layout and a title; components (nitro.components) are placed in views.
 */
class PagesTest extends TestCase
{
    #[DataProvider('frameworks')]
    public function test_components_and_pages_are_named_and_find_their_views_apart(string $framework): void
    {
        $this->boot($framework);

        $component = app(\Nitro\Compiler\Components::class)->meta(Counter::class);
        $page = app(\Nitro\Compiler\Components::class)->meta(StudentPage::class);

        $this->assertSame(['counter', 'nitro.components.counter', false], [$component->name, $component->view, $component->page]);
        $this->assertSame(['pages::student-page', 'nitro.pages.student-page', true], [$page->name, $page->view, $page->page]);
        $this->assertSame(StudentPage::class, app(\Nitro\Support\Registry::class)->class('pages::student-page'));
    }

    #[DataProvider('frameworks')]
    public function test_a_page_is_routed_by_its_class_or_its_name(string $framework): void
    {
        $this->boot($framework);

        $byClass = Route::nitro('/by-class', CounterPage::class);
        $byName = Route::nitro('/by-name', 'pages::counter');

        $this->assertSame(CounterPage::class.'@callAction', $byClass->getActionName());
        $this->assertSame(CounterPage::class.'@callAction', $byName->getActionName());
    }

    #[DataProvider('frameworks')]
    public function test_a_component_cannot_be_routed(string $framework): void
    {
        $this->boot($framework);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Route::nitro() routes pages: '.Counter::class.' is a component');

        Route::nitro('/component', Counter::class);
    }

    #[DataProvider('frameworks')]
    public function test_a_page_cannot_be_placed_in_a_view(string $framework): void
    {
        $this->boot($framework);

        foreach ([
            fn () => app(\Nitro\Rendering\Placement::class)->mount('pages::counter'),
            fn () => app(\Nitro\Rendering\Placement::class)->tag('dynamic-component', ['is' => 'pages::counter'], null, 0),
        ] as $place) {
            try {
                $place();
                $this->fail('A page placed in a view must fail.');
            } catch (InvalidArgumentException $e) {
                $this->assertStringContainsString('[pages::counter] is a page: pages are routed', $e->getMessage());
            }
        }
    }

    public function test_a_layout_or_a_title_is_a_pages(): void
    {
        $this->expectException(CompileException::class);
        $this->expectExceptionMessage('#[Layout], #[Title], #[Description] and #[Navigate] are a page\'s: '.TitledComponent::class.' is a component');

        ComponentReader::read(TitledComponent::class, 'titled-component', 'nitro.components.titled-component');
    }
}
