<?php

namespace Tests\Feature;

use App\Models\ExchangeRate;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class BulkImportDuplicateTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['superadmin', 'admin', 'agent', 'staff'] as $role) {
            Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        }

        $admin = $this->admin();
        $this->actingAs($admin);

        ExchangeRate::setPair('USD', 'UGX', 3850, 3600, 3850);
        ExchangeRate::setPair('USD', 'RMB', 7.2, 7.2, 7.2);
    }

    private function admin(): User
    {
        $user = User::create([
            'name' => 'Gorret Admin',
            'email' => 'gorret.admin' . uniqid() . '@plussave.test',
            'password' => 'Password123',
            'is_active' => true,
        ]);

        $user->assignRole('admin');

        return $user;
    }

    private function upload(string $csv)
    {
        return $this->post('/transactions/bulk/import', [
            'file' => UploadedFile::fake()->createWithContent('transfers.csv', $csv),
        ]);
    }

    private function rowsCsv(int $times = 1, string $contact = '0772000111'): string
    {
        $header = 'sender_name,sender_contact,receiver_name,receiver_contact,currency,amount,purpose,notes';
        $line = "Sarah Auma,{$contact},Li Wei,8613800002222,UGX,1200000,school fees,";

        return $header . "\n" . implode("\n", array_fill(0, $times, $line)) . "\n";
    }

    public function test_uploading_the_same_file_twice_books_the_transfer_once(): void
    {
        $csv = $this->rowsCsv();

        $this->upload($csv)->assertRedirect();
        $this->assertSame(1, Transaction::count());

        $this->upload($csv)->assertSessionHas('import_errors');

        $this->assertSame(1, Transaction::count(), 'A repeat upload must not book the transfer again.');
        $this->assertStringContainsString(Transaction::first()->receipt_number, implode(' ', session('import_errors')));
    }

    public function test_a_repeat_inside_one_file_is_rejected(): void
    {
        $this->upload($this->rowsCsv(2))->assertRedirect();

        $this->assertSame(1, Transaction::count());
    }

    public function test_a_phone_written_a_different_way_is_still_a_repeat(): void
    {
        $this->upload($this->rowsCsv())->assertRedirect();

        $this->upload($this->rowsCsv(1, '+256 772 000 111'));

        $this->assertSame(1, Transaction::count());
    }

    public function test_a_different_transfer_still_imports(): void
    {
        $this->upload($this->rowsCsv())->assertRedirect();

        $other = str_replace('Li Wei,8613800002222', 'Li Ming,8613800003333', $this->rowsCsv());
        $this->upload($other)->assertRedirect();

        $this->assertSame(2, Transaction::count());
    }
}
