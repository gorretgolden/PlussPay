<?php

namespace Tests\Feature;

use App\Models\ExchangeRate;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ReportConsolidationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['superadmin', 'admin', 'agent', 'staff'] as $role) {
            Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        }

        // 250 dollars delivering exactly 1,750 yuan reads at seven, so the yuan figures
        // in these tests are easy to follow by hand.
        ExchangeRate::setPair('USD', 'RMB', 7);
    }

    private function user(string $name, string $role): User
    {
        $user = User::create([
            'name' => $name,
            'email' => strtolower(str_replace(' ', '.', $name)).uniqid().'@plussave.test',
            'password' => 'Password123',
            'is_active' => true,
        ]);

        $user->assignRole($role);

        return $user;
    }

    private function transfer(User $by, array $overrides = []): Transaction
    {
        return Transaction::create(array_merge([
            'receipt_number' => 'PS-'.uniqid(),
            'sender_name' => 'Asuma Aggrey',
            'sender_contact' => '0700000001',
            'receiver_name' => 'Wei Ming',
            'receiver_contact' => '8613800000001',
            'currency' => 'USD',
            'amount' => 250,
            'exchange_rate' => 7,
            'rate_buy_ugx' => 3600,
            'rate_sell_ugx' => 3760,
            'rmb_amount' => 1750,
            'charge_rate' => 0.01,
            'charge_amount' => 2.5,
            'charge_ugx' => 9400,
            'total_usd_equivalent' => 250,
            'status' => 'completed',
            'created_by' => $by->id,
        ], $overrides));
    }

    private function reports(User $viewer)
    {
        $today = now()->toDateString();

        return $this->actingAs($viewer)
            ->get('/reports?date_from='.$today.'&date_to='.$today)
            ->assertOk();
    }

    public function test_the_daily_and_agent_tables_carry_the_same_yuan_as_the_profit_cards(): void
    {
        $admin = $this->user('Gorret Admin', 'admin');

        // Two rates, the second one delivering 1,850 yuan on 250 dollars. That division does
        // not come out even, which is the shape that reads as seven rather than 7.4 when the
        // database treats both columns as whole numbers.
        $this->transfer($admin, ['fx_profit_ugx' => 1200, 'fx_profit_usd' => 1.5]);
        $this->transfer($admin, [
            'exchange_rate' => 7.4,
            'rmb_amount' => 1850,
            'charge_amount' => 3,
            'fx_profit_ugx' => 900,
            'fx_profit_usd' => 1.2,
        ]);

        $response = $this->reports($admin);

        $profit = $response->viewData('profit');
        $daily = $response->viewData('dailySummary');
        $byAgent = $response->viewData('byAgent');

        // Fee: 2.50 at seven plus 3.00 at 7.4. Margin: 1.50 at seven plus 1.20 at 7.4.
        $this->assertEqualsWithDelta(39.7, (float) $profit['fee_rmb'], 0.01);
        $this->assertEqualsWithDelta(19.38, (float) $profit['fx_rmb'], 0.01);

        $this->assertEqualsWithDelta((float) $profit['fee_rmb'], (float) $daily->sum('fee_rmb'), 0.01);
        $this->assertEqualsWithDelta((float) $profit['fx_rmb'], (float) $daily->sum('fx_rmb'), 0.01);
        $this->assertEqualsWithDelta((float) $profit['fee_rmb'], (float) $byAgent->sum('fee_rmb'), 0.01);
        $this->assertEqualsWithDelta((float) $profit['fx_rmb'], (float) $byAgent->sum('fx_rmb'), 0.01);

        // Once in each table's row and once in each table's total line.
        $this->assertSame(4, substr_count($response->getContent(), '>59.08<'));
    }

    public function test_the_page_holds_one_daily_table_and_one_agent_table(): void
    {
        $admin = $this->user('Gorret Admin', 'admin');
        $this->transfer($admin);

        $html = $this->reports($admin)->getContent();

        foreach ([
            '<h3 class="text-lg font-semibold text-gray-900">Daily Summary</h3>',
            '<h3 class="text-lg font-semibold text-gray-900">Performance by Agent</h3>',
            '<div id="section-daily"',
            '<div id="section-agent"',
        ] as $marker) {
            $this->assertSame(1, substr_count($html, $marker), "Expected [$marker] exactly once.");
        }
    }

    public function test_a_desk_without_admin_sees_no_profit_columns_and_no_rate_report(): void
    {
        $agent = $this->user('Sarah Nalin', 'agent');
        $this->transfer($agent);

        $html = $this->reports($agent)->getContent();

        $this->assertStringContainsString('Revenue (USD)', $html);
        $this->assertStringNotContainsString('Margin (UGX)', $html);
        $this->assertStringNotContainsString('Rates Booked in This Period', $html);
        $this->assertStringContainsString('My Commission', $html);
    }

    public function test_the_customer_report_ranks_senders_by_the_dollars_they_moved(): void
    {
        $admin = $this->user('Gorret Admin', 'admin');
        $this->transfer($admin, ['sender_name' => 'Asuma Aggrey']);
        $this->transfer($admin, ['sender_name' => 'Asuma Aggrey']);
        $this->transfer($admin, ['sender_name' => 'Kato Musa', 'amount' => 900, 'total_usd_equivalent' => 900, 'rmb_amount' => 6300]);

        $response = $this->reports($admin);
        $byCustomer = $response->viewData('byCustomer');

        $this->assertSame(['Kato Musa', 'Asuma Aggrey'], $byCustomer->pluck('sender_name')->all());
        $this->assertSame(2, (int) $byCustomer[1]->count);
        $this->assertEqualsWithDelta(3500.0, (float) $byCustomer[1]->rmb_delivered, 0.01);
        $this->assertEqualsWithDelta(18800.0, (float) $byCustomer[1]->fee_ugx, 0.01);

        $html = $response->getContent();
        $this->assertStringContainsString('Customer Activity', $html);
        $this->assertStringContainsString('Kato Musa', $html);
        // Kato moved 900 dollars and paid two charges of 9,400 shillings, so 18,800 is his line.
        $this->assertStringContainsString('18,800', $html);
    }

    public function test_an_agent_sees_only_their_own_senders(): void
    {
        $agent = $this->user('Sarah Nalin', 'agent');
        $other = $this->user('Peter Okello', 'agent');

        $this->transfer($agent, ['sender_name' => 'Asuma Aggrey']);
        $this->transfer($other, ['sender_name' => 'Kato Musa']);

        $html = $this->reports($agent)->getContent();

        $this->assertStringContainsString('Asuma Aggrey', $html);
        $this->assertStringNotContainsString('Kato Musa', $html);
    }

    public function test_the_rate_report_shows_which_rate_the_business_was_booked_at(): void
    {
        $admin = $this->user('Gorret Admin', 'admin');

        // Two transfers at seven, then the sheet moves to 7.2 and one more is booked there.
        $this->transfer($admin);
        $this->transfer($admin);
        ExchangeRate::setPair('USD', 'RMB', 7.2);
        $this->transfer($admin, ['exchange_rate' => 7.2]);

        $response = $this->reports($admin);
        $byRate = $response->viewData('byRate');

        $this->assertCount(2, $byRate);
        $this->assertEqualsWithDelta(7.2, (float) $byRate[0]->exchange_rate, 0.0001);
        $this->assertTrue((bool) $byRate[0]->is_current);
        $this->assertSame(1, (int) $byRate[0]->count);
        $this->assertFalse((bool) $byRate[1]->is_current);
        $this->assertSame(2, (int) $byRate[1]->count);

        $html = $response->getContent();
        $this->assertStringContainsString('Rates Booked in This Period', $html);
        $this->assertStringContainsString('Current', $html);
        $this->assertStringContainsString('Superseded', $html);
    }
}
