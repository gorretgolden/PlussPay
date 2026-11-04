<?php

namespace Tests\Feature;

use App\Models\ExchangeRate;
use App\Models\RateSnapshot;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Every desk reads the counter price off the board, but the live dollar market reading is a
 * market figure rather than a price the desk sells at. It is drawn for the Super Admin and the
 * super agent only, and the page for every other role must not carry it at all.
 *
 * The card is two lines on purpose. It used to be a tile per pair with a sparkline and a sentence
 * of change under each, and the whole board sat three screens below the figures a desk reads first.
 */
class DashboardRateCardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse('2026-10-22 10:30:00'));

        ExchangeRate::setPair('USD', 'UGX', 4321.5, 4200, 4321.5);
        ExchangeRate::setPair('USD', 'RMB', 6.7891, 6.85, 6.7891);

        foreach ([[20, 4170.0], [21, 4165.0], [22, 4177.5965]] as [$day, $rate]) {
            $at = Carbon::parse('2026-10-'.$day.' 06:05:00');

            RateSnapshot::create([
                'base_currency' => 'USD',
                'quote_currency' => 'UGX',
                'rate' => $rate,
                'rate_date' => $at->toDateString(),
                'source' => 'currency-api.pages.dev',
                'fetched_at' => $at,
            ]);
        }

        foreach ([[20, 6.7000], [21, 6.7000], [22, 6.6787]] as [$day, $rate]) {
            $at = Carbon::parse('2026-10-'.$day.' 06:05:00');

            RateSnapshot::create([
                'base_currency' => 'USD',
                'quote_currency' => 'RMB',
                'rate' => $rate,
                'rate_date' => $at->toDateString(),
                'source' => 'currency-api.pages.dev',
                'fetched_at' => $at,
            ]);
        }
    }

    private function user(string $role): User
    {
        $user = User::create([
            'name' => ucfirst($role).' Desk',
            'email' => $role.'.'.uniqid().'@plussave.test',
            'password' => 'Password123',
            'is_active' => true,
            'country' => 'UG',
        ]);
        $user->assignRole($role);

        return $user;
    }

    private function dashboardAs(string $role): string
    {
        $this->flushSession();

        return $this->actingAs($this->user($role))->get('/dashboard')->assertOk()->getContent();
    }

    public function test_every_desk_role_sees_both_counter_prices_on_one_line(): void
    {
        foreach (['superadmin', 'admin', 'accountant', 'super-agent', 'agent', 'staff'] as $role) {
            $html = $this->dashboardAs($role);

            $this->assertStringContainsString('Exchange Rate', $html, $role.' cannot see the rate card');
            $this->assertStringContainsString('Desk rate', $html, $role.' cannot see which line is the counter price');
            $this->assertStringContainsString('4,321.5 UGX/USD', $html, $role.' cannot see the shilling selling rate');
            $this->assertStringContainsString('6.7891 RMB/USD', $html, $role.' cannot see the yuan selling rate');
            $this->assertStringContainsString('4,321.5 UGX/USD · 6.7891 RMB/USD', $html, $role.' has the two prices on separate lines.');
        }
    }

    public function test_admin_and_the_desk_roles_are_not_sent_the_live_market_figures(): void
    {
        foreach (['admin', 'accountant', 'agent', 'staff'] as $role) {
            $html = $this->dashboardAs($role);

            $this->assertStringNotContainsString('Market ', $html, $role.' can see the live market line');
            $this->assertStringNotContainsString('4,177.6', $html, $role.' was sent the market shilling rate');
            $this->assertStringNotContainsString('6.6787', $html, $role.' was sent the market yuan rate');
            $this->assertStringNotContainsString('Mid-market', $html);
            $this->assertStringNotContainsString('currency-api.pages.dev', $html);
        }
    }

    public function test_the_super_admin_and_the_super_agent_see_the_market_with_its_direction(): void
    {
        foreach (['superadmin', 'super-agent'] as $role) {
            $html = $this->dashboardAs($role);

            $this->assertStringContainsString('Market 4,177.6 UGX/USD · 6.6787 RMB/USD', $html);
            $this->assertStringContainsString('up 12.6 (0.30%), 21 Oct', $html);
            $this->assertStringContainsString('down 0.02 (0.32%), 21 Oct', $html);
            $this->assertStringContainsString('Mid-market, not the counter price.', $html);
            $this->assertStringContainsString('Pulled 22 Oct, 06:05 from currency-api.pages.dev.', $html);

            // The market is a reference, so it sits under the counter price in grey at footnote size
            // rather than beside it at the same weight.
            $this->assertMatchesRegularExpression('/<p class="mt-1 text-xs leading-snug text-gray-500">\s*<span class="tabular-nums">Market /', $html);
        }
    }

    public function test_a_super_admin_without_a_reading_is_told_the_pull_has_not_run(): void
    {
        RateSnapshot::query()->delete();

        $html = $this->dashboardAs('superadmin');

        $this->assertStringContainsString('No live market reading yet.', $html);
        $this->assertStringNotContainsString('Pulled ', $html);
        $this->assertStringContainsString('Market pull stopped (the pull has never run)', $html);
    }

    public function test_a_pull_older_than_a_day_is_flagged_on_the_card_as_a_badge(): void
    {
        $this->travelTo(Carbon::parse('2026-10-23 12:00:00'));

        $html = $this->dashboardAs('superadmin');

        // A sentence in the grey footnote was skimmed past; the badge is red and says how long ago
        // the last pull landed.
        $this->assertMatchesRegularExpression(
            '/rounded-full bg-red-100[^"]*"[^>]*>\s*<svg[^>]*>.*?<\/svg>\s*Market pull stopped 1 day ago/',
            $html
        );
        $this->assertStringContainsString('text-red-700', $html);
        $this->assertStringNotContainsString('may have stopped', $html);
    }

    public function test_a_fresh_pull_is_not_flagged(): void
    {
        $this->assertStringNotContainsString('Market pull stopped', $this->dashboardAs('superadmin'));
    }

    public function test_a_pair_with_no_price_says_so_once_instead_of_an_old_figure(): void
    {
        ExchangeRate::query()->update(['is_active' => false]);

        $html = $this->dashboardAs('agent');

        $this->assertSame(1, substr_count($html, 'No price is in use.'));
        $this->assertStringNotContainsString('4,321.5', $html);
        $this->assertStringNotContainsString('6.7891', $html);
    }

    public function test_the_history_link_follows_who_may_open_the_rate_page(): void
    {
        $this->assertStringContainsString('Rate history', $this->dashboardAs('admin'));
        $this->assertStringContainsString('Rate history', $this->dashboardAs('superadmin'));
        $this->assertStringNotContainsString('Rate history', $this->dashboardAs('agent'));
        $this->assertStringNotContainsString('Rate history', $this->dashboardAs('super-agent'));
    }
}
