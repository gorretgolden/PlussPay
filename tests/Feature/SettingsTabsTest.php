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
        Setting::set('min_transaction_amount', 100, 'float', 'transaction');
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
        $this->assertCount(7, $xpath->query('//form[@data-settings-form and @data-validate and @data-lock]'));
        $this->assertCount(7, $xpath->query('//button[@data-save and @disabled]'));
        $this->assertCount(1, $xpath->query('//*[@role="tab" and @aria-selected="true" and @id="tab-rates"]'));
        $this->assertCount(6, $xpath->query('//*[@role="tabpanel" and @hidden]'));
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
        // One short line per card, and the two opposite rules named side by side.
        $this->assertStringContainsString('Selling at or above buying keeps a spread.', $html);
        $this->assertStringContainsString('Buying at or above selling keeps a spread.', $html);
        $this->assertStringNotContainsString('A positive margin means', $html);
        // The three states are each written once, and the flat one is reachable only as a warning.
        $this->assertSame(2, substr_count($html, 'No spread: both prices are the same, so this leg earns nothing.'));
        $this->assertStringContainsString('Selling is below buying, so this leg loses money.', $html);
        $this->assertStringContainsString('Buying is below selling, so this leg loses money.', $html);
    }

    public function test_each_card_describes_the_direction_it_prices_and_holds_its_detail_behind_a_question_mark(): void
    {
        $html = $this->get('/settings')->assertOk()->getContent();
        $this->assertStringContainsString('Dollars to shillings.', $html);
        $this->assertStringContainsString('Dollars to yuan for China payouts.', $html);
        $this->assertStringNotContainsString('Convert shillings into dollars', $html);
        $this->assertStringNotContainsString('Price RMB payouts to China.', $html);

        // The working belongs in the tooltip, so each explanation appears exactly once on the page.
        $this->assertSame(1, substr_count($html, 'Margin = selling - buying'));
        $this->assertSame(1, substr_count($html, 'Margin = buying - selling'));
        $this->assertStringContainsString('<summary aria-label="How the UGX margin is worked out"', $html);
        $this->assertStringContainsString('<summary aria-label="How the RMB margin is worked out"', $html);
    }

    public function test_each_card_names_the_price_in_use_and_who_last_set_it(): void
    {
        $live = ExchangeRate::getActiveRate('USD', 'UGX');

        // A row nobody has corrected yet is still traceable to the person who put it in.
        $live->forceFill(['updated_by' => null])->save();
        $html = $this->get('/settings')->assertOk()->getContent();
        $this->assertMatchesRegularExpression('/Updated <time[^>]*>\w{3} \d{2}, \d{4} \d{2}:\d{2} [AP]M<\/time> by '.preg_quote($live->creator->name, '/').'/', $html);

        $editor = User::create([
            'name' => 'Ruth Backoffice', 'email' => 'ruth@example.test',
            'password' => 'Password123', 'is_active' => true,
        ]);
        $live->forceFill(['updated_by' => $editor->id])->save();
        $this->get('/settings')->assertOk()->assertSee('by '.$editor->fresh()->name.'.', false);

        ExchangeRate::where('from_currency', 'USD')->where('to_currency', 'RMB')->update(['is_active' => false]);
        $this->get('/settings')->assertOk()->assertSee('No price is in use for USD to RMB.')
            ->assertSee('Rate history');
    }

    public function test_company_save_cannot_overwrite_other_tabs_even_with_extra_payload_fields(): void
    {
        $rows = ExchangeRate::count();
        $this->from('/settings')->put('/settings', [
            'settings_tab' => 'company', 'company_name' => 'New company',
            'min_transaction_ugx' => 999, 'timezone' => 'UTC', 'site_mode' => 'testing',
            'usd_ugx_selling' => 5000, 'usd_ugx_buying' => 4900,
        ])->assertRedirect('/settings')->assertSessionHasNoErrors()->assertSessionHas('settings_saved_tab', 'company');
        $this->assertSame('New company', Setting::get('company_name'));
        $this->assertNull(Setting::get('min_transaction_ugx'));
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
        $this->put('/settings', ['settings_tab' => 'transactions', 'min_transaction_ugx' => '250.50', 'receipt_prefix' => 'PAY'])
            ->assertSessionHasNoErrors();
        $this->assertSame(250.5, Setting::get('min_transaction_ugx'));
        $this->assertSame('PAY', Setting::get('receipt_prefix'));
        $this->assertSame('Original company', Setting::get('company_name'));
        $this->assertSame($rows, ExchangeRate::count());
    }

    public function test_saving_a_per_currency_bound_retires_the_old_single_min_and_max(): void
    {
        Setting::set('max_transaction_amount', 500000, 'float', 'transaction');

        $this->put('/settings', ['settings_tab' => 'transactions', 'max_transaction_ugx' => '600000'])
            ->assertSessionHasNoErrors();

        $this->assertSame(600000.0, Setting::get('max_transaction_ugx'));
        $this->assertNull(Setting::get('min_transaction_amount'));
        $this->assertNull(Setting::get('max_transaction_amount'));
    }

    public function test_a_desk_still_on_the_old_single_min_reads_it_as_its_shilling_floor(): void
    {
        Setting::set('min_transaction_amount', 1500, 'float', 'transaction');
        Setting::set('max_transaction_amount', 900000, 'float', 'transaction');

        $bounds = Setting::transactionBounds();

        $this->assertSame(1500.0, $bounds['UGX']['min']);
        $this->assertSame(900000.0, $bounds['UGX']['max']);
        $this->assertSame(100.0, $bounds['USD']['min']);
        $this->assertSame(100000000.0, $bounds['USD']['max']);
        $this->assertSame(100.0, $bounds['RMB']['min']);
    }

    public function test_the_per_dollar_fee_is_gone_from_settings_and_ignores_old_posts(): void
    {
        Setting::set('service_fee_ugx', 2000, 'float', 'transaction');
        Setting::set('service_fee_usd', 100, 'float', 'transaction');

        $html = $this->get('/settings')->assertOk()->getContent();
        $this->assertStringNotContainsString('service_fee_ugx', $html);
        $this->assertStringNotContainsString('Service fee', $html);
        $this->assertStringNotContainsString('Charged per', $html);

        // A tab left open in a browser still posts the old fields. They must not be written back.
        $this->from('/settings')->put('/settings', [
            'settings_tab' => 'transactions', 'receipt_prefix' => 'PS',
            'service_fee_ugx' => 999, 'service_fee_usd' => 1,
        ])->assertSessionHasNoErrors();
        $this->assertSame(2000.0, Setting::get('service_fee_ugx'));
        $this->assertSame(100.0, Setting::get('service_fee_usd'));
    }

    /**
     * One flat figure replaces the old two-field rule: it only opens the charge box on a
     * new booking, so it is saved and read as plain shillings.
     */
    public function test_the_transactions_tab_holds_one_default_charge_and_saves_it(): void
    {
        $html = $this->get('/settings')->assertOk()->getContent();

        $this->assertStringContainsString('name="default_charge_ugx"', $html);
        $this->assertStringContainsString('Default charge', $html);
        $this->assertStringContainsString('Opens the charge box on a new booking.', $html);
        $this->assertStringNotContainsString('per 100 USD', $html);
        $this->assertSame(1, substr_count($html, 'name="default_charge_ugx"'));

        $this->from('/settings')->put('/settings', [
            'settings_tab' => 'transactions', 'default_charge_ugx' => '2000',
        ])->assertSessionHasNoErrors();

        $this->assertSame(2000.0, Setting::get('default_charge_ugx'));

        $this->from('/settings')->put('/settings', [
            'settings_tab' => 'transactions', 'default_charge_ugx' => '-5',
        ])->assertSessionHasErrors('default_charge_ugx');
    }

    public function test_rates_save_leaves_other_settings_and_other_pair_untouched(): void
    {
        $rmbId = ExchangeRate::getActiveRate('USD', 'RMB')->id;
        $this->put('/settings', ['settings_tab' => 'rates', 'usd_ugx_buying' => '3650', 'usd_ugx_selling' => '4010'])
            ->assertSessionHasNoErrors();
        $this->assertSame('Original company', Setting::get('company_name'));
        $this->assertSame(100.0, Setting::get('min_transaction_amount'));
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
        Setting::set('min_transaction_ugx', 100, 'float', 'transaction');
        $rows = ExchangeRate::count();
        $this->put('/settings', ['settings_tab' => 'transactions', 'min_transaction_ugx' => '100.00'])
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
            'settings_tab' => 'transactions', 'min_transaction_ugx' => -1, 'max_transaction_ugx' => -5,
            'receipt_prefix' => 'NOT-VALID',
        ])->assertOk();
        $this->assertNull(Setting::get('min_transaction_ugx'));
        $this->assertSame('PS', Setting::get('receipt_prefix'));
        $html = $response->getContent();
        $dom = new \DOMDocument;
        @$dom->loadHTML($html);
        $xpath = new \DOMXPath($dom);
        $this->assertCount(1, $xpath->query('//*[@id="tab-transactions" and @aria-selected="true"]'));
        $this->assertCount(1, $xpath->query('//*[@id="panel-transactions" and not(@hidden)]'));
        foreach (['min_transaction_ugx', 'max_transaction_ugx', 'receipt_prefix'] as $field) {
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
