<?php

namespace Tests\Feature;

use App\Models\ExchangeRate;
use App\Models\Setting;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * The charge is agreed with each customer, so an imported file has to carry real figures.
 * Nothing here may invent one for a row that left the column blank.
 */
class BulkImportChargeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Permission::firstOrCreate(['name' => 'bulk-import-transactions', 'guard_name' => 'web']);
        $role = Role::firstOrCreate(['name' => 'agent', 'guard_name' => 'web']);
        $role->givePermissionTo('bulk-import-transactions');

        $user = User::create([
            'name' => 'Desk Agent',
            'email' => 'importagent' . uniqid() . '@plussave.test',
            'password' => 'Password123',
            'is_active' => true,
        ]);
        $user->assignRole('agent');
        $this->actingAs($user);

        ExchangeRate::setPair('USD', 'UGX', 3760, 3750, 3760);
        ExchangeRate::setPair('USD', 'RMB', 7.24, 7.24, 7.24);
        Setting::set('service_fee_ugx', '2000', 'float', 'transaction');
        Setting::set('service_fee_usd', '100', 'float', 'transaction');
    }

    private function import(string $csv): void
    {
        $this->post('/transactions/bulk/import', [
            'file' => UploadedFile::fake()->createWithContent('rows.csv', $csv),
        ]);
    }

    public function test_a_charge_written_in_the_file_is_booked_exactly_as_written(): void
    {
        $this->import("sender_name,receiver_name,currency,amount,charge_ugx\nAisha Li,Wei Zhang,UGX,500000,20000\n");

        $transaction = Transaction::sole();
        $this->assertSame('20000.00', (string) $transaction->charge_ugx);
        $this->assertSame('480000.00', (string) $transaction->amount);
    }

    public function test_a_blank_charge_is_booked_as_no_charge_rather_than_a_guessed_one(): void
    {
        $this->import("sender_name,receiver_name,currency,amount,charge_ugx\nAisha Li,Wei Zhang,UGX,500000,\n");

        $transaction = Transaction::sole();
        $this->assertSame('0.00', (string) $transaction->charge_ugx);
        $this->assertSame('500000.00', (string) $transaction->amount);
    }
}
