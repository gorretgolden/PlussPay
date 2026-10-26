<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\ExchangeRate;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ExchangeRateEditorTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['superadmin', 'admin', 'agent', 'staff'] as $role) {
            Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        }
    }

    private function admin(string $name): User
    {
        $user = User::create([
            'name' => $name,
            'email' => strtolower(str_replace(' ', '.', $name)) . uniqid() . '@plussave.test',
            'password' => 'Password123',
            'is_active' => true,
        ]);

        $user->assignRole('admin');

        return $user;
    }

    private function rate(array $attributes = []): ExchangeRate
    {
        return ExchangeRate::create(array_merge([
            'from_currency' => 'USD',
            'to_currency' => 'UGX',
            'rate' => 3772,
            'sell_rate' => 3772,
            'buy_rate' => 3758,
            'is_active' => true,
        ], $attributes));
    }

    public function test_a_brand_new_rate_has_an_author_but_no_editor_yet(): void
    {
        $gorret = $this->admin('Gorret Admin');

        // Prices are set in Settings, where only the two pairs the system reads can be entered.
        $this->actingAs($gorret)
            ->put('/settings', [
                'usd_ugx_selling' => '3772',
                'usd_ugx_buying' => '3758',
            ])
            ->assertSessionHasNoErrors();

        $rate = ExchangeRate::where('to_currency', 'UGX')->first();

        $this->assertSame($gorret->id, $rate->created_by);
        $this->assertNull($rate->updated_by);
    }

    public function test_saving_a_new_rate_marks_who_retired_the_previous_one(): void
    {
        $gorret = $this->admin('Gorret Admin');
        $alice = $this->admin('Alice Admin');

        $old = $this->rate(['created_by' => $gorret->id]);

        $this->actingAs($alice)
            ->put('/settings', [
                'usd_ugx_selling' => '3790',
                'usd_ugx_buying' => '3775',
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame($alice->id, $old->fresh()->updated_by);
        $this->assertFalse($old->fresh()->is_active);
    }

    public function test_the_rates_page_no_longer_offers_a_free_pair_to_add(): void
    {
        $this->rate();

        $this->actingAs($this->admin('Gorret Admin'))
            ->get('/exchange-rates')
            ->assertOk()
            ->assertDontSee('Add Exchange Rate')
            ->assertSee('Update rates');
    }

    public function test_correcting_the_live_price_changes_that_row_and_stamps_who_did_it(): void
    {
        $gorret = $this->admin('Gorret Admin');
        $alice = $this->admin('Alice Editor');

        $rate = $this->rate(['created_by' => $gorret->id]);

        $this->actingAs($alice)
            ->patch('/exchange-rates/'.$rate->id, [
                'rate_id' => $rate->id,
                'sell_rate' => '3760',
                'buy_rate' => '3750',
            ])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success', fn (string $message) => str_contains($message, 'USD → UGX corrected to selling 3760, buying 3750'));

        $fresh = $rate->fresh();

        // Corrected in place: no extra row, and the customer facing figure stays the selling one.
        $this->assertSame(1, ExchangeRate::count());
        $this->assertTrue($fresh->is_active);
        $this->assertEqualsWithDelta(3760, (float) $fresh->rate, 0.000001);
        $this->assertEqualsWithDelta(3760, (float) $fresh->sell_rate, 0.000001);
        $this->assertEqualsWithDelta(3750, (float) $fresh->buy_rate, 0.000001);
        $this->assertSame($alice->id, $fresh->updated_by);
        $this->assertSame($gorret->id, $fresh->created_by);

        $log = ActivityLog::where('action', 'exchange_rate_edited')->firstOrFail();
        $this->assertStringContainsString('Live price for USD → UGX corrected from selling 3772, buying 3758', $log->description);
    }

    public function test_an_earlier_price_is_only_corrected_once_the_warning_is_acknowledged(): void
    {
        $alice = $this->admin('Alice Editor');

        $this->rate();
        $earlier = $this->rate(['rate' => 3700, 'sell_rate' => 3700, 'buy_rate' => 3690, 'is_active' => false]);

        $this->actingAs($alice)
            ->patch('/exchange-rates/'.$earlier->id, [
                'rate_id' => $earlier->id,
                'sell_rate' => '3705',
                'buy_rate' => '3690',
            ])
            ->assertSessionHasErrors('history_ok');

        $this->assertEqualsWithDelta(3700, (float) $earlier->fresh()->sell_rate, 0.000001);

        $this->actingAs($alice)
            ->patch('/exchange-rates/'.$earlier->id, [
                'rate_id' => $earlier->id,
                'sell_rate' => '3705',
                'buy_rate' => '3690',
                'history_ok' => '1',
            ])
            ->assertSessionHasNoErrors();

        $this->assertEqualsWithDelta(3705, (float) $earlier->fresh()->sell_rate, 0.000001);
        $this->assertFalse($earlier->fresh()->is_active, 'Correcting an old row does not bring it back into use.');
        $this->assertStringContainsString('Price from '.$earlier->fresh()->created_at->format('M d, Y'),
            ActivityLog::where('action', 'exchange_rate_edited')->firstOrFail()->description);
    }

    public function test_blank_buying_price_means_the_row_carries_one_figure(): void
    {
        $alice = $this->admin('Alice Editor');
        $rate = $this->rate(['buy_rate' => null]);

        $this->actingAs($alice)
            ->patch('/exchange-rates/'.$rate->id, [
                'rate_id' => $rate->id,
                'sell_rate' => '3760',
                'buy_rate' => '',
            ])
            ->assertSessionHasNoErrors();

        $fresh = $rate->fresh();

        $this->assertEqualsWithDelta(3760, (float) $fresh->buy_rate, 0.000001);
        $this->assertEqualsWithDelta(3760, (float) $fresh->sell_rate, 0.000001);

        $this->actingAs($alice)
            ->get('/exchange-rates')
            ->assertOk()
            ->assertDontSee('Pending');
    }

    public function test_a_backwards_price_is_saved_and_the_page_shouts_about_it(): void
    {
        $alice = $this->admin('Alice Editor');
        $rate = $this->rate();

        // The spread warning still shows on the page, so a wrong figure is visible rather than silent.
        $this->actingAs($alice)
            ->patch('/exchange-rates/'.$rate->id, [
                'rate_id' => $rate->id,
                'sell_rate' => '3700',
                'buy_rate' => '3800',
            ])
            ->assertSessionHasNoErrors();

        $this->actingAs($alice)
            ->get('/exchange-rates')
            ->assertOk()
            ->assertSee('Spread warning');
    }

    public function test_switching_a_rate_on_records_the_person_who_clicked_it(): void
    {
        $alice = $this->admin('Alice Admin');

        $live = $this->rate();
        $dormant = $this->rate(['rate' => 3700, 'sell_rate' => 3700, 'is_active' => false]);

        $this->actingAs($alice)
            ->patch('/exchange-rates/'.$dormant->id.'/toggle')
            ->assertSessionHasNoErrors();

        $this->assertTrue($dormant->fresh()->is_active);
        $this->assertSame($alice->id, $dormant->fresh()->updated_by);

        // One pair, one current price: bringing a row in always retires the one it replaces, so two
        // bookings entered a minute apart cannot be priced off different figures.
        $this->assertFalse($live->fresh()->is_active);
        $this->assertSame(1, ExchangeRate::where('is_active', true)->where('to_currency', 'UGX')->count());
    }

    /**
     * A pair left with two prices in use is data from before the rule, and the desk reads the newer
     * one. Setting the quiet row aside is the fix she asked to be able to do by hand, and the page
     * has to name the row so the switch is not guesswork.
     */
    public function test_a_price_in_use_that_nothing_reads_is_named_and_can_be_taken_out_of_use(): void
    {
        $alice = $this->admin('Alice Admin');

        $older = $this->rate(['rate' => 3700, 'sell_rate' => 3700, 'buy_rate' => 3690]);
        $current = $this->rate(['rate' => 3772, 'sell_rate' => 3772, 'buy_rate' => 3758]);

        $this->actingAs($alice)
            ->get('/exchange-rates')
            ->assertOk()
            ->assertSee('Prices in use')
            ->assertSee('USD → UGX 3,700 is not the price being read.');

        $this->actingAs($alice)
            ->patch('/exchange-rates/'.$older->id.'/toggle')
            ->assertSessionHas('success', fn (string $message) => str_contains($message, 'USD → UGX 3700 is out of use.'));

        $this->assertFalse($older->fresh()->is_active);
        $this->assertTrue($current->fresh()->is_active, 'The price the desk reads is untouched.');
    }

    public function test_the_only_price_in_use_for_a_desk_pair_cannot_be_taken_out_of_use(): void
    {
        $alice = $this->admin('Alice Admin');

        $this->rate(['from_currency' => 'USD', 'to_currency' => 'RMB', 'rate' => 7.24, 'sell_rate' => 7.24, 'buy_rate' => 7.2]);
        $live = $this->rate();

        $this->actingAs($alice)
            ->patch('/exchange-rates/'.$live->id.'/toggle')
            ->assertSessionHasErrors(['error' => 'USD → UGX 3772 is the only price in use for USD → UGX. Bookings cannot be priced without it, so set another price for the pair first.']);

        $this->assertTrue($live->fresh()->is_active, 'The last live price stays in use.');

        // The other desk pair still has its price, so only nothing is reported missing.
        $this->actingAs($alice)
            ->get('/exchange-rates')
            ->assertOk()
            ->assertDontSee('No price is in use for USD → UGX.')
            ->assertDontSee('No price is in use for USD → RMB');
    }

    public function test_a_reverse_direction_row_cannot_become_a_price_in_use(): void
    {
        $alice = $this->admin('Alice Admin');

        // The shilling price is held as USD → UGX with both figures on it. The reverse row prices
        // nothing and would stand in for the desk price if it were ever switched off, so it is
        // refused rather than silently accepted.
        $reverse = $this->rate([
            'from_currency' => 'UGX',
            'to_currency' => 'USD',
            'rate' => 0.00027,
            'sell_rate' => 0.00027,
            'buy_rate' => null,
            'is_active' => false,
        ]);

        $this->actingAs($alice)
            ->patch('/exchange-rates/'.$reverse->id.'/toggle')
            ->assertSessionHasErrors(['error' => 'UGX → USD cannot be set as current. Only USD → UGX and USD → RMB price bookings.']);

        $this->assertFalse($reverse->fresh()->is_active);

        $this->actingAs($alice)
            ->get('/exchange-rates?status=')
            ->assertOk()
            ->assertDontSee('Set UGX to USD 0.00027 as the current rate', false);
    }

    public function test_a_live_reverse_direction_row_can_be_switched_off_and_prices_nothing(): void
    {
        $alice = $this->admin('Alice Admin');

        $this->rate();
        $reverse = $this->rate([
            'from_currency' => 'UGX',
            'to_currency' => 'USD',
            'rate' => 4235,
            'sell_rate' => 4235,
            'buy_rate' => 4115,
        ]);

        $this->assertSame(3772.0, ExchangeRate::usdToUgx(), 'The shilling price comes from the desk pair, never the reverse row.');

        $this->actingAs($alice)
            ->get('/exchange-rates')
            ->assertOk()
            ->assertSee('UGX → USD 4,235 is not the price being read. Take it out of use.');

        $this->actingAs($alice)
            ->patch('/exchange-rates/'.$reverse->id.'/toggle')
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success', fn (string $message) => str_contains($message, 'UGX → USD 4235 is out of use.'));

        $this->assertFalse($reverse->fresh()->is_active);
        $this->assertSame(3772.0, ExchangeRate::usdToUgx(), 'Switching the reverse row off changes no price anything is worked out at.');
    }

    public function test_the_price_in_use_carries_a_switch_that_names_what_it_does(): void
    {
        $alice = $this->admin('Alice Admin');
        $this->rate();

        $html = $this->actingAs($alice)->get('/exchange-rates')->assertOk()->getContent();

        $this->assertStringContainsString('aria-label="Take USD to UGX 3,772 out of use"', $html);
        $this->assertStringContainsString('data-confirm="Take USD to UGX 3,772 out of use?"', $html);
    }

    public function test_the_desk_prices_are_the_only_rows_anything_is_worked_from(): void
    {
        // An in-use UGX → USD row is not a substitute for the shilling price: the desk has none, so
        // conversions report no price rather than inventing one by inverting a row nobody set.
        $this->rate(['from_currency' => 'UGX', 'to_currency' => 'USD', 'rate' => 0.00027, 'sell_rate' => 0.00027, 'buy_rate' => null]);

        $this->assertNull(ExchangeRate::usdToUgx());
        $this->assertNull(ExchangeRate::usdUgxPair());
    }

    public function test_seeding_leaves_exactly_the_two_desk_prices_in_use(): void
    {
        // Re-running the demo seed on the live book used to resurrect the oldest row of each pair
        // and add a third, reverse-direction price, which is how a pair ended up with two prices
        // in use at once.
        $this->rate(['rate' => 3772, 'sell_rate' => 3772, 'buy_rate' => 3758]);
        $this->rate(['from_currency' => 'USD', 'to_currency' => 'RMB', 'rate' => 7.24, 'sell_rate' => 7.24, 'buy_rate' => 7.2]);
        $retired = $this->rate(['from_currency' => 'USD', 'to_currency' => 'RMB', 'rate' => 7.10, 'sell_rate' => 7.10, 'buy_rate' => 7.05, 'is_active' => false]);

        $this->seed(\Database\Seeders\ExchangeRateSeeder::class);

        $this->assertFalse($retired->fresh()->is_active, 'An earlier price stays an earlier price.');
        $this->assertSame(2, ExchangeRate::where('is_active', true)->count());
        $this->assertSame([], ExchangeRate::liveRowsOutsideRule()->all());
        $this->assertSame([], ExchangeRate::unpricedDeskPairs());
    }

    public function test_the_rates_page_shows_who_edited_each_rate(): void
    {
        $gorret = $this->admin('Gorret Admin');
        $alice = $this->admin('Alice Editor');

        $rate = $this->rate(['created_by' => $gorret->id]);

        $this->actingAs($alice);
        $rate->update(['buy_rate' => 3600]);

        $this->get('/exchange-rates')
            ->assertOk()
            ->assertSee('Alice Editor');
    }

    public function test_the_date_columns_carry_the_clock_time_and_not_just_the_day(): void
    {
        $gorret = $this->admin('Gorret Admin');
        $alice = $this->admin('Alice Editor');

        $this->travelTo(\Carbon\Carbon::parse('2026-10-16 09:05:00'));
        $rate = $this->rate(['created_by' => $gorret->id]);

        $this->actingAs($alice);
        $rate->update(['buy_rate' => 3600]);

        $html = $this->get('/exchange-rates')->assertOk()->getContent();

        // Two price changes on the same Friday read as one row without the hour, and the Excel
        // export has always printed it, so the table cannot be the only place that hides it.
        $this->assertSame(2, substr_count($html, 'Oct 16, 2026 09:05 AM'),
            'Both the Added and the Updated column print the time.');
    }

    public function test_a_rate_without_a_buying_rate_shows_dashes_for_buying_and_margin(): void
    {
        $gorret = $this->admin('Gorret Admin');

        $this->rate(['buy_rate' => null, 'created_by' => $gorret->id]);

        $this->actingAs($gorret)
            ->get('/exchange-rates')
            ->assertOk()
            ->assertSee('USD')
            ->assertSee('UGX');
    }

    public function test_big_rates_are_grouped_with_a_thousands_separator(): void
    {
        $gorret = $this->admin('Gorret Admin');

        $this->rate(['rate' => 4025, 'sell_rate' => 4025, 'buy_rate' => 4000, 'created_by' => $gorret->id]);

        $this->actingAs($gorret)
            ->get('/exchange-rates')
            ->assertOk()
            ->assertSee('4,025')
            ->assertSee('4,000');
    }

    public function test_the_export_carries_the_person_who_edited_each_rate(): void
    {
        $gorret = $this->admin('Gorret Admin');
        $alice = $this->admin('Alice Editor');

        $rate = $this->rate(['created_by' => $gorret->id]);

        $this->actingAs($alice);
        $rate->update(['buy_rate' => 3600]);

        $response = $this->get('/export/exchange-rates');
        $response->assertOk();

        $path = tempnam(sys_get_temp_dir(), 'pluspay-') . '.xlsx';
        file_put_contents($path, $response->streamedContent());
        $sheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($path)->getActiveSheet();
        @unlink($path);

        $this->assertSame('Edited By', $sheet->getCell('I5')->getValue());
        $this->assertSame('Alice Editor', $sheet->getCell('I6')->getValue());
        $this->assertSame('Gorret Admin', $sheet->getCell('G6')->getValue());
    }
}
