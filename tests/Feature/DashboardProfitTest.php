<?php

namespace Tests\Feature;

use App\Models\ExchangeRate;
use App\Models\Setting;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class DashboardProfitTest extends TestCase
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
            'name' => 'Board '.ucfirst($role),
            'email' => strtolower($role).uniqid().'@plussave.test',
            'password' => 'Password123',
            'is_active' => true,
        ]);
        $user->assignRole($role);

        return $user;
    }

    private function book(User $booker, string $amount = '3760000'): Transaction
    {
        $this->actingAs($booker)->get('/transactions/create');
        $this->actingAs($booker)->post('/transactions', [
            'form_id' => session('transaction_form_id'),
            'sender_name' => 'Board Sender',
            'receiver_name' => 'Board Receiver',
            'currency' => 'UGX',
            'amount' => $amount,
        ])->assertSessionHasNoErrors();

        return Transaction::latest('id')->firstOrFail();
    }

    public function test_the_board_splits_the_fee_from_the_currency_profit(): void
    {
        // $1,000 sent: UGX 20,000 fee, UGX 10,000 on the shillings, flat yuan pair.
        $this->book($this->user('admin'));

        $html = $this->actingAs($this->user('admin'))->get('/dashboard')->getContent();

        foreach (['Service Fees', 'Exchange Profit', 'Total Profit', 'Today', 'This Month', 'All Time'] as $needle) {
            $this->assertStringContainsString($needle, $html);
        }

        $this->assertStringContainsString('UGX 20,000.00', $html);
        $this->assertStringContainsString('UGX 10,000.00', $html);
        $this->assertStringContainsString('UGX 30,000.00', $html);

        // The old name promised the whole earning, which it never was.
        $this->assertStringNotContainsString('Revenue Today', $html);
        $this->assertStringNotContainsString('Total Revenue', $html);
    }

    public function test_a_yuan_loss_shows_against_the_day_it_happened(): void
    {
        $this->book($this->user('admin'));

        // The yuan pair reverses for the second transfer: 1,000 dollars x (7.00 - 7.30).
        ExchangeRate::setPair('USD', 'RMB', 7.30, 7.00, 7.30);
        $this->book($this->user('admin'));

        $html = $this->actingAs($this->user('admin'))->get('/dashboard')->getContent();

        // 40,000 of fees, 20,000 of shilling margin, and 300 yuan short on the second
        // transfer, worth 160,714.29 shillings at the buying rate.
        $this->assertStringContainsString('Loss UGX 140,714.29', $html);
        $this->assertStringContainsString('Loss UGX 100,714.29', $html);
    }

    public function test_transfers_without_rates_are_counted_not_silently_zeroed(): void
    {
        $legacy = $this->book($this->user('admin'));
        $legacy->forceFill(['rate_buy_ugx' => null, 'fx_profit_ugx' => 0, 'total_profit_ugx' => null])->save();

        $html = $this->actingAs($this->user('admin'))->get('/dashboard')->getContent();

        $this->assertStringContainsString('1 completed transfer has no rates stored', $html);
    }

    public function test_the_profit_series_stays_off_the_page_for_a_cashier(): void
    {
        $this->book($this->user('admin'));

        foreach (['staff', 'agent'] as $role) {
            $html = $this->actingAs($this->user($role))->get('/dashboard')->getContent();

            $this->assertStringNotContainsString('Exchange Profit', $html);
            $this->assertStringNotContainsString('Total Profit', $html);
            $this->assertStringNotContainsString('margin_ugx', $html);
            $this->assertStringNotContainsString('yuan_ugx', $html);
            // Their own fee figure stays where it was.
            $this->assertStringContainsString('Service Fees Today', $html);
        }
    }
}
