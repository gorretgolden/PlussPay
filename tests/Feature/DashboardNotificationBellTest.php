<?php

namespace Tests\Feature;

use App\Models\ExchangeRate;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * The bell beside the clock opens What Happened and carries the unread total, because the hour the
 * board was read at is exactly the moment somebody asks what happened while the desk was shut. The
 * sidebar entry counts off the same figures, so the two can never disagree, and a desk on the
 * receiving end of no kind of news is not handed a dead bell at all.
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

        $this->assertStringContainsString('href="'.route('notifications.index').'"', $html);
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
        $this->assertMatchesRegularExpression('#<span class="absolute -top-1 -right-1[^"]*"[^>]*>2</span>#', $html);

        // The menu entry is read off the same total, so the bell and the drawer never disagree.
        $this->assertMatchesRegularExpression('#<span class="flex-1">What Happened</span>\s*<span class="ml-2 bg-yellow-300[^"]*">2</span>#', $html);
    }

    public function test_a_badge_over_ninety_nine_stays_short(): void
    {
        $user = $this->desk();
        for ($i = 0; $i < 120; $i++) {
            $this->unreadNotice($user);
        }

        $html = $this->actingAs($user)->get('/dashboard')->getContent();

        // The pill is a corner mark on a 40px button: three digits of it would push the word along
        // the row, so a queue that long is said as "more than ninety nine" instead.
        $this->assertMatchesRegularExpression('#<span class="absolute -top-1 -right-1[^"]*"[^>]*>99\+</span>#', $html);
        $this->assertStringContainsString('aria-label="What happened, 120 unread"', $html, 'The accessible name still says how many there really are.');
    }

    public function test_a_desk_on_the_receiving_end_of_no_news_gets_no_bell_or_inbox_link(): void
    {
        $html = $this->actingAs($this->desk('agent'))->get('/dashboard')->getContent();

        $this->assertStringNotContainsString('aria-label="What happened', $html);
        $this->assertStringNotContainsString('What Happened', $html);
    }
}
