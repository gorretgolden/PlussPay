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
 * Profit belongs on the booking screen next to the transfer it came from, but only for the
 * two roles that own the book. A cashier needs the fee the customer paid, not the margin the
 * business made on it.
 */
class TransactionProfitTableTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['superadmin', 'admin', 'agent', 'staff'] as $role) {
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

    private function book(string $amount = '3760000'): Transaction
    {
        $admin = $this->user('admin');

        $this->actingAs($admin)->get('/transactions/create');
        $this->actingAs($admin)->post('/transactions', [
            'form_id' => session('transaction_form_id'),
            'sender_name' => 'Table Sender',
            'receiver_name' => 'Table Receiver',
            'currency' => 'UGX',
            'amount' => $amount,
        ])->assertSessionHasNoErrors();

        return Transaction::latest('id')->firstOrFail();
    }

    public function test_the_book_shows_each_profit_figure_for_a_completed_transfer(): void
    {
        // $1,000 sent: the fee is UGX 20,000, the shillings cost 3,750 apiece against the
        // 3,760 sold, and the yuan pair is flat so the yuan leg earns nothing.
        $this->book();

        $html = $this->actingAs($this->user('admin'))->get('/transactions')->getContent();

        foreach (['Fee (UGX)', 'Profit UGX to USD', 'Profit USD to RMB', 'Total Profit'] as $header) {
            $this->assertStringContainsString($header, $html);
        }

        $this->assertStringContainsString('UGX 10,000.00', $html);
        $this->assertStringContainsString('UGX 0.00', $html);
        $this->assertStringContainsString('UGX 30,000.00', $html);
    }

    public function test_the_breakdown_dialog_is_offered_on_every_row(): void
    {
        $this->book();

        $response = $this->actingAs($this->user('admin'))->get('/transactions');

        $response->assertSee('View profit breakdown');

        $steps = Transaction::latest('id')->firstOrFail()->profitBreakdown();
        $labels = array_column($steps, 'label');

        $this->assertContains('Service fee', $labels);
        $this->assertContains('Margin on the shillings', $labels);
        $this->assertContains('Total profit', $labels);
        // The dialog has to name the prices the figure was built from, not just the total.
        $this->assertSame('UGX 3,760.00 per USD = USD 1,000.00', $steps[array_search('Dollars sold to the customer at', $labels)]['value']);
    }

    public function test_a_transfer_that_lost_yuan_is_shown_as_a_loss(): void
    {
        // Yuan bought from China at 7.00 but handed over at 7.30: every dollar sent loses yuan.
        ExchangeRate::setPair('USD', 'RMB', 7.30, 7.00, 7.30);
        $this->book();

        $transaction = Transaction::latest('id')->firstOrFail();

        // 1,000 dollars x (7.00 - 7.30) = 300 yuan short, worth 160,714 shillings at the
        // buying rate, which is more than the fee and the shilling margin together.
        $this->assertLessThan(0, (float) $transaction->usd_rmb_profit_ugx);
        $this->assertSame('Loss UGX 160,714.29', $transaction->profitLabels()['rmb_leg']['text']);
        $this->assertSame('loss', $transaction->profitLabels()['rmb_leg']['tone']);
        $this->assertSame('Loss UGX 130,714.29', $transaction->profitLabels()['total']['text']);

        $html = $this->actingAs($this->user('admin'))->get('/transactions')->getContent();
        $this->assertStringContainsString('Loss UGX 160,714.29', $html);
        $this->assertStringContainsString('text-red-600', $html);
    }

    public function test_cashier_and_agent_see_nothing_about_profit(): void
    {
        $this->book();

        foreach (['staff', 'agent'] as $role) {
            $html = $this->actingAs($this->user($role))->get('/transactions')->getContent();

            $this->assertStringNotContainsString('Total Profit', $html);
            $this->assertStringNotContainsString('Profit UGX to USD', $html);
            $this->assertStringNotContainsString('View profit breakdown', $html);
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
        $this->assertSame('n/a', $labels['ugx_leg']['text']);
        $this->assertSame('n/a', $labels['rmb_leg']['text']);
        $this->assertSame('n/a', $labels['total']['text']);
        // The fee was always known, so it keeps showing.
        $this->assertSame('UGX 20,000.00', $labels['fee']['text']);

        $html = $this->actingAs($this->user('admin'))->get('/transactions')->getContent();

        $this->assertStringContainsString('n/a', $html);
        $this->assertStringNotContainsString('UGX 30,000.00', $html);
    }

    public function test_a_cancelled_transfer_earns_nothing(): void
    {
        $cancelled = $this->book();
        $cancelled->forceFill(['status' => 'cancelled'])->save();

        $labels = $cancelled->profitLabels();

        $this->assertSame('UGX 0.00', $labels['total']['text']);
        $this->assertSame('muted', $labels['total']['tone']);

        $html = $this->actingAs($this->user('admin'))->get('/transactions')->getContent();
        $this->assertStringNotContainsString('UGX 30,000.00', $html);
        $this->assertStringContainsString('Only a completed transfer counts toward profit', $html);
    }

    public function test_the_downloaded_book_carries_every_profit_figure(): void
    {
        ExchangeRate::setPair('USD', 'RMB', 7.30, 7.00, 7.30);
        $this->book();

        $rows = $this->sheet($this->actingAs($this->user('admin'))
            ->get('/export/transactions')
            ->streamedContent());

        foreach (['Dollar Margin (UGX)', 'Yuan Leg (CNY)', 'Yuan Leg (UGX)', 'Yuan Leg (USD)', 'Total Profit (UGX)', 'Total Profit (USD)'] as $header) {
            $this->assertContains($header, $rows[5]);
        }

        // $1,000 sent: fee 20,000, margin 10,000, and 300 yuan short, which is 160,714
        // shillings or 41.10 dollars at the 7.30 those dollars were delivered at.
        $this->assertSame('UGX 10,000.00', $rows[6][11]);
        $this->assertSame('Loss CNY 300.0000', $rows[6][12]);
        $this->assertSame('Loss UGX 160,714.29', $rows[6][13]);
        $this->assertSame('Loss USD 41.10', $rows[6][14]);
        $this->assertSame('Loss UGX 130,714.29', $rows[6][15]);
        $this->assertMatchesRegularExpression('/^Loss USD \d/', $rows[6][16]);
        $this->assertSame('Completed', $rows[6][18]);
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

        // The fee the customer paid is still known, so it downloads as a figure. Everything
        // built from the missing rates says n/a rather than pretending to be zero.
        $this->assertSame('UGX 20,000', $rows[6][9]);
        foreach ([11, 12, 13, 14, 15, 16] as $column) {
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
            foreach (range('A', 'T') as $column) {
                $rows[$row][] = (string) $sheet->getCell($column.$row)->getValue();
            }
        }

        return $rows;
    }
}
