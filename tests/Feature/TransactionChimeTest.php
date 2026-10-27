<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\ExchangeRate;
use App\Models\ExpenseCategory;
use App\Models\Setting;
use App\Models\User;
use App\Services\AccountLedger;
use App\Support\PermissionCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * A finished transaction is announced twice over: the bell badge moves for the readers, and a
 * short chime plays on the next page the desk opens. The chime is a tone made in the browser, no
 * audio file, and it only arms for the transfer book and the two desks, never the quieter books.
 */
class TransactionChimeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (PermissionCatalog::names() as $name) {
            Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
        }

        foreach (array_keys(PermissionCatalog::defaults()) as $role) {
            Role::firstOrCreate(['name' => $role, 'guard_name' => 'web'])
                ->syncPermissions(PermissionCatalog::defaults()[$role]);
        }

        ExchangeRate::setPair('USD', 'UGX', 3760, 3750, 3760);
        ExchangeRate::setPair('USD', 'RMB', 7.24, 7.20, 7.24);
        Setting::set('default_charge_ugx', '20000', 'float', 'transaction');
    }

    private function desk(string $role = 'admin'): User
    {
        $user = User::create([
            'name' => 'Chime Desk',
            'email' => 'chime.'.uniqid().'@plussave.test',
            'password' => 'Password123',
            'is_active' => true,
        ]);
        $user->assignRole($role);

        return $user;
    }

    private function book(User $by): TestResponse
    {
        $this->actingAs($by)->get('/transactions/create');

        return $this->actingAs($by)->post('/transactions', [
            'form_id' => session('transaction_form_id'),
            'agent_id' => 'counter',
            'sender_name' => 'Sender One',
            'receiver_name' => 'Receiver One',
            'currency' => 'UGX',
            'amount' => '3780000',
            'charge_ugx' => '20000',
            'payment_method' => 'Cash',
        ]);
    }

    public function test_booking_a_transfer_arms_the_chime_for_the_next_page(): void
    {
        $response = $this->book($this->desk());

        $response->assertSessionHasNoErrors();
        $response->assertSessionHas('chime', 'transaction');
    }

    public function test_the_page_that_follows_renders_the_chime_armed(): void
    {
        $by = $this->desk();
        $this->book($by);

        $html = $this->actingAs($by)->get('/transactions')->getContent();

        $this->assertStringContainsString('var wanted = true;', $html);
        $this->assertStringContainsString('pluspay:unread', $html);
    }

    public function test_a_quieter_book_does_not_arm_the_chime(): void
    {
        $by = $this->desk();
        $till = Account::byCode(Account::UGX_UGANDA);
        AccountLedger::record($till, 'opening_balance', '5000000.00', 'Office float');

        $response = $this->actingAs($by)->post('/expenses', [
            'category_id' => ExpenseCategory::where('name', 'Transport')->value('id'),
            'description' => 'Boda to the customer',
            'amount' => '45000.00',
            'account_id' => $till->id,
            'expense_date' => now()->toDateString(),
            'paid_via' => 'Mobile money',
        ]);

        $response->assertSessionHasNoErrors();
        $response->assertSessionMissing('chime');
    }
}
