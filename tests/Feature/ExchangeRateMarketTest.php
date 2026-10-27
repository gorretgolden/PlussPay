<?php

namespace Tests\Feature;

use App\Models\ExchangeRate;
use App\Models\RateSnapshot;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ExchangeRateMarketTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['superadmin', 'admin', 'agent'] as $role) {
            Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        }

        ExchangeRate::setPair('USD', 'UGX', 4300, 4000, 4300);
        ExchangeRate::setPair('USD', 'RMB', 6.70, 6.75, 6.70);
    }

    private function user(string $role = 'superadmin'): User
    {
        $user = User::create([
            'name' => 'Market Desk',
            'email' => 'desk'.uniqid().'@plussave.test',
            'password' => 'Password123',
            'is_active' => true,
        ]);
        $user->assignRole($role);

        return $user;
    }

    private function reading(string $quote, float $rate, ?\Illuminate\Support\Carbon $at = null): RateSnapshot
    {
        return RateSnapshot::create([
            'base_currency' => 'USD',
            'quote_currency' => $quote,
            'rate' => $rate,
            'rate_date' => ($at ?? now())->toDateString(),
            'source' => 'Wise',
            'fetched_at' => $at ?? now(),
        ]);
    }

    public function test_the_page_offers_the_pull_and_use_actions_with_the_reading_time(): void
    {
        $this->reading('UGX', 4177.5965, now()->setTime(8, 5));
        $this->reading('RMB', 6.6787, now()->setTime(8, 5));

        $this->actingAs($this->user())
            ->get('/exchange-rates')
            ->assertOk()
            ->assertSee('Live market rate')
            ->assertSee('Pull now')
            ->assertSee('Use as buying price')
            ->assertSee('Use as selling price')
            ->assertSee('08:05 AM')
            ->assertSee('Recent daily readings');
    }

    public function test_using_the_shilling_reading_sets_the_buying_price_and_keeps_selling(): void
    {
        $this->reading('UGX', 4177.5965, now()->setTime(8, 5));

        $this->actingAs($this->user())
            ->post('/exchange-rates/market/use', ['quote' => 'UGX'])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success');

        $live = ExchangeRate::getActiveRate('USD', 'UGX');
        $this->assertSame(4300.0, (float) $live->sell_rate);
        $this->assertEqualsWithDelta(4177.5965, (float) $live->buy_rate, 0.000001);
        $this->assertSame(1, ExchangeRate::where('from_currency', 'USD')->where('to_currency', 'UGX')->where('is_active', true)->count());
        $this->assertDatabaseHas('activity_logs', ['action' => 'exchange_rate_used_market']);
    }

    public function test_using_the_yuan_reading_sets_the_selling_price_and_keeps_buying(): void
    {
        $this->reading('RMB', 6.6787, now()->setTime(8, 5));

        $this->actingAs($this->user())
            ->post('/exchange-rates/market/use', ['quote' => 'RMB'])
            ->assertSessionHasNoErrors();

        $live = ExchangeRate::getActiveRate('USD', 'RMB');
        $this->assertEqualsWithDelta(6.6787, (float) $live->sell_rate, 0.000001);
        $this->assertSame(6.75, (float) $live->buy_rate);
    }

    public function test_a_market_reading_that_would_cross_the_spread_is_refused(): void
    {
        $this->reading('UGX', 4300, now()->setTime(8, 5));

        $this->actingAs($this->user())
            ->post('/exchange-rates/market/use', ['quote' => 'UGX'])
            ->assertSessionHasErrors('market_rate');

        $live = ExchangeRate::getActiveRate('USD', 'UGX');
        $this->assertSame(4000.0, (float) $live->buy_rate);
    }

    public function test_a_stale_reading_cannot_be_used(): void
    {
        $this->reading('UGX', 4177.5965, now()->subDays(3));

        $this->actingAs($this->user())
            ->post('/exchange-rates/market/use', ['quote' => 'UGX'])
            ->assertSessionHasErrors('market_rate');

        $this->assertSame(4000.0, (float) ExchangeRate::getActiveRate('USD', 'UGX')->buy_rate);
    }

    public function test_pulling_from_the_rates_page_records_both_readings(): void
    {
        Http::fake([
            '*target=UGX*' => Http::response(['value' => 4177.5965, 'time' => now()->getTimestamp() * 1000]),
            '*target=CNY*' => Http::response(['value' => 6.6787, 'time' => now()->getTimestamp() * 1000]),
        ]);

        $this->actingAs($this->user())
            ->from('/exchange-rates')
            ->post('/exchange-rates/market/pull')
            ->assertRedirect('/exchange-rates')
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success');

        $this->assertDatabaseCount('rate_snapshots', 2);
    }

    public function test_an_agent_cannot_pull_or_use_a_reading(): void
    {
        $this->reading('UGX', 4177.5965);

        $agent = $this->user('agent');

        $this->actingAs($agent)->post('/exchange-rates/market/pull')->assertForbidden();
        $this->actingAs($agent)->post('/exchange-rates/market/use', ['quote' => 'UGX'])->assertForbidden();
    }

    public function test_the_pdf_downloads_with_a_file_name_instead_of_opening_print(): void
    {
        ExchangeRate::create([
            'from_currency' => 'USD',
            'to_currency' => 'UGX',
            'rate' => 4300,
            'sell_rate' => 4300,
            'buy_rate' => 4177.5965,
            'is_active' => false,
        ]);

        $response = $this->actingAs($this->user())->get('/exchange-rates/pdf?status=');

        $response->assertOk();
        $response->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringContainsString('attachment; filename="PlusPay_Exchange_Rates_', $response->headers->get('Content-Disposition'));
        $this->assertStringStartsWith('%PDF', $response->getContent());
        $this->assertDatabaseHas('activity_logs', ['action' => 'exported']);
    }
}
