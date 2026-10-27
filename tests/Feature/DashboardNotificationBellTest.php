<?php

namespace Tests\Feature;

use App\Models\ExchangeRate;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * The bell beside the clock opens What Happened and carries the unread total. Only a desk that
 * is on the receiving end of at least one kind of news gets the bell, and its badge is the same
 * figure the sidebar badges are read from.
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

    public function test_a_desk_on_the_news_audience_gets_a_bell_pointing_at_the_inbox(): void
    {
        $html = $this->actingAs($this->desk())->get('/dashboard')->getContent();

        $this->assertStringContainsString('/notifications', $html);
        $this->assertStringContainsString('aria-label="What happened"', $html);
        $this->assertStringNotContainsString('-top-1 -right-1', $html, 'Nothing unread, so no badge hangs on the bell.');
    }

    public function test_the_badge_counts_what_has_not_been_seen(): void
    {
        $user = $this->desk();
        $this->unreadNotice($user);
        $this->unreadNotice($user);

        $html = $this->actingAs($user)->get('/dashboard')->getContent();

        $this->assertStringContainsString('aria-label="What happened, 2 unread"', $html);
        $this->assertMatchesRegularExpression('#<span class="absolute -top-1 -right-1[^"]*">2</span>#', $html);
    }

    public function test_a_badge_over_ninety_nine_stays_short(): void
    {
        $user = $this->desk();
        for ($i = 0; $i < 120; $i++) {
            $this->unreadNotice($user);
        }

        $html = $this->actingAs($user)->get('/dashboard')->getContent();

        $this->assertStringContainsString('>99+</span>', $html);
    }

    public function test_a_desk_on_the_receiving_end_of_no_news_gets_no_bell(): void
    {
        $html = $this->actingAs($this->desk('agent'))->get('/dashboard')->getContent();

        $this->assertStringNotContainsString('aria-label="What happened', $html);
        $this->assertStringNotContainsString('What happened,', $html);
    }
}
