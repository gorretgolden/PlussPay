<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\ExchangeRate;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * The guard added in 0df632d stops new reverse-direction rows from ever coming into use, but the
 * rows the old free pair picker already made stay where they are until someone sets them aside.
 * This command is that someone, so the desk is not left clicking a switch per leftover row.
 */
class StrayRateCleanupTest extends TestCase
{
    use RefreshDatabase;

    private ExchangeRate $shilling;

    private ExchangeRate $yuan;

    private ExchangeRate $reverse;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['superadmin', 'admin'] as $role) {
            Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        }

        $this->shilling = ExchangeRate::setPair('USD', 'UGX', 3772, 3758, 3772);
        $this->yuan = ExchangeRate::setPair('USD', 'RMB', 7.26, 7.20, 7.26);

        // What the removed pair picker used to save: the same two figures written the other way
        // round, live at the same time as the price the desk actually reads.
        $this->reverse = ExchangeRate::create([
            'from_currency' => 'UGX', 'to_currency' => 'USD', 'rate' => 0.00027,
            'buy_rate' => 0.00026, 'sell_rate' => 0.00027, 'is_active' => true,
        ]);
    }

    public function test_a_dry_run_names_the_row_that_is_not_read_and_touches_nothing(): void
    {
        $this->artisan('exchange-rates:set-aside-strays')
            ->expectsOutputToContain('UGX → USD 0.00027  would go out of use: the desk does not read money in that direction.')
            ->expectsOutputToContain('Dry run: nothing was changed. Add --apply to take the 1 row out of use.')
            ->assertExitCode(0);

        $this->assertTrue($this->reverse->fresh()->is_active);
        $this->assertSame(0, ActivityLog::where('action', 'exchange_rate_deactivated')->count());
    }

    public function test_applying_sets_the_reverse_row_aside_and_keeps_both_desk_prices_reading(): void
    {
        $this->artisan('exchange-rates:set-aside-strays --apply')
            ->expectsOutputToContain('UGX → USD 0.00027  out of use')
            ->expectsOutputToContain('1 row out of use. USD → UGX and USD → RMB still price bookings, and nothing was deleted.')
            ->assertExitCode(0);

        $this->assertFalse($this->reverse->fresh()->is_active);
        $this->assertTrue($this->shilling->fresh()->is_active);
        $this->assertTrue($this->yuan->fresh()->is_active);

        // The row stays on the record with its figures and its author, because transfers entered
        // while it was live are read back from it.
        $stray = $this->reverse->fresh();
        $this->assertSame('0.000270', (string) $stray->sell_rate);
        $this->assertSame($this->reverse->created_at->toDateString(), $stray->created_at->toDateString());

        $log = ActivityLog::where('action', 'exchange_rate_deactivated')->sole();
        $this->assertStringContainsString('taken out of use by exchange-rates:set-aside-strays', $log->description);
        $this->assertSame('in use', $log->old_value);
        $this->assertNull($log->user_id);
    }

    public function test_a_second_live_row_for_a_priced_pair_goes_out_of_use_and_the_newest_price_stays(): void
    {
        $newer = ExchangeRate::create([
            'from_currency' => 'USD', 'to_currency' => 'UGX', 'rate' => 3800,
            'buy_rate' => 3790, 'sell_rate' => 3800, 'is_active' => true,
        ]);

        $this->artisan('exchange-rates:set-aside-strays --apply')
            ->expectsOutputToContain('USD → UGX 3772  out of use: this pair already has a price in use.')
            ->expectsOutputToContain('2 rows out of use.')
            ->assertExitCode(0);

        $this->assertFalse($this->shilling->fresh()->is_active);
        $this->assertTrue($newer->fresh()->is_active);
        $this->assertSame('3800.000000', (string) ExchangeRate::getActiveRate('USD', 'UGX')->rate);
    }

    public function test_it_says_so_when_only_the_two_desk_prices_are_in_use(): void
    {
        $this->reverse->update(['is_active' => false]);

        $this->artisan('exchange-rates:set-aside-strays')
            ->expectsOutput('The prices in use are USD → UGX and USD → RMB, which is what the desk reads. Nothing to set aside.')
            ->assertExitCode(0);
    }
}
