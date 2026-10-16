<?php

namespace Tests\Feature;

use App\Models\ExchangeRate;
use App\Models\Setting;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * The board reports one profit per transfer: the charge the office kept and what the rates came
 * to on the money that travelled, added together in shillings. The legs behind the exchange
 * figure are kept in the books but never printed on their own.
 */
class DashboardProfitTest extends TestCase
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
            'name' => 'Board '.ucfirst($role),
            'email' => strtolower($role).uniqid().'@plussave.test',
            'password' => 'Password123',
            'is_active' => true,
        ]);
        $user->assignRole($role);

        return $user;
    }

    /**
     * UGX 3,780,000 handed over, UGX 20,000 charge, UGX 3,760,000 travelling as $1,000 at the
     * 3,760 selling price, which cost the office 3,750 apiece.
     */
    private function book(User $booker, string $cash = '3780000', string $charge = '20000'): Transaction
    {
        $this->actingAs($booker)->get('/transactions/create');
        $this->actingAs($booker)->post('/transactions', [
            'form_id' => session('transaction_form_id'),
            'sender_name' => 'Board Sender',
            'receiver_name' => 'Board Receiver',
            'currency' => 'UGX',
            'amount' => $cash,
            'charge_ugx' => $charge,
        ])->assertSessionHasNoErrors();

        return Transaction::latest('id')->firstOrFail();
    }

    public function test_the_board_shows_the_charge_and_one_overall_profit(): void
    {
        $this->book($this->user('admin'));

        $html = $this->actingAs($this->user('admin'))->get('/dashboard')->getContent();

        foreach (['Charge', 'FX profit', 'Overall profit', 'Today', 'This Month', 'All Time'] as $needle) {
            $this->assertStringContainsString($needle, $html);
        }

        // Each figure is printed once per window and the window is named by a tab, so the labels
        // a cashier used to have to read twice across three blocks are gone.
        foreach (['Service Fees', 'Total Profit', 'Yuan Leg', 'Revenue Today', 'Total Revenue', 'Money Sent (UGX)'] as $gone) {
            $this->assertStringNotContainsString($gone, $html);
        }

        // UGX 20,000 kept, UGX 10,000 from the shilling spread, a flat yuan pair, UGX 30,000 overall.
        $this->assertStringContainsString('UGX 20,000.00', $html);
        $this->assertStringContainsString('UGX 10,000.00', $html);
        $this->assertStringContainsString('UGX 30,000.00', $html);
    }

    public function test_a_yuan_loss_shows_against_the_day_it_happened(): void
    {
        $this->book($this->user('admin'));

        // The yuan pair reverses for the second transfer: 1,000 dollars x (7.00 - 7.30).
        ExchangeRate::setPair('USD', 'RMB', 7.30, 7.00, 7.30);
        $this->book($this->user('admin'));

        $html = $this->actingAs($this->user('admin'))->get('/dashboard')->getContent();

        // 40,000 of charges and 20,000 of shilling spread, against 160,714.29 lost on the yuan
        // of the second transfer: the exchange figure is 140,714.29 short and the overall 100,714.29.
        $this->assertStringContainsString('UGX 40,000.00', $html);
        $this->assertStringContainsString('Loss UGX 140,714.29', $html);
        $this->assertStringContainsString('Loss UGX 100,714.29', $html);
    }

    public function test_transfers_without_rates_are_counted_not_silently_zeroed(): void
    {
        $legacy = $this->book($this->user('admin'));
        $legacy->forceFill(['rate_buy_ugx' => null, 'fx_profit_ugx' => 0, 'usd_rmb_profit_ugx' => null, 'total_profit_ugx' => null])->save();

        $html = $this->actingAs($this->user('admin'))->get('/dashboard')->getContent();

        // The rule is stated once, in the tooltip beside the heading, and each window that holds
        // the transfer counts it: today sits inside this month, which sits inside all time.
        $this->assertSame(1, substr_count($html, 'Transfers booked before their rates were stored count as 0'));
        $this->assertSame(3, substr_count($html, '>1 without rates<'));
    }

    public function test_the_profit_series_stays_off_the_page_for_a_cashier(): void
    {
        $this->book($this->user('admin'));

        foreach (['staff', 'agent'] as $role) {
            $html = $this->actingAs($this->user($role))->get('/dashboard')->getContent();

            $this->assertStringNotContainsString('FX profit', $html);
            $this->assertStringNotContainsString('Overall profit', $html);
            $this->assertStringNotContainsString('exchange_ugx', $html);
            // Their own charge figure stays where it was.
            $this->assertStringContainsString('Charge', $html);
        }
    }
}
