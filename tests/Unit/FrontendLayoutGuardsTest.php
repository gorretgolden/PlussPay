<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class FrontendLayoutGuardsTest extends TestCase
{
    private function read(string $path): string
    {
        $full = dirname(__DIR__, 2).DIRECTORY_SEPARATOR.$path;
        $this->assertFileExists($full);

        return file_get_contents($full);
    }

    public function test_the_users_table_pins_every_column_and_stops_headers_breaking_mid_word(): void
    {
        $css = $this->read('public'.DIRECTORY_SEPARATOR.'user-management.css');
        $this->assertMatchesRegularExpression('/\.user-table\{[^}]*min-width:700px/', $css, 'The table must scroll rather than crush a column.');
        $this->assertMatchesRegularExpression('/\.user-table th\{[^}]*overflow-wrap:normal/', $css, 'Anywhere wrapping stacks a header one letter per line.');

        $table = $this->read('resources'.DIRECTORY_SEPARATOR.'views'.DIRECTORY_SEPARATOR.'users'.DIRECTORY_SEPARATOR.'partials'.DIRECTORY_SEPARATOR.'table.blade.php');
        $this->assertMatchesRegularExpression('/<colgroup>(<col style="width:[^"]+">){7}<\/colgroup>/', $table, 'Every column needs an explicit width.');
    }

    public function test_validation_banners_keep_their_slot_so_fields_do_not_move_under_a_click(): void
    {
        $forms = $this->read('public'.DIRECTORY_SEPARATOR.'js'.DIRECTORY_SEPARATOR.'admin-forms.js');
        $this->assertStringNotContainsString('banner.hidden = active.length === 0;', $forms);
        $this->assertStringContainsString("banner.style.visibility = active.length ? 'visible' : 'hidden';", $forms);

        $users = $this->read('public'.DIRECTORY_SEPARATOR.'user-management.js');
        $this->assertStringNotContainsString('summary.hidden = !count;', $users);
        $this->assertStringContainsString("summary.className = 'user-summary';", $users);
        $this->assertMatchesRegularExpression('/\.user-summary\{[^}]*min-height:\d+px/', $this->read('public'.DIRECTORY_SEPARATOR.'user-management.css'));
    }

    public function test_a_details_element_used_as_a_menu_button_hides_the_disclosure_triangle(): void
    {
        $roles = $this->read('resources'.DIRECTORY_SEPARATOR.'views'.DIRECTORY_SEPARATOR.'admin'.DIRECTORY_SEPARATOR.'roles.blade.php');
        $this->assertMatchesRegularExpression('/<summary[^>]*list-none[^>]*>⋮<\/summary>/', $roles);
        $this->assertStringContainsString('[&::-webkit-details-marker]:hidden', $roles);
    }

    /**
     * The desk's country decides which wallets and which money a person is shown, so it
     * has to be readable on the page they land on, including when it was never filled in.
     */
    public function test_the_dashboard_names_the_desk_country_and_says_so_when_it_is_missing(): void
    {
        $dashboard = $this->read('resources'.DIRECTORY_SEPARATOR.'views'.DIRECTORY_SEPARATOR.'dashboard.blade.php');
        $layout = $this->read('resources'.DIRECTORY_SEPARATOR.'views'.DIRECTORY_SEPARATOR.'layouts'.DIRECTORY_SEPARATOR.'app.blade.php');

        $this->assertStringContainsString('$me->countryLabel()', $dashboard);
        $this->assertStringContainsString("'Desk country not set'", $dashboard);
        $this->assertStringContainsString("'Desk country not set'", $layout);

        // Two wallets in a three column grid left a hole at the right of the page.
        $this->assertStringContainsString('grid grid-cols-1 sm:grid-cols-2 gap-4 mb-6', $dashboard);
        $this->assertStringNotContainsString('grid-cols-1 md:grid-cols-3 gap-4 mb-6', $dashboard);

        $this->assertStringNotContainsString('Welcome, {{ auth()->user()->name }}', $dashboard);
        $this->assertStringContainsString('$greeting', $dashboard);
    }

    /**
     * The same money used to be summed into three blocks under three labels, so a reader had to
     * work out which of the four "Money Sent" figures to trust. One window at a time now, and a
     * number that will not fit its card gives up pixels instead of spilling over the border.
     */
    public function test_the_dashboard_prints_each_figure_once_and_keeps_a_long_one_inside_its_card(): void
    {
        $dashboard = $this->read('resources'.DIRECTORY_SEPARATOR.'views'.DIRECTORY_SEPARATOR.'dashboard.blade.php');

        foreach ([
            "Today's Summary", 'Total Transactions', 'Charge (All Time)', 'Total RMB Sent',
            'Still Pending', 'Active Exchange Rates', 'Completed Today', 'Money Sent (',
            'Exchange Profit', 'Avg Transaction',
        ] as $gone) {
            $this->assertStringNotContainsString($gone, $dashboard, $gone.' is the same figure under a second label.');
        }

        $this->assertSame(1, substr_count($dashboard, 'role="tablist"'), 'One window picker, not three blocks.');
        $this->assertSame(1, substr_count($dashboard, 'role="tab" '), 'One tab template, repeated per window.');
        $this->assertSame(1, substr_count($dashboard, 'role="tabpanel"'));
        $this->assertStringContainsString("['today' => 'Today', 'month' => 'This Month', 'all' => 'All Time']", $dashboard);

        $this->assertStringContainsString('.stat-card { container-type: inline-size; }', $dashboard);
        $this->assertMatchesRegularExpression('/\.stat-value \{[^}]*clamp\([^}]*100cqi[^}]*var\(--len\)/', $dashboard);
        $this->assertStringContainsString('style="--len: {{ strlen($cardValue) }}"', $dashboard);
        $this->assertStringContainsString('whitespace-nowrap', $dashboard);

        // The paragraph that explained the profit figures is a tooltip on the heading now.
        $this->assertStringNotContainsString('never guessed', $dashboard);
        $this->assertSame(1, substr_count($dashboard, 'role="tooltip"'));
    }

    /**
     * Eight columns of a receipt table crush into one letter per line on a phone unless the
     * table is allowed to scroll inside its own card.
     */
    public function test_the_recent_transactions_table_scrolls_instead_of_shrinking_its_headers(): void
    {
        $dashboard = $this->read('resources'.DIRECTORY_SEPARATOR.'views'.DIRECTORY_SEPARATOR.'dashboard.blade.php');

        $this->assertMatchesRegularExpression('/<div class="overflow-x-auto">\s*<table class="w-full min-w-\[\d+px\]/', $dashboard);
        $this->assertSame(8, substr_count($dashboard, 'tracking-wide whitespace-nowrap">'), 'Every header cell stays on one line.');
    }
}
