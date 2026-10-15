<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\ExchangeRate;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class SettingsTabsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Role::firstOrCreate(['name' => 'superadmin', 'guard_name' => 'web']);
        $user = User::create([
            'name' => 'Settings owner', 'email' => 'settings@example.test',
            'password' => 'Password123', 'is_active' => true,
        ]);
        $user->assignRole('superadmin');
        $this->actingAs($user);
        config(['app.url' => 'http://localhost']);
        Setting::set('company_name', 'Original company', 'string', 'company');
        Setting::set('service_fee_ugx', 2000, 'float', 'transaction');
        Setting::set('receipt_prefix', 'PS', 'string', 'transaction');
        Setting::set('timezone', 'Africa/Kampala', 'string', 'display');
        Setting::set('site_mode', 'production', 'string', 'display');
        ExchangeRate::setPair('USD', 'UGX', 4000, 3600, 4000);
        ExchangeRate::setPair('USD', 'RMB', 7.2, 7.3, 7.2);
    }

    public function test_tabs_have_separate_scoped_forms_labels_errors_and_dirty_only_save_buttons(): void
    {
        $html = $this->get('/settings')->assertOk()->getContent();
        $dom = new \DOMDocument;
        @$dom->loadHTML($html);
        $xpath = new \DOMXPath($dom);
        $this->assertCount(5, $xpath->query('//form[@data-settings-form and @data-validate]'));
        $this->assertCount(5, $xpath->query('//button[@data-save and @disabled]'));
        $this->assertCount(1, $xpath->query('//*[@role="tab" and @aria-selected="true" and @id="tab-rates"]'));
        $this->assertCount(4, $xpath->query('//*[@role="tabpanel" and @hidden]'));
        foreach ($xpath->query('//form[@data-settings-form]') as $form) {
            $this->assertCount(1, $xpath->query('.//input[@name="settings_tab" and @value="'.$form->getAttribute('data-settings-form').'"]', $form));
        }
        foreach ($xpath->query('//*[@data-original]') as $field) {
            $name = $field->getAttribute('name');
            $this->assertSame($name, $field->getAttribute('id'));
            $this->assertCount(1, $xpath->query('//label[@for="'.$name.'"]'));
            $this->assertCount(1, $xpath->query('//*[@data-field-error="'.$name.'" and @id="error-'.$name.'"]'));
        }
        $this->assertStringContainsString('Margin: +400 UGX/USD', $html);
        $this->assertStringContainsString('Margin: +0.1 RMB/USD', $html);
        $this->assertStringContainsString('data-min="0.01"', $html);
        $this->assertStringContainsString('maxlength="10"', $html);
        $this->assertStringContainsString('id="receipt-preview"', $html);
        $this->assertStringContainsString('js/settings.js', $html);
    }

    public function test_each_rate_field_puts_its_unit_in_the_label_and_states_its_own_spread_rule(): void
    {
        $html = $this->get('/settings')->assertOk()->getContent();
        $this->assertStringContainsString('Buying rate <span class="text-gray-500">(UGX/USD)</span>', $html);
        $this->assertStringContainsString('Selling rate <span class="text-gray-500">(RMB/USD)</span>', $html);
        $this->assertStringNotContainsString('id="unit-usd_ugx_buying"', $html);
        $this->assertStringNotContainsString('aria-describedby="unit-', $html);
        $this->assertStringContainsString('A positive margin means buying shillings cheap', $html);
        $this->assertStringContainsString('A positive margin means crediting the receiver at the selling price', $html);
    }

    public function test_company_save_cannot_overwrite_other_tabs_even_with_extra_payload_fields(): void
    {
        $rows = ExchangeRate::count();
        $this->from('/settings')->put('/settings', [
            'settings_tab' => 'company', 'company_name' => 'New company',
            'service_fee_ugx' => 999, 'timezone' => 'UTC', 'site_mode' => 'testing',
            'usd_ugx_selling' => 5000, 'usd_ugx_buying' => 4900,
        ])->assertRedirect('/settings')->assertSessionHasNoErrors()->assertSessionHas('settings_saved_tab', 'company');
        $this->assertSame('New company', Setting::get('company_name'));
        $this->assertSame(2000.0, Setting::get('service_fee_ugx'));
        $this->assertSame('Africa/Kampala', Setting::get('timezone'));
        $this->assertSame('production', Setting::get('site_mode'));
        $this->assertSame($rows, ExchangeRate::count());
        $log = ActivityLog::where('action', 'settings_updated')->sole();
        $this->assertSame(['company_name' => 'Original company'], json_decode($log->old_value, true));
        $this->assertSame(['company_name' => 'New company'], json_decode($log->new_value, true));
    }

    public function test_transaction_save_leaves_company_and_rates_untouched(): void
    {
        $rows = ExchangeRate::count();
        $this->put('/settings', ['settings_tab' => 'transactions', 'service_fee_ugx' => '2500.50', 'receipt_prefix' => 'PAY'])
            ->assertSessionHasNoErrors();
        $this->assertSame(2500.5, Setting::get('service_fee_ugx'));
        $this->assertSame('PAY', Setting::get('receipt_prefix'));
        $this->assertSame('Original company', Setting::get('company_name'));
        $this->assertSame($rows, ExchangeRate::count());
    }

    public function test_rates_save_leaves_other_settings_and_other_pair_untouched(): void
    {
        $rmbId = ExchangeRate::getActiveRate('USD', 'RMB')->id;
        $this->put('/settings', ['settings_tab' => 'rates', 'usd_ugx_buying' => '3650', 'usd_ugx_selling' => '4010'])
            ->assertSessionHasNoErrors();
        $this->assertSame('Original company', Setting::get('company_name'));
        $this->assertSame(2000.0, Setting::get('service_fee_ugx'));
        $this->assertSame($rmbId, ExchangeRate::getActiveRate('USD', 'RMB')->id);
        $this->assertEquals(4010, ExchangeRate::usdUgxPair()['sell']);
        $this->assertEquals(3650, ExchangeRate::usdUgxPair()['buy']);
    }

    public function test_blank_selling_skips_each_pair_and_blank_buying_preserves_saved_buying(): void
    {
        $rows = ExchangeRate::count();
        $this->put('/settings', [
            'settings_tab' => 'rates', 'usd_ugx_buying' => '', 'usd_ugx_selling' => '',
            'usd_rmb_buying' => '7.4', 'usd_rmb_selling' => '',
        ])->assertSessionHasNoErrors();
        $this->assertSame($rows, ExchangeRate::count());
        $this->assertSame(0, ActivityLog::where('action', 'settings_updated')->count());
        $this->put('/settings', [
            'settings_tab' => 'rates', 'usd_ugx_buying' => '', 'usd_ugx_selling' => '4100',
            'usd_rmb_buying' => '', 'usd_rmb_selling' => '7.1',
        ])->assertSessionHasNoErrors();
        $this->assertEquals(3600, ExchangeRate::usdUgxPair()['buy']);
        $this->assertEquals(4100, ExchangeRate::usdUgxPair()['sell']);
        $this->assertEquals(7.3, ExchangeRate::pair('USD', 'RMB')['buy']);
        $this->assertEquals(7.1, ExchangeRate::pair('USD', 'RMB')['sell']);
    }

    public function test_no_op_saves_do_not_write_rates_or_audit_entries_even_with_decimal_formatting(): void
    {
        $rows = ExchangeRate::count();
        $this->put('/settings', ['settings_tab' => 'transactions', 'service_fee_ugx' => '2000.00'])
            ->assertSessionHasNoErrors()->assertSessionHas('success', 'No changes to save.');
        $this->put('/settings', [
            'settings_tab' => 'rates', 'usd_ugx_buying' => '3600.00', 'usd_ugx_selling' => '4000.0000',
            'usd_rmb_buying' => '7.300000', 'usd_rmb_selling' => '7.200000',
        ])->assertSessionHasNoErrors();
        $this->assertSame($rows, ExchangeRate::count());
        $this->assertSame(0, ActivityLog::where('action', 'settings_updated')->count());
    }

    public function test_validation_preserves_all_settings_and_returns_to_the_failed_tab(): void
    {
        $response = $this->followingRedirects()->from('/settings')->put('/settings', [
            'settings_tab' => 'transactions', 'service_fee_ugx' => -1, 'service_fee_usd' => 0,
            'receipt_prefix' => 'NOT-VALID',
        ])->assertOk();
        $this->assertSame(2000.0, Setting::get('service_fee_ugx'));
        $this->assertSame('PS', Setting::get('receipt_prefix'));
        $html = $response->getContent();
        $dom = new \DOMDocument;
        @$dom->loadHTML($html);
        $xpath = new \DOMXPath($dom);
        $this->assertCount(1, $xpath->query('//*[@id="tab-transactions" and @aria-selected="true"]'));
        $this->assertCount(1, $xpath->query('//*[@id="panel-transactions" and not(@hidden)]'));
        foreach (['service_fee_ugx', 'service_fee_usd', 'receipt_prefix'] as $field) {
            $this->assertNotEmpty(trim($xpath->query('//*[@data-field-error="'.$field.'"]')->item(0)->textContent));
        }
        $this->assertSame('The receipt prefix can only contain letters and numbers, e.g. PS.', trim($xpath->query('//*[@data-field-error="receipt_prefix"]')->item(0)->textContent));
        $this->assertSame(0, ActivityLog::where('action', 'settings_updated')->count());
    }

    public function test_invalid_rate_values_are_rejected_but_unusual_spreads_remain_allowed(): void
    {
        $rows = ExchangeRate::count();
        $this->put('/settings', [
            'settings_tab' => 'rates', 'usd_ugx_buying' => 0, 'usd_ugx_selling' => 'not-a-number',
            'usd_rmb_buying' => 1000000, 'usd_rmb_selling' => -1,
        ])->assertSessionHasErrors(['usd_ugx_buying', 'usd_ugx_selling', 'usd_rmb_buying', 'usd_rmb_selling']);
        $this->assertSame($rows, ExchangeRate::count());
        // Warnings must not become a new mathematical validation policy.
        $this->put('/settings', [
            'settings_tab' => 'rates', 'usd_ugx_buying' => 5000, 'usd_ugx_selling' => 4000,
            'usd_rmb_buying' => 6, 'usd_rmb_selling' => 7,
        ])->assertSessionHasNoErrors();
        $html = $this->get('/settings')->assertOk()->getContent();
        $this->assertStringContainsString('Margin: -1000 UGX/USD', $html);
        $this->assertStringContainsString('Margin: -1 RMB/USD', $html);
        $this->assertEquals(4000, ExchangeRate::getActiveRate('USD', 'UGX')->rate);
        $this->assertEquals(7, ExchangeRate::getActiveRate('USD', 'RMB')->rate);
    }

    public function test_unknown_tab_is_rejected_without_changes(): void
    {
        $this->put('/settings', ['settings_tab' => 'unknown', 'company_name' => 'Wrong'])
            ->assertSessionHasErrors('settings_tab');
        $this->assertSame('Original company', Setting::get('company_name'));
    }
}
