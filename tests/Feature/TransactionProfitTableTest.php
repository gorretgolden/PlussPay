<?php

namespace Tests\Feature;

use App\Models\ExchangeRate;
use App\Models\Setting;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * One profit figure per transfer, on the booking screen, for the roles that own the book. The
 * office stopped wanting the dollar and yuan cards separately: what the rates came to is one
 * number, the charge is what the customer was billed, and the two added is the profit reported.
 * A cashier needs the charge the customer paid, not what the business made on it.
 */
class TransactionProfitTableTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['superadmin', 'admin', 'agent', 'staff', 'accountant'] as $role) {
            Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        }

        ExchangeRate::setPair('USD', 'UGX', 3760, 3750, 3760);
        ExchangeRate::setPair('USD', 'RMB', 7.24, 7.24, 7.24);

        Setting::set('service_fee_ugx', '2000', 'float', 'transaction');
        Setting::set('service_fee_usd', '100', 'float', 'transaction');
    }

    private function user(string $role): User
    {
        $user = User::create([
            'name' => 'Table '.ucfirst($role),
            'email' => strtolower($role).uniqid().'@plussave.test',
            'password' => 'Password123',
            'is_active' => true,
        ]);
        $user->assignRole($role);

        return $user;
    }

    /**
     * UGX 3,780,000 handed over with a UGX 20,000 charge, so UGX 3,760,000 travels and the
     * customer is credited exactly $1,000. The shillings are sold at 3,760 and cost 3,750, so
     * the exchange profit is UGX 10,000 and the overall profit UGX 30,000.
     */
    private function book(string $cash = '3780000', string $charge = '20000'): Transaction
    {
        $admin = $this->user('admin');

        $this->actingAs($admin)->get('/transactions/create');
        $this->actingAs($admin)->post('/transactions', [
            'form_id' => session('transaction_form_id'),
            'agent_id' => 'counter',
            'sender_name' => 'Table Sender',
            'receiver_name' => 'Table Receiver',
            'currency' => 'UGX',
            'amount' => $cash,
            'charge_ugx' => $charge,
        ])->assertSessionHasNoErrors();

        return Transaction::latest('id')->firstOrFail();
    }

    public function test_the_book_shows_the_exchange_profit_and_the_overall_profit(): void
    {
        $this->book();

        $html = $this->actingAs($this->user('admin'))->get('/transactions')->getContent();

        foreach (['Money Travels', 'Charge (UGX)', 'Exchange Profit', 'Overall Profit'] as $header) {
            $this->assertStringContainsString($header, $html);
        }

        // The charge the customer was billed, what the rates earned, and the two added.
        $this->assertStringContainsString('UGX 20,000', $html);
        $this->assertStringContainsString('UGX 10,000.0', $html);
        $this->assertStringContainsString('UGX 30,000.0', $html);

        // The per-leg cards the client removed have no home on this page any more.
        foreach (['Profit UGX to USD', 'Profit USD to RMB', 'Yuan Leg', 'Fee (UGX)'] as $gone) {
            $this->assertStringNotContainsString($gone, $html);
        }
    }

    public function test_no_row_offers_a_profit_breakdown(): void
    {
        $transaction = $this->book();

        $this->actingAs($this->user('admin'))->get('/transactions')
            ->assertDontSee('View profit breakdown');

        // The model no longer has the method the dialog was built from.
        $this->assertFalse(method_exists($transaction, 'profitBreakdown'));
        $this->assertSame(['exchange', 'charge', 'total'], array_keys($transaction->profitLabels()));
    }

    public function test_a_transfer_that_lost_yuan_shows_one_loss_not_two_legs(): void
    {
        // Yuan bought from China at 7.00 but handed over at 7.30: every dollar sent loses yuan.
        ExchangeRate::setPair('USD', 'RMB', 7.30, 7.00, 7.30);
        $this->book();

        $transaction = Transaction::latest('id')->firstOrFail();

        // 1,000 dollars x (7.00 - 7.30) = 300 yuan short, worth 160,714.29 shillings at the
        // buying rate, which is more than the shilling margin and the charge together.
        $this->assertEqualsWithDelta(-160714.29, (float) $transaction->usd_rmb_profit_ugx, 0.01);
        $labels = $transaction->profitLabels();
        $this->assertSame('Loss UGX 150,714.3', $labels['exchange']['text']);
        $this->assertSame('loss', $labels['exchange']['tone']);
        $this->assertSame('UGX 20,000', $labels['charge']['text']);
        $this->assertSame('Loss UGX 130,714.3', $labels['total']['text']);

        $html = $this->actingAs($this->user('admin'))->get('/transactions')->getContent();
        $this->assertStringContainsString('Loss UGX 150,714.3', $html);
        $this->assertStringContainsString('Loss UGX 130,714.3', $html);
        $this->assertStringContainsString('text-red-600', $html);
        // The yuan leg on its own is never printed.
        $this->assertStringNotContainsString('Loss UGX 160,714.3', $html);
    }

    public function test_cashier_and_agent_see_the_charge_but_nothing_about_profit(): void
    {
        $this->book();

        foreach (['staff', 'agent'] as $role) {
            $html = $this->actingAs($this->user($role))->get('/transactions')->getContent();

            $this->assertStringContainsString('Charge (UGX)', $html);
            $this->assertStringNotContainsString('Overall Profit', $html);
            $this->assertStringNotContainsString('Exchange Profit', $html);
            $this->assertStringNotContainsString('UGX 30,000.0', $html);
        }
    }

    public function test_a_transfer_booked_before_the_rates_were_stored_says_n_a(): void
    {
        $legacy = $this->book();
        // The row a transfer from before the snapshots looks like: the profit columns that
        // exist carry their old default of zero, and the rates beside them were never written.
        $legacy->forceFill([
            'rate_buy_ugx' => null,
            'rate_sell_ugx' => null,
            'fx_profit_ugx' => 0,
            'rmb_buying_rate' => null,
            'usd_rmb_profit_cny' => null,
            'usd_rmb_profit_ugx' => null,
            'total_profit_ugx' => null,
        ])->save();

        $labels = $legacy->fresh()->profitLabels();
        $this->assertSame('n/a', $labels['exchange']['text']);
        $this->assertSame('n/a', $labels['total']['text']);
        $this->assertSame('muted', $labels['exchange']['tone']);
        // The charge was always known, so it keeps showing.
        $this->assertSame('UGX 20,000', $labels['charge']['text']);

        $html = $this->actingAs($this->user('admin'))->get('/transactions')->getContent();

        $this->assertStringContainsString('n/a', $html);
        $this->assertStringNotContainsString('UGX 30,000.0', $html);
    }

    public function test_a_cancelled_transfer_earns_nothing(): void
    {
        $cancelled = $this->book();
        $cancelled->forceFill(['status' => 'cancelled'])->save();

        $labels = $cancelled->profitLabels();

        $this->assertSame('UGX 0.0', $labels['total']['text']);
        $this->assertSame('muted', $labels['total']['tone']);
        $this->assertSame('UGX 0', $labels['charge']['text']);

        $html = $this->actingAs($this->user('admin'))->get('/transactions')->getContent();
        $this->assertStringNotContainsString('UGX 30,000.0', $html);
        $this->assertStringContainsString('Only a completed transfer counts toward profit', $html);
    }

    public function test_the_downloaded_book_carries_the_three_profit_figures(): void
    {
        ExchangeRate::setPair('USD', 'RMB', 7.30, 7.00, 7.30);
        $this->book();

        $rows = $this->sheet($this->actingAs($this->user('admin'))
            ->get('/export/transactions')
            ->streamedContent());

        foreach (['Money Handed Over', 'Charge (UGX)', 'Dollar Selling Price (per USD)',
            'Yuan Selling Price (per USD)', 'Exchange Profit (UGX)',
            'Overall Profit (UGX)', 'Overall Profit (USD)'] as $header) {
            $this->assertContains($header, $rows[5]);
        }

        // The customer handed over the full UGX 3,780,000 and UGX 3,760,000 of it travelled,
        // both as flat shillings because that is how they were counted out on the counter.
        $this->assertSame('UGX 3,780,000', $rows[6][6]);
        $this->assertSame('UGX 3,760,000', $rows[6][7]);
        $this->assertSame('UGX 20,000', $rows[6][10]);

        // The two selling prices the money went out at. The buying prices are not columns at all:
        // the book quotes and pays on the selling side, so that is what a download carries.
        $this->assertSame('3760', $rows[6][12]);
        $this->assertSame('7.3', $rows[6][13]);

        $this->assertSame('Loss UGX 150,714.3', $rows[6][14]);
        $this->assertSame('Loss UGX 130,714.3', $rows[6][15]);
        // The same loss in dollars, at the 7.30 those dollars were delivered at.
        $this->assertMatchesRegularExpression('/^Loss USD \d/', $rows[6][16]);
        $this->assertSame('Completed', $rows[6][18]);

        foreach (['Yuan Leg (CNY)', 'Yuan Leg (UGX)', 'Yuan Leg (USD)', 'Dollar Margin (UGX)',
            'UGX Bought At (per USD)', 'RMB Bought At (per USD)'] as $gone) {
            $this->assertNotContains($gone, $rows[5]);
        }
    }

    public function test_a_transfer_without_stored_rates_downloads_as_n_a(): void
    {
        $legacy = $this->book();
        $legacy->forceFill([
            'rate_buy_ugx' => null,
            'rate_sell_ugx' => null,
            'fx_profit_ugx' => 0,
            'rmb_buying_rate' => null,
            'usd_rmb_profit_cny' => null,
            'usd_rmb_profit_ugx' => null,
            'total_profit_ugx' => null,
        ])->save();

        $rows = $this->sheet($this->actingAs($this->user('admin'))
            ->get('/export/transactions')
            ->streamedContent());

        // The charge the customer paid is still known, so it downloads as a figure. Everything
        // built from the missing rates says n/a rather than pretending to be zero. The yuan
        // selling price survives on the row, so it is the only rate still printed.
        $this->assertSame('UGX 20,000', $rows[6][10]);
        $this->assertSame('n/a', $rows[6][12]);
        $this->assertSame('7.24', $rows[6][13]);
        foreach ([14, 15, 16] as $column) {
            $this->assertSame('n/a', $rows[6][$column]);
        }
    }

    /**
     * Read the downloaded workbook back as a grid of cell text, keyed by one-based row number,
     * because what matters is which column each figure lands in.
     *
     * @return array<int, array<int, string>>
     */
    private function sheet(string $bytes): array
    {
        $path = storage_path('app/profit-sheet-check.xlsx');
        file_put_contents($path, $bytes);
        $sheet = IOFactory::load($path)->getActiveSheet();
        unlink($path);

        $rows = [];
        foreach (range(1, 7) as $row) {
            foreach (range('A', 'V') as $column) {
                $rows[$row][] = (string) $sheet->getCell($column.$row)->getValue();
            }
        }

        return $rows;
    }
}
