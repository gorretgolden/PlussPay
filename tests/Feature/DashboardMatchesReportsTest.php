<?php

namespace Tests\Feature;

use App\Models\ExchangeRate;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class DashboardMatchesReportsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['superadmin', 'admin', 'agent', 'staff'] as $role) {
            Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        }
    }

    private function admin(string $name = 'Gorret Admin'): User
    {
        $user = User::create([
            'name' => $name,
            'email' => strtolower(str_replace(' ', '.', $name)) . uniqid() . '@plussave.test',
            'password' => 'Password123',
            'is_active' => true,
        ]);

        $user->assignRole('admin');

        return $user;
    }

    private function transfer(User $by, array $overrides = []): Transaction
    {
        return Transaction::create(array_merge([
            'receipt_number' => 'PS-' . uniqid(),
            'sender_name' => 'Sender One',
            'sender_contact' => '0700000001',
            'receiver_name' => 'Receiver One',
            'receiver_contact' => '8613800000001',
            'currency' => 'UGX',
            'amount' => 1000000,
            // What the controller really stores: exchange_rate is the dollar to RMB rate,
            // and the UGX price the money was sold at is in rate_sell_ugx.
            'exchange_rate' => 7.2,
            'rate_buy_ugx' => 3600,
            'rate_sell_ugx' => 4000,
            'rmb_amount' => 1764.71,
            'charge_rate' => 0.02,
            'charge_amount' => 2.5,
            'charge_ugx' => 10000,
            'total_usd_equivalent' => 250,
            'status' => 'completed',
            'created_by' => $by->id,
        ], $overrides));
    }

    public function test_the_dashboard_reads_the_ugx_the_transfer_was_priced_at(): void
    {
        $admin = $this->admin();

        ExchangeRate::setPair('USD', 'UGX', 4000, 3600, 4000);
        $this->transfer($admin);

        // The rate moves after the transfer. Converting the stored dollars at the new
        // rate is what made the dashboard and Reports disagree.
        ExchangeRate::setPair('USD', 'UGX', 4200, 3800, 4200);

        $stats = $this->actingAs($admin)->get('/dashboard')->assertOk()->viewData('stats');

        $this->assertEqualsWithDelta(1000000, (float) $stats['total_ugx_sent'], 0.01);
        $this->assertEqualsWithDelta(10000, (float) $stats['total_revenue_ugx'], 0.01);
        $this->assertEqualsWithDelta(1000000, (float) $stats['today_ugx_sent'], 0.01);
        $this->assertEqualsWithDelta(10000, (float) $stats['today_revenue_ugx'], 0.01);
    }

    public function test_the_dashboard_and_reports_show_the_same_money(): void
    {
        $admin = $this->admin();

        ExchangeRate::setPair('USD', 'UGX', 4000, 3600, 4000);
        $this->transfer($admin);
        ExchangeRate::setPair('USD', 'UGX', 4200, 3800, 4200);

        $dashboard = $this->actingAs($admin)->get('/dashboard')->assertOk()->viewData('stats');

        $reports = $this->actingAs($admin)
            ->get('/reports?date_from=' . now()->toDateString() . '&date_to=' . now()->toDateString())
            ->assertOk();

        $profit = $reports->viewData('profit');
        $byCurrency = $reports->viewData('byCurrency');

        $this->assertEqualsWithDelta((float) $byCurrency['UGX']['total'], (float) $dashboard['total_ugx_sent'], 0.01);
        $this->assertEqualsWithDelta((float) $profit['fee_ugx'], (float) $dashboard['total_revenue_ugx'], 0.01);
    }

    public function test_a_dollar_funded_transfer_is_counted_at_its_own_rate(): void
    {
        $admin = $this->admin();

        ExchangeRate::setPair('USD', 'UGX', 4000, 3600, 4000);
        $this->transfer($admin, [
            'currency' => 'USD',
            'amount' => 250,
            'charge_ugx' => 0,
            'charge_amount' => 5,
        ]);

        ExchangeRate::setPair('USD', 'UGX', 4200, 3800, 4200);

        $stats = $this->actingAs($admin)->get('/dashboard')->assertOk()->viewData('stats');

        // Sold at 4,000 when it was booked, so it is counted at 4,000 and not at today's 4,200.
        $this->assertEqualsWithDelta(1000000, (float) $stats['total_ugx_sent'], 0.01);
        $this->assertEqualsWithDelta(20000, (float) $stats['total_revenue_ugx'], 0.01);
    }

    public function test_a_transfer_booked_before_the_ugx_price_was_recorded_uses_todays_rate(): void
    {
        $admin = $this->admin();

        ExchangeRate::setPair('USD', 'UGX', 4000, 3600, 4000);
        $this->transfer($admin, [
            'currency' => 'USD',
            'amount' => 250,
            'rate_sell_ugx' => 0,
            'charge_ugx' => 0,
            'charge_amount' => 5,
        ]);
        ExchangeRate::setPair('USD', 'UGX', 4200, 3800, 4200);

        $stats = $this->actingAs($admin)->get('/dashboard')->assertOk()->viewData('stats');

        $this->assertEqualsWithDelta(1050000, (float) $stats['total_ugx_sent'], 0.01);
        $this->assertEqualsWithDelta(21000, (float) $stats['total_revenue_ugx'], 0.01);
    }

    public function test_the_average_card_uses_the_same_ugx_as_the_total(): void
    {
        $admin = $this->admin();

        ExchangeRate::setPair('USD', 'UGX', 4000, 3600, 4000);
        $this->transfer($admin);
        $this->transfer($admin, ['amount' => 3000000, 'charge_ugx' => 30000, 'total_usd_equivalent' => 750]);
        ExchangeRate::setPair('USD', 'UGX', 4200, 3800, 4200);

        $stats = $this->actingAs($admin)->get('/dashboard')->assertOk()->viewData('stats');

        $this->assertEqualsWithDelta(2000000, (float) $stats['avg_ugx_sent'], 0.01);
    }

    public function test_the_seven_day_chart_uses_the_stored_ugx_too(): void
    {
        $admin = $this->admin();

        ExchangeRate::setPair('USD', 'UGX', 4000, 3600, 4000);
        $this->transfer($admin);
        ExchangeRate::setPair('USD', 'UGX', 4200, 3800, 4200);

        $chart = $this->actingAs($admin)->get('/dashboard')->assertOk()->viewData('chartData');

        $this->assertArrayNotHasKey('revenue_ugx', $chart);
        $this->assertEqualsWithDelta(10000, (float) array_sum($chart['revenue']), 0.01);
    }
}
