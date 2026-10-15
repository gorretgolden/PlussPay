<?php

namespace Tests\Feature;

use App\Models\ExchangeRate;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ExchangeRateAuthorshipTest extends TestCase
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

    public function test_saving_a_rate_records_who_added_it(): void
    {
        $gorret = $this->admin('Gorret Admin');

        $this->actingAs($gorret)
            ->put('/settings', [
                'usd_ugx_selling' => '3772',
                'usd_ugx_buying' => '3758',
            ])
            ->assertSessionHasNoErrors();

        $rate = ExchangeRate::where('to_currency', 'UGX')->first();

        $this->assertSame($gorret->id, $rate->created_by);
    }

    public function test_the_rates_page_shows_who_added_each_rate(): void
    {
        $gorret = $this->admin('Gorret Admin');

        ExchangeRate::create([
            'from_currency' => 'USD',
            'to_currency' => 'UGX',
            'rate' => 3772,
            'is_active' => true,
            'created_by' => $gorret->id,
        ]);

        $this->actingAs($this->admin('Page Viewer'))
            ->get('/exchange-rates')
            ->assertOk()
            ->assertSee('Gorret Admin');
    }

    public function test_rates_can_be_filtered_by_the_person_who_added_them(): void
    {
        $gorret = $this->admin('Gorret Admin');
        $other = $this->admin('Other Admin');

        ExchangeRate::create([
            'from_currency' => 'USD', 'to_currency' => 'UGX', 'rate' => 3772,
            'is_active' => true, 'created_by' => $gorret->id,
        ]);
        ExchangeRate::create([
            'from_currency' => 'USD', 'to_currency' => 'RMB', 'rate' => 6.75,
            'is_active' => true, 'created_by' => $other->id,
        ]);

        $response = $this->actingAs($gorret)->get('/exchange-rates?created_by=' . $gorret->id);

        $response->assertOk()->assertSee('Gorret Admin');

        // Both names stay in the Added By dropdown, so check the rows rather than the page text.
        $rates = $response->viewData('rates')->getCollection();

        $this->assertSame(1, $rates->count());
        $this->assertSame('UGX', $rates->first()->to_currency);
    }

    public function test_rates_can_be_filtered_by_the_day_they_were_added(): void
    {
        $admin = $this->admin('Gorret Admin');

        $old = ExchangeRate::create([
            'from_currency' => 'USD', 'to_currency' => 'UGX', 'rate' => 3772,
            'is_active' => true, 'created_by' => $admin->id,
        ]);
        $old->forceFill(['created_at' => now()->subMonths(3)])->save();

        ExchangeRate::create([
            'from_currency' => 'USD', 'to_currency' => 'RMB', 'rate' => 6.75,
            'is_active' => true, 'created_by' => $admin->id,
        ]);

        $response = $this->actingAs($admin)->get('/exchange-rates?date_from=' . now()->format('Y-m-d'));

        $rates = $response->viewData('rates')->getCollection();

        $this->assertSame(1, $rates->count());
        $this->assertFalse($rates->contains('id', $old->id));
    }

    public function test_rates_can_be_searched_by_currency_or_by_the_person_who_added_them(): void
    {
        $gorret = $this->admin('Gorret Admin');

        ExchangeRate::create([
            'from_currency' => 'USD', 'to_currency' => 'UGX', 'rate' => 3772,
            'is_active' => true, 'created_by' => $gorret->id,
        ]);
        ExchangeRate::create([
            'from_currency' => 'USD', 'to_currency' => 'RMB', 'rate' => 6.75,
            'is_active' => true,
        ]);

        $byCurrency = $this->actingAs($gorret)->get('/exchange-rates?search=ugx')
            ->viewData('rates')->getCollection();
        $this->assertSame(['UGX'], $byCurrency->pluck('to_currency')->all());

        $byPerson = $this->actingAs($gorret)->get('/exchange-rates?search=Gorret')
            ->viewData('rates')->getCollection();
        $this->assertSame(['UGX'], $byPerson->pluck('to_currency')->all());
    }

    public function test_prices_are_set_in_settings_and_each_row_can_be_corrected_on_this_page(): void
    {
        $admin = $this->admin('Gorret Admin');

        ExchangeRate::create([
            'from_currency' => 'USD', 'to_currency' => 'UGX', 'rate' => 3772,
            'sell_rate' => 3772, 'buy_rate' => 3758, 'is_active' => true,
            'created_by' => $admin->id,
        ]);

        $this->actingAs($admin)
            ->get('/exchange-rates')
            ->assertOk()
            ->assertDontSee('Add Exchange Rate')
            ->assertDontSee('addRateModal', false)
            ->assertSee('Update rates')
            ->assertSee('Save')
            ->assertSee('Yesterday');
    }

    public function test_the_rates_export_carries_the_person_who_added_each_rate(): void
    {
        $gorret = $this->admin('Gorret Admin');

        ExchangeRate::create([
            'from_currency' => 'USD', 'to_currency' => 'UGX', 'rate' => 3772,
            'is_active' => true, 'created_by' => $gorret->id,
        ]);

        $response = $this->actingAs($gorret)->get('/export/exchange-rates');
        $response->assertOk();

        $path = tempnam(sys_get_temp_dir(), 'pluspay-') . '.xlsx';
        file_put_contents($path, $response->streamedContent());
        $sheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($path)->getActiveSheet();
        @unlink($path);

        $this->assertSame('PlusPay', $sheet->getCell('A1')->getValue());
        $this->assertSame('Exchange Rates', $sheet->getTitle());
        $this->assertSame('Added By', $sheet->getCell('G5')->getValue());
        $this->assertSame('Gorret Admin', $sheet->getCell('G6')->getValue());
    }
}
