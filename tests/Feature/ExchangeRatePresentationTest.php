<?php

namespace Tests\Feature;

use App\Models\ExchangeRate;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ExchangeRatePresentationTest extends TestCase
{
    use RefreshDatabase;

    private function viewer(string $role = 'admin'): User
    {
        $record = Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        foreach (['view-exchange-rates', 'manage-exchange-rates', 'view-settings'] as $name) {
            Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
        }
        $record->syncPermissions($role === 'admin' ? ['view-exchange-rates', 'manage-exchange-rates', 'view-settings'] : ['view-exchange-rates']);
        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole($record);

        return $user;
    }

    private function rate(bool $active, string $currency = 'UGX'): ExchangeRate
    {
        return ExchangeRate::create([
            'from_currency' => 'USD', 'to_currency' => $currency,
            'rate' => $currency === 'UGX' ? 3760 : 7.24,
            'sell_rate' => $currency === 'UGX' ? 3760 : 7.24,
            'buy_rate' => $currency === 'UGX' ? 3750 : 7.30,
            'is_active' => $active,
        ]);
    }

    public function test_default_only_lists_current_rates_and_all_retains_its_filter_in_date_links(): void
    {
        $current = $this->rate(true);
        $old = $this->rate(false);
        $this->actingAs($this->viewer())->get('/exchange-rates')->assertOk()
            ->assertViewHas('rates', fn ($rates) => $rates->pluck('id')->all() === [$current->id]);
        $response = $this->get('/exchange-rates?status=')->assertOk()
            ->assertViewHas('rates', fn ($rates) => $rates->count() === 2);
        $this->assertStringContainsString('status=&amp;date_from=', $response->getContent());
        $this->get('/exchange-rates?status=inactive')->assertOk()
            ->assertViewHas('rates', fn ($rates) => $rates->pluck('id')->all() === [$old->id]);
    }

    public function test_icon_actions_name_the_pair_and_confirm_the_exact_rate(): void
    {
        $rate = $this->rate(false, 'RMB');
        $this->actingAs($this->viewer())->get('/exchange-rates?status=')->assertOk()
            ->assertSee('aria-label="Edit the USD to RMB prices"', false)
            ->assertSee('data-confirm="Set USD to RMB 7.24 as the current rate?"', false)
            ->assertSee('aria-controls="rate-edit-'.$rate->id.'"', false)
            ->assertSee('rate-actions', false);
    }

    public function test_read_only_accountant_sees_no_mutating_controls(): void
    {
        $this->rate(false);
        $this->actingAs($this->viewer('accountant'))->get('/exchange-rates?status=')->assertOk()
            ->assertDontSee('aria-label="Edit the', false)
            ->assertDontSee('data-confirm="Set USD', false)
            ->assertDontSee('Update rates');
    }

    public function test_both_corridors_display_signed_profitable_margins(): void
    {
        $this->rate(true);
        $rmb = $this->rate(true, 'RMB');
        $response = $this->actingAs($this->viewer())->get('/exchange-rates')->assertOk();
        $this->assertMatchesRegularExpression('/data-label="Margin"[^>]*>\+10<\/td>/', $response->getContent());
        $this->assertMatchesRegularExpression('/data-label="Margin"[^>]*>\+0\.06<\/td>/', $response->getContent());
        $this->assertStringContainsString('data-label="Margin" title="What this pair earns per dollar. Negative means a loss."', $response->getContent());
        $rmb->update(['buy_rate' => 7.18]);
        $this->assertMatchesRegularExpression('/data-label="Margin"[^>]*>-0\.06<\/td>/', $this->get('/exchange-rates')->getContent());
    }

    public function test_missing_buying_price_has_a_dash_in_both_numeric_cells(): void
    {
        $rate = $this->rate(true);
        $rate->update(['buy_rate' => null]);
        $html = $this->actingAs($this->viewer())->get('/exchange-rates')->assertOk()->getContent();
        $this->assertMatchesRegularExpression('/data-label="Buying">-<\/td>/', $html);
        $this->assertMatchesRegularExpression('/data-label="Margin"[^>]*>-<\/td>/', $html);
    }
}
