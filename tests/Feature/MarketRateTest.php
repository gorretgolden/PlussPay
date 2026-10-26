<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\ExchangeRate;
use App\Models\RateSnapshot;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class MarketRateTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['superadmin', 'admin', 'agent'] as $role) {
            Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        }

        Setting::set('market_buy_auto', 'off', 'string', 'rates');
        ExchangeRate::setPair('USD', 'UGX', 4300, 4000, 4300);
        ExchangeRate::setPair('USD', 'RMB', 6.70, 6.75, 6.70);
    }

    private function user(string $role = 'admin'): User
    {
        $user = User::create([
            'name' => 'Market Manager',
            'email' => 'market'.uniqid().'@plussave.test',
            'password' => 'Password123',
            'is_active' => true,
        ]);
        $user->assignRole($role);

        return $user;
    }

    private function fakeFeed(float $ugx = 4177.5965, float $cny = 6.6787, ?string $date = null): void
    {
        $wiseTime = \Illuminate\Support\Carbon::parse($date ?? today()->toDateString())->startOfDay()->getTimestamp() * 1000;

        Http::fake([
            '*target=UGX*' => Http::response(['value' => $ugx, 'time' => $wiseTime]),
            '*target=CNY*' => Http::response(['value' => $cny, 'time' => $wiseTime]),
            '*latest.currency-api.pages.dev*' => Http::response([
                'date' => $date ?? today()->toDateString(),
                'usd' => ['ugx' => $ugx, 'cny' => $cny],
            ]),
            '*open.er-api.com*' => Http::response([
                'result' => 'success',
                'rates' => ['UGX' => $ugx, 'CNY' => $cny],
            ]),
            '*cdn.jsdelivr.net*' => Http::response([
                'date' => today()->subDay()->toDateString(),
                'usd' => ['ugx' => 4150.0, 'cny' => 6.70],
            ]),
        ]);
    }

    public function test_a_pull_reads_wise_first_and_records_source_and_market_day(): void
    {
        $this->fakeFeed(date: '2026-10-21');

        $this->artisan('rates:pull')->assertSuccessful();

        $ugx = RateSnapshot::where('quote_currency', 'UGX')->sole();
        $rmb = RateSnapshot::where('quote_currency', 'RMB')->sole();

        $this->assertSame('USD', $ugx->base_currency);
        $this->assertEqualsWithDelta(4177.5965, (float) $ugx->rate, 0.00000001);
        $this->assertSame('2026-10-21', $ugx->rate_date->toDateString());
        $this->assertSame('Wise', $ugx->source);
        $this->assertNotNull($ugx->fetched_at);
        $this->assertEqualsWithDelta(6.6787, (float) $rmb->rate, 0.00000001);
        $this->assertSame('Wise', $rmb->source);
    }

    public function test_the_next_feed_is_used_when_the_ones_before_it_are_down(): void
    {
        Http::fake([
            '*target=UGX*' => Http::response('unavailable', 503),
            '*currency-api.pages.dev*' => Http::response('unavailable', 503),
            '*open.er-api.com*' => Http::response([
                'result' => 'success',
                'rates' => ['UGX' => 4040.74, 'CNY' => 6.6913],
            ]),
        ]);

        $this->artisan('rates:pull')->assertSuccessful();

        $ugx = RateSnapshot::where('quote_currency', 'UGX')->sole();
        $this->assertEqualsWithDelta(4040.74, (float) $ugx->rate, 0.00000001);
        $this->assertSame('open.er-api.com', $ugx->source);
    }

    public function test_a_pull_with_no_reachable_feed_records_nothing_and_fails(): void
    {
        Http::fake(['*' => Http::response('unavailable', 503)]);

        $this->artisan('rates:pull')
            ->expectsOutputToContain('could not be reached')
            ->assertFailed();

        $this->assertDatabaseCount('rate_snapshots', 0);
    }

    public function test_automation_off_records_the_reading_without_touching_the_pair(): void
    {
        $this->fakeFeed();

        $this->artisan('rates:pull')->assertSuccessful();

        $live = ExchangeRate::getActiveRate('USD', 'UGX');
        $this->assertEqualsWithDelta(4000.0, (float) $live->buy_rate, 0.000001);
        $this->assertEqualsWithDelta(4300.0, (float) $live->sell_rate, 0.000001);
        $this->assertSame(0, ActivityLog::where('action', 'market_buying_rate_applied')->count());
    }

    public function test_automation_on_moves_only_the_buying_rate_when_the_market_sits_below_the_quote(): void
    {
        Setting::set('market_buy_auto', 'on', 'string', 'rates');
        $this->fakeFeed();

        $this->artisan('rates:pull')->assertSuccessful();

        $live = ExchangeRate::getActiveRate('USD', 'UGX');
        $this->assertEqualsWithDelta(4177.5965, (float) $live->buy_rate, 0.000001);
        $this->assertEqualsWithDelta(4300.0, (float) $live->sell_rate, 0.000001);

        $log = ActivityLog::where('action', 'market_buying_rate_applied')->sole();
        $this->assertStringContainsString('Wise', $log->description);
        $this->assertStringContainsString('4,177.60', $log->description);
    }

    public function test_automation_never_pushes_the_pair_into_a_loss(): void
    {
        ExchangeRate::setPair('USD', 'UGX', 3760, 3700, 3760);
        Setting::set('market_buy_auto', 'on', 'string', 'rates');
        $this->fakeFeed();

        $this->artisan('rates:pull')->assertSuccessful();

        $live = ExchangeRate::getActiveRate('USD', 'UGX');
        $this->assertEqualsWithDelta(3700.0, (float) $live->buy_rate, 0.000001);
        $this->assertSame(0, ActivityLog::where('action', 'market_buying_rate_applied')->count());

        $this->actingAs($this->user())->get('/settings')
            ->assertOk()
            ->assertSee('at or above your selling rate');
    }

    public function test_automation_does_not_use_a_reading_older_than_a_day(): void
    {
        RateSnapshot::create([
            'base_currency' => 'USD',
            'quote_currency' => 'UGX',
            'rate' => 4177.5965,
            'rate_date' => today()->subDays(2)->toDateString(),
            'source' => 'currency-api.pages.dev',
            'fetched_at' => now()->subDays(2),
        ]);
        $this->actingAs($this->user())
            ->put('/settings', ['settings_tab' => 'rates', 'market_buy_auto' => 'on'])
            ->assertSessionHasNoErrors();

        $live = ExchangeRate::getActiveRate('USD', 'UGX');
        $this->assertEqualsWithDelta(4000.0, (float) $live->buy_rate, 0.000001);
        $this->assertSame(0, ActivityLog::where('action', 'market_buying_rate_applied')->count());
    }

    public function test_a_save_with_automation_already_on_keeps_the_typed_buying_rate(): void
    {
        Setting::set('market_buy_auto', 'on', 'string', 'rates');
        RateSnapshot::create([
            'base_currency' => 'USD',
            'quote_currency' => 'UGX',
            'rate' => 4177.5965,
            'rate_date' => today()->toDateString(),
            'source' => 'currency-api.pages.dev',
            'fetched_at' => now(),
        ]);

        $this->actingAs($this->user())
            ->put('/settings', [
                'settings_tab' => 'rates',
                'market_buy_auto' => 'on',
                'usd_ugx_buying' => 4050,
                'usd_ugx_selling' => 4300,
            ])
            ->assertSessionHasNoErrors();

        $live = ExchangeRate::getActiveRate('USD', 'UGX');
        $this->assertEqualsWithDelta(4050.0, (float) $live->buy_rate, 0.000001);
        $this->assertSame(0, ActivityLog::where('action', 'market_buying_rate_applied')->count());
    }

    public function test_enabling_the_switch_applies_a_fresh_reading_without_waiting_for_a_pull(): void
    {
        RateSnapshot::create([
            'base_currency' => 'USD',
            'quote_currency' => 'UGX',
            'rate' => 4177.5965,
            'rate_date' => today()->toDateString(),
            'source' => 'currency-api.pages.dev',
            'fetched_at' => now(),
        ]);

        $this->actingAs($this->user())
            ->put('/settings', ['settings_tab' => 'rates', 'market_buy_auto' => 'on'])
            ->assertSessionHasNoErrors();

        $live = ExchangeRate::getActiveRate('USD', 'UGX');
        $this->assertEqualsWithDelta(4177.5965, (float) $live->buy_rate, 0.000001);
        $this->assertDatabaseHas('activity_logs', ['action' => 'market_buying_rate_applied']);
    }

    public function test_the_card_shows_the_source_time_direction_and_sparkline(): void
    {
        RateSnapshot::create([
            'base_currency' => 'USD',
            'quote_currency' => 'UGX',
            'rate' => 4150.0,
            'rate_date' => today()->subDay()->toDateString(),
            'source' => 'currency-api.pages.dev',
            'fetched_at' => now()->subDay(),
        ]);
        RateSnapshot::create([
            'base_currency' => 'USD',
            'quote_currency' => 'UGX',
            'rate' => 4177.5965,
            'rate_date' => today()->toDateString(),
            'source' => 'currency-api.pages.dev',
            'fetched_at' => now(),
        ]);
        RateSnapshot::create([
            'base_currency' => 'USD',
            'quote_currency' => 'RMB',
            'rate' => 6.6787,
            'rate_date' => today()->toDateString(),
            'source' => 'currency-api.pages.dev',
            'fetched_at' => now(),
        ]);

        $this->actingAs($this->user())->get('/settings')
            ->assertOk()
            ->assertSee('Live market rate')
            ->assertSee('4,177.60')
            ->assertSee('Increased by 27.60')
            ->assertSee('currency-api.pages.dev')
            ->assertSee('<polyline', false)
            ->assertSee('First reading recorded')
            ->assertSee('Recent daily readings')
            ->assertSee('Dollar to yuan');
    }

    public function test_the_card_starts_empty_until_the_first_pull(): void
    {
        $this->actingAs($this->user())->get('/settings')
            ->assertOk()
            ->assertSee('No reading yet')
            ->assertSee('Never pulled')
            ->assertDontSee('Recent daily readings');
    }

    public function test_the_pull_button_records_a_reading_and_returns_to_settings(): void
    {
        $this->fakeFeed();

        $this->actingAs($this->user())
            ->from('/settings')
            ->post('/settings/market-rates/pull')
            ->assertRedirect('/settings')
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success');

        $this->assertDatabaseCount('rate_snapshots', 2);
    }

    public function test_only_settings_managers_can_pull(): void
    {
        $this->actingAs($this->user('agent'))
            ->post('/settings/market-rates/pull')
            ->assertForbidden();

        $this->assertDatabaseCount('rate_snapshots', 0);
    }

    public function test_the_switch_saves_with_the_rates_tab_and_cannot_be_changed_from_another_tab(): void
    {
        $this->actingAs($this->user())
            ->put('/settings', ['settings_tab' => 'rates', 'market_buy_auto' => 'on'])
            ->assertSessionHasNoErrors();

        $this->assertSame('on', Setting::get('market_buy_auto'));

        $this->put('/settings', [
            'settings_tab' => 'company',
            'company_name' => 'Somewhere else',
            'market_buy_auto' => 'off',
        ])->assertSessionHasNoErrors();

        $this->assertSame('on', Setting::get('market_buy_auto'));

        $this->put('/settings', ['settings_tab' => 'rates', 'market_buy_auto' => 'yes'])
            ->assertSessionHasErrors('market_buy_auto');
    }

    public function test_backfill_records_each_earlier_day_once(): void
    {
        Http::fake([
            '*latest.currency-api.pages.dev*' => Http::response([
                'date' => today()->toDateString(),
                'usd' => ['ugx' => 4177.0, 'cny' => 6.67],
            ]),
            '*cdn.jsdelivr.net*' => Http::sequence()
                ->push([
                    'date' => today()->subDay()->toDateString(),
                    'usd' => ['ugx' => 4150.0, 'cny' => 6.70],
                ])
                ->push([
                    'date' => today()->subDays(2)->toDateString(),
                    'usd' => ['ugx' => 4140.0, 'cny' => 6.71],
                ]),
        ]);

        $this->artisan('rates:pull', ['--backfill' => 2])->assertSuccessful();

        $this->assertDatabaseCount('rate_snapshots', 6);
        $this->assertSame(1, RateSnapshot::where('quote_currency', 'UGX')->whereDate('rate_date', today()->subDay()->toDateString())->count());
        $this->assertSame(1, RateSnapshot::where('quote_currency', 'UGX')->whereDate('rate_date', today()->subDays(2)->toDateString())->count());

        $this->artisan('rates:pull', ['--backfill' => 2])->assertSuccessful();

        $this->assertDatabaseCount('rate_snapshots', 8);
        $this->assertSame(1, RateSnapshot::where('quote_currency', 'UGX')->whereDate('rate_date', today()->subDay()->toDateString())->count());
    }
}
