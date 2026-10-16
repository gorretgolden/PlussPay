<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\AccountEntry;
use App\Models\ExchangeRate;
use App\Models\Setting;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class FxRateAndProfitTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['superadmin', 'admin', 'agent', 'staff'] as $role) {
            Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        }

        ExchangeRate::create(['from_currency' => 'USD', 'to_currency' => 'RMB', 'rate' => 7.24, 'is_active' => true]);
        ExchangeRate::create(['from_currency' => 'USD', 'to_currency' => 'UGX', 'rate' => 3760, 'buy_rate' => 3750, 'sell_rate' => 3760, 'is_active' => true]);

        Setting::set('service_fee_ugx', '2000', 'float', 'transaction');
        Setting::set('service_fee_usd', '100', 'float', 'transaction');
    }

    private function admin(): User
    {
        $user = User::create([
            'name' => 'Rate Admin',
            'email' => 'rates'.uniqid().'@plussave.test',
            'password' => 'Password123',
            'is_active' => true,
        ]);

        $user->assignRole('admin');

        return $user;
    }

    public function test_settings_save_keeps_the_selling_rate_as_the_conversion_rate(): void
    {
        $this->actingAs($this->admin())
            ->put('/settings', [
                'usd_ugx_buying' => '3748',
                'usd_ugx_selling' => '3772',
                'usd_rmb_buying' => '6.70',
                'usd_rmb_selling' => '6.75',
            ])
            ->assertSessionHasNoErrors();

        $row = ExchangeRate::getActiveRate('USD', 'UGX');

        // Everything else in the app converts with `rate`, so it must stay the customer-facing price.
        $this->assertSame('3772.000000', (string) $row->rate);
        $this->assertSame('3748.000000', (string) $row->buy_rate);
        $this->assertSame('3772.000000', (string) $row->sell_rate);
        $this->assertSame(['buy' => 3748.0, 'sell' => 3772.0], ExchangeRate::usdUgxPair());

        $rmb = ExchangeRate::getActiveRate('USD', 'RMB');
        $this->assertSame('6.750000', (string) $rmb->rate);
        $this->assertSame('6.700000', (string) $rmb->buy_rate);
    }

    public function test_transaction_prices_the_charge_the_operator_typed_and_the_rest_that_travels(): void
    {
        // 3,780,000 handed over with 20,000 taken out of it leaves 3,760,000, which at the selling
        // rate of 3760 is exactly $1,000.0, and those dollars cost us 3750 each.
        $transaction = $this->storeUgxTransfer('3780000', '20000');

        $this->assertSame('3760000.00', (string) $transaction->amount);
        $this->assertSame('1000.00', (string) $transaction->total_usd_equivalent);
        $this->assertSame('7240.00', (string) $transaction->rmb_amount);
        $this->assertSame('3750.000000', (string) $transaction->rate_buy_ugx);
        $this->assertSame('3760.000000', (string) $transaction->rate_sell_ugx);

        // One exchange profit in shillings, being the 3,760,000 taken in less those dollars at our
        // own buying price. The yuan leg is nothing here because the pair has no yuan spread.
        $this->assertSame('10000.00', (string) $transaction->fx_profit_ugx);
        $this->assertSame('2.66', (string) $transaction->fx_profit_usd);
        $this->assertSame('0.000000', (string) $transaction->usd_rmb_profit_ugx);

        // The charge stays exactly as entered, in shillings, and the overall profit is the two.
        $this->assertSame('20000.00', (string) $transaction->charge_ugx);
        $this->assertSame('5.32', (string) $transaction->charge_amount);
        $this->assertSame('30000.000000', (string) $transaction->total_profit_ugx);
    }

    public function test_an_agent_books_the_transfer_and_types_the_charge_themselves(): void
    {
        $agent = User::create([
            'name' => 'Desk Agent',
            'email' => 'deskagent' . uniqid() . '@plussave.test',
            'password' => 'Password123',
            'is_active' => true,
        ]);
        $agent->assignRole('agent');

        $html = $this->actingAs($agent)->get('/transactions/create')->getContent();

        // The charge box is theirs to fill from an empty start. There is no rate card behind it,
        // so the field carries its own short line and the tooltip says what the figure is.
        $this->assertStringContainsString('You agree this per customer, so nothing is filled in for you. Type what you kept, or 0 when nothing was taken.', $html);
        $this->assertStringContainsString('Type what you kept from this customer.', $html);
        $this->assertStringContainsString('name="charge_ugx"', $html);
        $this->assertStringNotContainsString('name="charge_ugx" readonly', $html);
        $this->assertStringNotContainsString('name="charge_ugx" disabled', $html);

        // 3,772,500 handed over with 12,500 typed by hand leaves 3,760,000, which is exactly
        // $1,000.0 at the selling rate. The 12,500 is what the desk agreed with the customer.
        $this->actingAs($agent)->post('/transactions', [
            'form_id' => session('transaction_form_id'),
            'sender_name' => 'Agent Sender',
            'receiver_name' => 'Agent Receiver',
            'currency' => 'UGX',
            'amount' => '3772500',
            'charge_ugx' => '12500',
        ])->assertSessionHasNoErrors();

        $transaction = Transaction::latest('id')->firstOrFail();

        $this->assertSame($agent->id, $transaction->created_by);
        $this->assertSame('12500.00', (string) $transaction->charge_ugx);
        $this->assertSame('3760000.00', (string) $transaction->amount);
        $this->assertSame('1000.00', (string) $transaction->total_usd_equivalent);
        $this->assertSame('3.32', (string) $transaction->charge_amount);
        $this->assertSame('22500.000000', (string) $transaction->total_profit_ugx);

        // The agent's own commission is the figure they typed, not one worked out for them. It sits
        // in the wallet beside the shillings they are holding for the office, so the two lines add
        // back to the cash that actually left the customer's hand.
        $wallet = Account::walletFor($agent, 'UGX');
        $this->assertSame('12500.00', (string) AccountEntry::where('account_id', $wallet->id)
            ->where('type', 'agent_fee')
            ->value('amount'));
        $this->assertSame('3772500.00', $wallet->balance());
    }

    public function test_the_booking_form_labels_the_money_differently_for_each_flow(): void
    {
        $html = $this->actingAs($this->admin())->get('/transactions/create')->getContent();

        // Shillings are entered whole with the charge inside them; dollars all travel and the
        // charge is collected on top, so the same box cannot carry one sentence for both. The
        // line under the field stays short and the working lives in the tooltip.
        $this->assertStringContainsString('Total received, charge included.', $html);
        $this->assertStringContainsString("'All of these dollars travel.'", $html);
        $this->assertStringContainsString('the charge is collected on top in shillings.', $html);
        $this->assertStringContainsString('id="cash_help"', $html);
    }

    public function test_a_charge_that_leaves_nothing_to_send_is_refused(): void
    {
        $this->actingAs($this->admin())->get('/transactions/create');

        $this->actingAs($this->admin())->post('/transactions', [
            'form_id' => session('transaction_form_id'),
            'sender_name' => 'Test Sender',
            'receiver_name' => 'Test Receiver',
            'currency' => 'UGX',
            'amount' => '3780000',
            'charge_ugx' => '3780000',
        ])->assertSessionHasErrors('charge_ugx');

        $this->assertSame(0, Transaction::count());
    }

    public function test_the_exchange_profit_is_zero_when_the_pair_has_no_spread(): void
    {
        // One rate only on the sheet, so dollars are as cheap to buy as they are to sell and the
        // only thing the transfer earns is the charge.
        ExchangeRate::setPair('USD', 'UGX', 3700);

        $transaction = $this->storeUgxTransfer('3720000', '20000');

        $this->assertSame('3700.000000', (string) $transaction->rate_buy_ugx);
        $this->assertSame('3700.000000', (string) $transaction->rate_sell_ugx);
        $this->assertSame('1000.00', (string) $transaction->total_usd_equivalent);
        $this->assertSame('0.00', (string) $transaction->fx_profit_ugx);
        $this->assertSame('0.00', (string) $transaction->fx_profit_usd);
        $this->assertSame('0.000000', (string) $transaction->usd_rmb_profit_ugx);
        $this->assertSame('20000.000000', (string) $transaction->total_profit_ugx);
    }

    public function test_the_profit_report_shows_the_charge_the_exchange_and_the_overall(): void
    {
        $transaction = $this->storeUgxTransfer('3780000', '20000');

        // 20,000 of charge plus 10,000 of exchange is 30,000 overall, on 1,000 dollars sent. The
        // charge is whole shillings; the exchange, the overall and the per-dollar figure carry one
        // decimal each.
        $this->actingAs($transaction->creator)
            ->get('/reports?type=profit')
            ->assertOk()
            ->assertSee('Profit on One Dollar Sent')
            ->assertSee('UGX 30,000.0')
            ->assertSee('UGX 20,000')
            ->assertSee('UGX 10,000.0')
            ->assertSee('UGX 30.0');
    }

    public function test_the_profit_report_states_the_overall_in_yuan_beside_the_shillings(): void
    {
        $transaction = $this->storeUgxTransfer('3780000', '20000');

        // Those 1,000 dollars were delivered as 7,240 yuan, so each dollar of profit is worth 7.24:
        // the charge is $5.32 = 38.52 and the exchange $2.66 = 19.26, which is 57.78 overall. The
        // yuan rides on the overall card instead of having a row of cards of its own.
        $this->actingAs($transaction->creator)
            ->get('/reports?type=profit')
            ->assertOk()
            ->assertSee('&yen;57.8', false)
            ->assertSee('USD 8.0', false)
            ->assertDontSee('Profit in yuan', false);
    }

    public function test_agents_never_see_the_profit_report(): void
    {
        $this->storeUgxTransfer('3780000', '20000');

        $agent = User::create([
            'name' => 'Field Agent',
            'email' => 'agent'.uniqid().'@plussave.test',
            'password' => 'Password123',
            'is_active' => true,
        ]);
        $agent->assignRole('agent');

        $this->actingAs($agent)
            ->get('/reports?type=profit')
            ->assertOk()
            ->assertDontSee('Foreign Exchange Profit')
            ->assertDontSee('Profit on One Dollar Sent');

        $this->actingAs($agent)->get('/export/profit-report')->assertForbidden();
    }

    public function test_profit_report_exports_as_excel(): void
    {
        $transaction = $this->storeUgxTransfer('3780000', '20000');

        $this->actingAs($transaction->creator)
            ->get('/export/profit-report')
            ->assertOk();

        $this->assertDatabaseHas('activity_logs', [
            'user_id' => $transaction->created_by,
            'action' => 'exported',
        ]);
    }

    public function test_the_profit_export_carries_the_three_profit_figures(): void
    {
        $this->storeUgxTransfer('3780000', '20000');
        $cells = $this->profitText();

        // The boss reads the profit in the yuan it was delivered as, so the file he downloads
        // carries it the same way the page does: charge 20,000 whole shillings, exchange 10,000.0
        // and overall 30,000.0, which is 8.0 dollars or 57.8 yuan.
        foreach ([
            'Charge (UGX)', 'Exchange Profit (UGX)', 'Overall Profit (UGX)', 'Overall Profit (USD)',
            'Overall Profit (RMB)', 'Transfers With No Rates',
            '20,000', '10,000.0', '30,000.0', '8.0', '57.8',
        ] as $needle) {
            $this->assertStringContainsString($needle, $cells);
        }

        // One line per figure, no per-leg columns left behind.
        foreach (['Yuan Leg', 'Dollar Margin', 'Fee Income'] as $gone) {
            $this->assertStringNotContainsString($gone, $cells);
        }
    }

    public function test_the_profit_export_folds_a_reversed_yuan_spread_into_one_exchange_figure(): void
    {
        // Yuan bought in at 7.00 but handed to the receiver at 7.30, so every dollar sent loses
        // 30 fen. The day line carries that inside the exchange profit instead of on its own.
        ExchangeRate::create(['from_currency' => 'USD', 'to_currency' => 'RMB', 'rate' => 7.30, 'buy_rate' => 7.00, 'sell_rate' => 7.30, 'is_active' => true]);
        ExchangeRate::where('from_currency', 'USD')->where('to_currency', 'RMB')->where('rate', 7.24)->delete();

        $transaction = $this->storeUgxTransfer('3780000', '20000');
        $this->assertSame('10000.00', (string) $transaction->fx_profit_ugx);
        $this->assertSame('-160714.290000', (string) $transaction->usd_rmb_profit_ugx);
        $this->assertEqualsWithDelta(-300, (float) $transaction->usd_rmb_profit_cny, 0.01);

        $sheet = $this->profitSheet();
        $line = fn (int $row) => array_map(fn ($c) => (string) $sheet->getCell($c.$row)->getValue(), range('A', 'I'));
        $day = $line(6);
        $total = $line(7);

        // charge 20,000 whole shillings + exchange 150,714.3 lost less the yuan leg already
        // folded in, so the day line reads one loss to one decimal
        $this->assertSame('20,000', $day[3]);
        $this->assertSame('-150,714.3', $day[4]);
        $this->assertSame('-130,714.3', $day[5]);
        $this->assertSame('-33.1', $day[6]);
        $this->assertSame('-241.7', $day[7]);
        $this->assertSame('0', $day[8]);
        $this->assertSame('TOTAL', $total[0]);
        // One transfer in the period, so the range total repeats the day line exactly.
        $this->assertSame(array_slice($day, 1), array_slice($total, 1));
    }

    /**
     * The profit workbook for the period, downloaded through the same route the Export Excel
     * button uses, as the boss's own admin account.
     */
    private function profitSheet(): \PhpOffice\PhpSpreadsheet\Worksheet\Worksheet
    {
        $response = $this->actingAs($this->admin())->get('/export/profit-report');
        $this->assertSame(200, $response->getStatusCode());

        $path = storage_path('app/profit-export-check.xlsx');
        file_put_contents($path, $response->streamedContent());
        $sheet = IOFactory::load($path)->getActiveSheet();
        unlink($path);

        return $sheet;
    }

    /**
     * The text of the header and the two money lines, rows 5 to 7 of the profit workbook.
     */
    private function profitText(): string
    {
        $sheet = $this->profitSheet();
        $cells = [];

        foreach (range('A', 'I') as $column) {
            foreach ([5, 6, 7] as $row) {
                $cells[] = (string) $sheet->getCell($column.$row)->getValue();
            }
        }

        return implode('|', $cells);
    }

    private function storeUgxTransfer(string $cashHandedOver, string $charge): Transaction
    {
        $admin = $this->admin();

        $this->actingAs($admin)->get('/transactions/create');

        $this->actingAs($admin)->post('/transactions', [
            'form_id' => session('transaction_form_id'),
            'sender_name' => 'Test Sender',
            'receiver_name' => 'Test Receiver',
            'currency' => 'UGX',
            'amount' => $cashHandedOver,
            'charge_ugx' => $charge,
        ])->assertSessionHasNoErrors();

        return Transaction::latest('id')->firstOrFail();
    }
}
