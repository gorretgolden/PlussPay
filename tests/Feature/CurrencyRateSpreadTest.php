<?php

namespace Tests\Feature;

use App\Models\ExchangeRate;
use App\Models\Setting;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class CurrencyRateSpreadTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['superadmin', 'admin'] as $role) {
            Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        }

        Setting::set('service_fee_ugx', '2000', 'float', 'transaction');
        Setting::set('service_fee_usd', '100', 'float', 'transaction');
    }

    private function admin(): User
    {
        $user = User::create([
            'name' => 'Spread Admin',
            'email' => 'spread'.uniqid().'@plussave.test',
            'password' => 'Password123',
            'is_active' => true,
        ]);
        $user->assignRole('admin');

        return $user;
    }

    public function test_a_healthy_pair_shows_no_warning(): void
    {
        ExchangeRate::create(['from_currency' => 'USD', 'to_currency' => 'UGX', 'rate' => 3760, 'buy_rate' => 3750, 'sell_rate' => 3760, 'is_active' => true]);
        ExchangeRate::create(['from_currency' => 'USD', 'to_currency' => 'RMB', 'rate' => 7.20, 'buy_rate' => 7.30, 'sell_rate' => 7.20, 'is_active' => true]);

        $this->actingAs($this->admin())
            ->get('/exchange-rates')
            ->assertOk()
            ->assertDontSee('Spread warning');
    }

    public function test_warns_when_the_yuan_selling_price_is_above_the_buying_price(): void
    {
        ExchangeRate::create(['from_currency' => 'USD', 'to_currency' => 'RMB', 'rate' => 7.30, 'buy_rate' => 7.00, 'sell_rate' => 7.30, 'is_active' => true]);

        $this->actingAs($this->admin())
            ->get('/exchange-rates')
            ->assertOk()
            ->assertSee('USD to RMB: the selling price (7.3) is at or above the buying price (7)');
    }

    public function test_warns_when_the_shilling_selling_price_is_at_or_below_buying(): void
    {
        ExchangeRate::create(['from_currency' => 'USD', 'to_currency' => 'UGX', 'rate' => 3750, 'buy_rate' => 3760, 'sell_rate' => 3750, 'is_active' => true]);

        $this->actingAs($this->admin())
            ->get('/exchange-rates')
            ->assertOk()
            ->assertSee('USD to UGX: the selling price (3750) is at or below the buying price (3760)');
    }

}
