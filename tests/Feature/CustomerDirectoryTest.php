<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\ExchangeRate;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class CustomerDirectoryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['superadmin', 'admin', 'agent', 'staff'] as $role) {
            Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        }

        ExchangeRate::setPair('USD', 'UGX', 4000, 3600, 4000);
    }

    private function admin(string $name = 'Gorret Admin'): User
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

    private function transfer(User $by, array $overrides = []): Transaction
    {
        return Transaction::create(array_merge([
            'receipt_number' => 'PS-' . uniqid(),
            'sender_name' => 'Nabatanzi Gorret',
            'sender_contact' => '0751657654',
            'receiver_name' => 'Marvin Chen',
            'receiver_contact' => '8613800000001',
            'currency' => 'UGX',
            'amount' => 50000,
            'exchange_rate' => 4000,
            'rmb_amount' => 88.24,
            'charge_rate' => 0.02,
            'charge_amount' => 0.25,
            'charge_ugx' => 1000,
            'total_usd_equivalent' => 12.5,
            'status' => 'completed',
            'created_by' => $by->id,
        ], $overrides));
    }

    public function test_a_transfer_files_both_sides_into_the_directory(): void
    {
        $this->transfer($this->admin());

        $sender = Customer::where('name', 'Nabatanzi Gorret')->first();
        $receiver = Customer::where('name', 'Marvin Chen')->first();

        $this->assertNotNull($sender);
        $this->assertNotNull($receiver);
        $this->assertSame('sender', $sender->type);
        $this->assertSame('Uganda', $sender->country);
        $this->assertSame('receiver', $receiver->type);
        $this->assertSame('China', $receiver->country);
        $this->assertSame('+256751657654', $sender->phone);
    }

    public function test_the_same_person_is_not_filed_twice(): void
    {
        $admin = $this->admin();

        $this->transfer($admin);
        $this->transfer($admin, ['sender_contact' => '0751 657 654', 'amount' => 80000]);

        $this->assertSame(1, Customer::where('name', 'Nabatanzi Gorret')->count());
        $this->assertSame(2, Customer::count(), 'Only the sender and the receiver, however many transfers.');
    }

    public function test_someone_who_sends_and_receives_is_marked_both(): void
    {
        $admin = $this->admin();

        $this->transfer($admin);
        $this->transfer($admin, [
            'sender_name' => 'Marvin Chen',
            'sender_contact' => '8613800000001',
            'receiver_name' => 'Nabatanzi Gorret',
            'receiver_contact' => '+256751657654',
        ]);

        $this->assertSame(2, Customer::count());
        $this->assertSame('both', Customer::where('name', 'Marvin Chen')->value('type'));
        $this->assertSame('both', Customer::where('name', 'Nabatanzi Gorret')->value('type'));
    }

    public function test_a_customer_someone_deleted_stays_deleted(): void
    {
        $admin = $this->admin();

        $this->transfer($admin);
        Customer::where('name', 'Marvin Chen')->first()->delete();

        $this->transfer($admin, ['amount' => 90000]);

        $this->assertSame(0, Customer::where('name', 'Marvin Chen')->count());
        $this->assertSame(1, Customer::withTrashed()->where('name', 'Marvin Chen')->count());
    }

    public function test_past_transfers_can_be_filed_in_one_go(): void
    {
        $admin = $this->admin();

        $this->transfer($admin);
        Customer::query()->forceDelete();

        $this->artisan('customers:backfill')->assertSuccessful();

        $this->assertSame(2, Customer::count());
    }

    public function test_the_backfill_dry_run_reports_without_saving(): void
    {
        $admin = $this->admin();

        $this->transfer($admin);
        Customer::query()->forceDelete();

        $this->artisan('customers:backfill', ['--dry-run' => true])
            ->assertSuccessful()
            ->expectsOutput('2 customer(s) would be added from existing transfers. Nothing was saved.');

        $this->assertSame(0, Customer::count());
    }

    public function test_a_transfer_with_no_contact_number_still_books_and_still_files(): void
    {
        $admin = $this->admin();

        $transaction = $this->transfer($admin, [
            'sender_name' => 'Walk In Sender',
            'sender_contact' => '',
            'receiver_contact' => '',
        ]);

        $this->assertNotNull($transaction->id);

        $sender = Customer::where('name', 'Walk In Sender')->first();

        $this->assertNotNull($sender);
        $this->assertNull($sender->phone);
        $this->assertSame('receiver', Customer::where('name', 'Marvin Chen')->value('type'));
    }

    public function test_the_directory_page_shows_the_people_from_a_transfer(): void
    {
        $admin = $this->admin();

        $this->transfer($admin);

        $this->actingAs($admin)->get('/customers')
            ->assertOk()
            ->assertSee('Nabatanzi Gorret')
            ->assertSee('Marvin Chen');
    }
}
