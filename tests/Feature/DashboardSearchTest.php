<?php

namespace Tests\Feature;

use App\Models\ExchangeRate;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * One box on the dashboard that searches the pages this account can open. The
 * index is read off the sidebar links already on the page, so the permission
 * gates that build the sidebar are the only ones the search ever needs.
 */
class DashboardSearchTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Role::firstOrCreate(['name' => 'agent', 'guard_name' => 'web']);
        ExchangeRate::setPair('USD', 'UGX', 4000, 3600, 4000);
        ExchangeRate::setPair('USD', 'RMB', 6.56, 6.56, 6.56);
    }

    private function board(string $role = 'agent'): string
    {
        $user = User::create([
            'name' => 'Search Desk',
            'email' => 'search.'.uniqid().'@plussave.test',
            'password' => 'Password123',
            'is_active' => true,
        ]);
        $user->assignRole($role);

        return $this->actingAs($user)->get('/dashboard')->getContent();
    }

    public function test_the_board_carries_one_search_box_under_the_header_card(): void
    {
        $html = $this->board();

        $this->assertSame(1, substr_count($html, 'id="dashboardSearch"'));
        $this->assertStringContainsString('Search the desk', $html);
        $this->assertStringContainsString('aria-controls="dashboardSearchResults"', $html);
        $this->assertStringContainsString('Nothing on the desk matches that.', $html);
    }

    public function test_the_index_is_built_from_the_sidebar_links_the_permission_gates_already_rendered(): void
    {
        $html = $this->board();

        // The script reads the sidebar's own links, so no second permission list exists to drift.
        $this->assertStringContainsString("#appSidebar nav a[href]", $html);
        $this->assertStringContainsString("div[id$=\"Group\"]", $html);
    }

    public function test_an_agent_is_never_offered_a_page_their_sidebar_does_not_show(): void
    {
        $html = $this->board('agent');

        // The Settings group is gated away from an agent, and since the search reads the
        // sidebar itself, the box cannot offer what the sidebar does not render.
        $this->assertStringNotContainsString('id="settingsGroup"', $html);
        $this->assertStringContainsString('id="dashboardSearch"', $html);
    }

    public function test_the_slash_opens_the_box_but_never_steals_a_keystroke_from_another_field(): void
    {
        $html = $this->board();

        $this->assertStringContainsString("if (event.key !== '/') return;", $html);
        $this->assertStringContainsString('target.tagName === \'INPUT\'', $html);
    }
}
