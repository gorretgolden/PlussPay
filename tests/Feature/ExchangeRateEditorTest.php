<?php

namespace Tests\Feature;

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

        $this->actingAs($gorret)
            ->post('/exchange-rates', [
                'from_currency' => 'USD',
                'to_currency' => 'UGX',
                'rate' => '3772',
                'buy_rate' => '3758',
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
            ->post('/exchange-rates', [
                'from_currency' => 'USD',
                'to_currency' => 'UGX',
                'rate' => '3790',
                'buy_rate' => '3775',
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame($alice->id, $old->fresh()->updated_by);
        $this->assertFalse($old->fresh()->is_active);
    }

    public function test_switching_a_rate_on_records_the_person_who_clicked_it(): void
    {
        $alice = $this->admin('Alice Admin');

        $this->rate();
        $dormant = $this->rate(['rate' => 3700, 'sell_rate' => 3700, 'is_active' => false]);

        $this->actingAs($alice)
            ->patch('/exchange-rates/'.$dormant->id.'/toggle')
            ->assertSessionHasNoErrors();

        $this->assertTrue($dormant->fresh()->is_active);
        $this->assertSame($alice->id, $dormant->fresh()->updated_by);
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
            ->assertSee('Edited By')
            ->assertSee('Alice Editor');
    }

    public function test_a_rate_without_a_buying_rate_reads_pending_rather_than_a_dash(): void
    {
        $gorret = $this->admin('Gorret Admin');

        $this->rate(['buy_rate' => null, 'created_by' => $gorret->id]);

        $this->actingAs($gorret)
            ->get('/exchange-rates')
            ->assertOk()
            ->assertSee('Pending')
            ->assertSee('Not set');
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
