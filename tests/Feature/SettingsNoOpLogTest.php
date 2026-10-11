<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\ExchangeRate;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class SettingsNoOpLogTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['superadmin', 'admin', 'agent', 'staff'] as $role) {
            Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        }
    }

    private function admin(): User
    {
        $user = User::create([
            'name' => 'Gorret Admin',
            'email' => 'gorret.admin' . uniqid() . '@plussave.test',
            'password' => 'Password123',
            'is_active' => true,
        ]);

        $user->assignRole('admin');

        return $user;
    }

    public function test_saving_settings_without_touching_a_rate_writes_nothing(): void
    {
        $admin = $this->admin();

        ExchangeRate::setPair('USD', 'UGX', 4000, 3600, 4000);
        ExchangeRate::setPair('USD', 'RMB', 6.56, 6.56, 6.56);

        $rowsBefore = ExchangeRate::count();

        $this->actingAs($admin)->put('/settings', [
            'usd_ugx_buying' => '3600',
            'usd_ugx_selling' => '4000',
            'usd_rmb_buying' => '6.56',
            'usd_rmb_selling' => '6.56',
        ])->assertSessionHasNoErrors();

        $this->assertSame($rowsBefore, ExchangeRate::count(), 'A no-op save must not retire and re-add the pair.');
        $this->assertSame(0, ActivityLog::where('action', 'settings_updated')->count());
    }

    public function test_changing_only_the_buying_price_is_a_real_edit(): void
    {
        $admin = $this->admin();

        ExchangeRate::setPair('USD', 'UGX', 4000, 3600, 4000);

        $this->actingAs($admin)->put('/settings', [
            'usd_ugx_buying' => '3610',
            'usd_ugx_selling' => '4000',
        ])->assertSessionHasNoErrors();

        $active = ExchangeRate::getActiveRate('USD', 'UGX');

        $this->assertEqualsWithDelta(3610, (float) $active->buy_rate, 0.000001);
        $this->assertEqualsWithDelta(4000, (float) $active->sell_rate, 0.000001);

        $log = ActivityLog::where('action', 'settings_updated')->first();

        $this->assertNotNull($log);
        $this->assertStringContainsString('buying rate to 3610', $log->description);
    }

    public function test_a_changed_setting_is_still_logged_once(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->put('/settings', [
            'company_name' => 'PlusPay',
        ])->assertSessionHasNoErrors();

        $this->actingAs($admin)->put('/settings', [
            'company_name' => 'PlusPay',
        ])->assertSessionHasNoErrors();

        $logs = ActivityLog::where('action', 'settings_updated')->get();

        $this->assertSame(1, $logs->count(), 'Saving the same value twice is one change, not two.');
        $this->assertStringContainsString('company_name changed from unset to PlusPay', $logs->first()->description);
    }

    public function test_the_rates_form_accepts_a_new_buying_price_at_the_same_selling_rate(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->post('/exchange-rates', [
            'from_currency' => 'USD',
            'to_currency' => 'UGX',
            'rate' => '4025',
            'buy_rate' => '4000',
        ])->assertSessionHasNoErrors();

        $this->actingAs($admin)->post('/exchange-rates', [
            'from_currency' => 'USD',
            'to_currency' => 'UGX',
            'rate' => '4025',
            'buy_rate' => '3990',
        ])->assertSessionHasNoErrors();

        $active = ExchangeRate::getActiveRate('USD', 'UGX');

        $this->assertEqualsWithDelta(3990, (float) $active->buy_rate, 0.000001);
        $this->assertEqualsWithDelta(4025, (float) $active->sell_rate, 0.000001);
    }

    public function test_the_rates_form_still_refuses_an_identical_pair(): void
    {
        $admin = $this->admin();

        $payload = [
            'from_currency' => 'USD',
            'to_currency' => 'UGX',
            'rate' => '4025',
            'buy_rate' => '4000',
        ];

        $this->actingAs($admin)->post('/exchange-rates', $payload)->assertSessionHasNoErrors();

        $this->actingAs($admin)->post('/exchange-rates', $payload)
            ->assertSessionHasErrors('rate');

        $this->assertSame(1, ExchangeRate::where('from_currency', 'USD')->where('to_currency', 'UGX')->count());
    }
}
