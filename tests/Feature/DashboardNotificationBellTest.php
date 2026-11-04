<?php

namespace Tests\Feature;

use App\Models\ExchangeRate;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Word of what the desk did while whoever reads it was away lives in one place: the What Happened
 * entry in the sidebar, with the unread count beside it. The board used to hang a second bell in
 * its header carrying the same total, which was two copies of one link to keep in step on a screen
 * that was already short of room.
 */
class DashboardNotificationBellTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['superadmin', 'agent'] as $role) {
            Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        }

        ExchangeRate::setPair('USD', 'UGX', 4000, 3600, 4000);
        ExchangeRate::setPair('USD', 'RMB', 6.56, 6.56, 6.56);
    }

    private function desk(string $role = 'superadmin'): User
    {
        $user = User::create([
            'name' => 'Bell Desk',
            'email' => 'bell.'.uniqid().'@plussave.test',
            'password' => 'Password123',
            'is_active' => true,
        ]);
        $user->assignRole($role);

        return $user;
    }

    private function unreadNotice(User $user): void
    {
        $user->notifications()->create([
            'id' => (string) Str::uuid(),
            'type' => 'activity',
            'data' => [
                'section' => 'transfers',
                'headline' => 'A new transfer was booked',
                'body' => 'PS-TEST: UGX 100,000 in for USD 25.00 to a receiver, UGX 4,000 charge',
                'actor' => 'Bell Desk',
                'actor_role' => 'Super Admin',
                'path' => '/transactions/1',
                'link_label' => 'Open the transfer',
                'log_id' => '1',
            ],
        ]);
    }

    public function test_a_desk_on_the_news_audience_gets_the_inbox_in_its_menu(): void
    {
        $html = $this->actingAs($this->desk())->get('/dashboard')->getContent();

        $this->assertStringContainsString('/notifications', $html);
        $this->assertStringContainsString('What Happened', $html);
        $this->assertStringNotContainsString('bg-yellow-300', $html, 'Nothing unread, so no count rides on the menu entry.');
    }

    public function test_the_menu_counts_what_has_not_been_seen(): void
    {
        $user = $this->desk();
        $this->unreadNotice($user);
        $this->unreadNotice($user);

        $html = $this->actingAs($user)->get('/dashboard')->getContent();

        $this->assertMatchesRegularExpression('#<span class="flex-1">What Happened</span>\s*<span class="ml-2 bg-yellow-300[^"]*">2</span>#', $html);
    }

    public function test_the_board_itself_hangs_no_second_bell(): void
    {
        $user = $this->desk();
        $this->unreadNotice($user);

        $html = $this->actingAs($user)->get('/dashboard')->getContent();

        $this->assertStringNotContainsString('aria-label="What happened', $html, 'The header copy of the link is gone.');
        $this->assertStringNotContainsString('-top-1 -right-1', $html, 'No badge hangs off a corner of the board.');
    }

    public function test_a_desk_on_the_receiving_end_of_no_news_gets_no_inbox_link(): void
    {
        $html = $this->actingAs($this->desk('agent'))->get('/dashboard')->getContent();

        $this->assertStringNotContainsString('What Happened', $html);
    }
}
