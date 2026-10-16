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

    public function test_the_exchange_profit_folds_both_legs_into_one_figure_everywhere(): void
    {
        $admin = $this->user('Gorret Admin', 'admin');

        // 250 dollars bought in at 7.00 and handed over at 7.40 lose 100 yuan, which cost the
        // business 100 / 7.00 x 3,600 = 51,428.57 shillings. That leg and the margin on the same
        // transfer are one exchange figure now, so nothing on the page names them apart.
        $this->transfer($admin, [
            'fx_profit_ugx' => 1200,
            'fx_profit_usd' => 1.5,
            'rmb_buying_rate' => 7,
            'usd_rmb_profit_cny' => -100,
            'usd_rmb_profit_ugx' => -51428.571429,
            'total_profit_ugx' => -40828.571429,
        ]);
        // A second transfer booked before the yuan rate was snapshotted has no leg at all.
        $this->transfer($admin, ['fx_profit_ugx' => 900, 'fx_profit_usd' => 1.2]);

        $response = $this->reports($admin);
        $html = $response->getContent();

        $profit = $response->viewData('profit');
        $daily = $response->viewData('dailySummary');
        $byAgent = $response->viewData('byAgent');

        // The margin of 1,200 plus 900 against the one yuan leg of 51,429 lost.
        $this->assertEqualsWithDelta(-49328.571429, (float) $profit['exchange_ugx'], 0.01);

        // The tables have to add up to the same figure the card shows, once per table.
        $this->assertEqualsWithDelta((float) $profit['exchange_ugx'], (float) $daily->sum('exchange_ugx'), 0.01);
        $this->assertEqualsWithDelta((float) $profit['exchange_ugx'], (float) $byAgent->sum('exchange_ugx'), 0.01);

        // The charge is its own column and the overall figure is the two added: charges of
        // 18,800 and exchange of 49,328.6 lost leaves the period 30,528.6 in the red.
        $this->assertEqualsWithDelta(18800, (float) $profit['fee_ugx'], 0.01);
        $this->assertEqualsWithDelta(-30528.571429, (float) $profit['total_ugx'], 0.01);
        $this->assertStringContainsString('Loss UGX 30,528.6', $html);
        $this->assertStringContainsString('Loss UGX 49,328.6', $html);
        $this->assertStringContainsString('text-red-600', $html);

        // Both legs stay stored on the row, but no column or card is allowed to name them.
        $this->assertStringNotContainsString('Yuan Leg', $html);
        $this->assertStringNotContainsString('Margin', $html);
        // Once in each table that reports profit in shillings: Daily, Agent and Rate.
        $this->assertSame(3, substr_count($html, 'Exchange Profit (UGX)'));

        // The transfer with no yuan rate is counted, because the totals above are short by it.
        // Both rate gaps are reported as one notice rather than two paragraphs on the same row.
        $this->assertStringContainsString('1 completed transfer was booked before its buying rates were stored', $html);
        $this->assertSame(1, substr_count($html, 'completed transfer was booked before'));
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

    public function test_the_daily_and_agent_tables_carry_the_same_yuan_overall_as_the_cards(): void
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

        // One yuan figure per line now, being the whole overall profit: 2.50 charge plus 1.50 of
        // exchange at seven, and 3.00 plus 1.20 at 7.4.
        $this->assertEqualsWithDelta(59.08, (float) $profit['total_rmb'], 0.01);
        $this->assertEqualsWithDelta((float) $profit['total_rmb'], (float) $daily->sum('total_rmb'), 0.01);
        $this->assertEqualsWithDelta((float) $profit['total_rmb'], (float) $byAgent->sum('total_rmb'), 0.01);

        // The shilling exchange column in both tables adds to the same figure the card shows.
        $this->assertEqualsWithDelta(2100, (float) $profit['exchange_ugx'], 0.01);
        $this->assertEqualsWithDelta(2100, (float) $daily->sum('exchange_ugx'), 0.01);
        $this->assertEqualsWithDelta(2100, (float) $byAgent->sum('exchange_ugx'), 0.01);

        // The yuan overall prints once per table line and once per table total, and once on each
        // of the two cards that state it. One decimal, because it is a computed figure.
        $this->assertSame(4, substr_count($response->getContent(), '>59.1<'));
        $this->assertSame(2, substr_count($response->getContent(), '&yen;59.1'));
    }

    public function test_the_profit_cards_state_shillings_with_the_yuan_beside_the_overall(): void
    {
        $admin = $this->user('Gorret Admin', 'admin');
        $this->transfer($admin, ['fx_profit_ugx' => 1200, 'fx_profit_usd' => 1.5]);
        $this->transfer($admin, [
            'exchange_rate' => 7.4,
            'rmb_amount' => 1850,
            'charge_amount' => 3,
            'fx_profit_ugx' => 900,
            'fx_profit_usd' => 1.2,
        ]);

        $html = $this->reports($admin)->getContent();

        // The client asked for the whole profit on a transfer at once, so there is no second row
        // of yuan cards any more. The yuan he still reads sits on the overall card itself.
        $this->assertSame(1, substr_count($html, 'Profit in shillings'));
        $this->assertStringNotContainsString('Profit in yuan', $html);

        foreach (['Overall Profit', 'Foreign Exchange Profit', 'Profit on One Dollar Sent'] as $heading) {
            $this->assertStringContainsString('>'.$heading.'<', $html);
        }

        // Overall 20,900 shillings, which is 59.1 yuan, made of 18,800 of charge and 2,100 of
        // exchange, and 41.8 shillings for each of the 500 dollars sent. The charge is a whole
        // shilling figure, so it prints without decimals; the computed figures carry one.
        foreach (['UGX 20,900.0', '&yen;59.1', 'UGX 18,800', 'UGX 2,100.0', 'UGX 41.8'] as $figure) {
            $this->assertStringContainsString($figure, $html);
        }
    }

    public function test_a_transfer_page_shows_the_charge_the_exchange_profit_and_the_overall(): void
    {
        $admin = $this->user('Gorret Admin', 'admin');

        // Both legs recorded, so this one transfer has a priced exchange profit: 1,200 of margin
        // less a yuan leg of 51,428.57 is 50,228.57 lost, and the 9,400 charge brings the overall
        // to 40,828.57 lost. All three are shillings, because that is where the money is kept.
        $priced = $this->transfer($admin, [
            'fx_profit_ugx' => 1200,
            'fx_profit_usd' => 1.5,
            'usd_rmb_profit_ugx' => -51428.571429,
            'usd_rmb_profit_cny' => -100,
            'total_profit_ugx' => -40828.571429,
        ]);

        $html = $this->actingAs($admin)
            ->get('/transactions/'.$priced->id)
            ->assertOk()
            ->getContent();

        foreach (['Foreign exchange profit:', 'Charge:', 'Overall profit:'] as $label) {
            $this->assertStringContainsString($label, $html);
        }
        foreach (['Loss UGX 50,228.6', 'UGX 9,400', 'Loss UGX 40,828.6'] as $figure) {
            $this->assertStringContainsString($figure, $html);
        }

        // A transfer booked before the yuan rate was snapshotted leaves the exchange figure as
        // n/a instead of showing only the margin, which would read as a priced result.
        $unpriced = $this->transfer($admin);

        $html = $this->actingAs($admin)
            ->get('/transactions/'.$unpriced->id)
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('n/a', $html);
        $this->assertStringContainsString('No buying rate was recorded on this transfer', $html);
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

        // The desk keeps his own earnings in shillings and nothing else: no exchange figure, no
        // overall, and no view of the rate sheet the office books at. His own commission is on his
        // wallet card rather than in the office table that lists every desk side by side.
        $this->assertStringContainsString('Charge (UGX)', $html);
        $this->assertStringNotContainsString('Foreign Exchange Profit', $html);
        $this->assertStringNotContainsString('Profit on One Dollar Sent', $html);
        $this->assertStringNotContainsString('Rates Booked in This Period', $html);
        $this->assertStringNotContainsString('Commissions Earned', $html);
        $this->assertStringContainsString('Commission kept', $html);
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
        $this->assertEqualsWithDelta(3500.0, (float) $byCustomer[1]->rmb_booked, 0.01);
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
