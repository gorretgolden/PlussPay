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
}
