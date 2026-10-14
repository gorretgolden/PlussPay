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

    public function test_correcting_only_the_buying_price_on_a_row_is_a_real_edit(): void
    {
        $admin = $this->admin();

        $row = ExchangeRate::setPair('USD', 'UGX', 4025, 4000, 4025);

        $this->actingAs($admin)->patch('/exchange-rates/'.$row->id, [
            'rate_id' => $row->id,
            'sell_rate' => '4025',
            'buy_rate' => '3990',
        ])->assertSessionHasNoErrors();

        $active = ExchangeRate::getActiveRate('USD', 'UGX');

        $this->assertSame($row->id, $active->id, 'A correction changes the row, it does not add one.');
        $this->assertEqualsWithDelta(3990, (float) $active->buy_rate, 0.000001);
        $this->assertEqualsWithDelta(4025, (float) $active->sell_rate, 0.000001);
    }

    public function test_the_edit_refuses_figures_that_are_already_on_the_row(): void
    {
        $admin = $this->admin();

        $row = ExchangeRate::setPair('USD', 'UGX', 4025, 4000, 4025);

        $this->actingAs($admin)->patch('/exchange-rates/'.$row->id, [
            'rate_id' => $row->id,
            'sell_rate' => '4025',
            'buy_rate' => '4000',
        ])->assertSessionHasErrors('sell_rate');

        $this->assertSame(1, ExchangeRate::where('from_currency', 'USD')->where('to_currency', 'UGX')->count());
        $this->assertSame(0, ActivityLog::where('action', 'exchange_rate_edited')->count());
    }
}
