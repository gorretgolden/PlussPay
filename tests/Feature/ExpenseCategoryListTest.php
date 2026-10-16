<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\User;
use App\Services\AccountLedger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * The spending heads used to be a list inside the model, so adding one meant changing code. These
 * hold the promise made on that page: an admin edits the list from the screen, a head that has
 * been used is retired rather than cleared away, and nothing that was already paid gets re-filed
 * behind the user's back.
 */
class ExpenseCategoryListTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['superadmin', 'admin', 'accountant', 'agent', 'staff'] as $role) {
            Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        }

        Permission::firstOrCreate(['name' => 'manage-expense-categories', 'guard_name' => 'web']);

        $this->admin = User::create([
            'name' => 'Heads Admin',
            'email' => 'heads'.uniqid().'@plussave.test',
            'password' => 'Password123',
            'is_active' => true,
        ]);

        $this->admin->assignRole('admin');
        $this->admin->givePermissionTo('manage-expense-categories');
    }

    public function test_the_heads_from_the_old_hard_coded_list_are_already_on_the_page(): void
    {
        $this->actingAs($this->admin)->get('/expenses/categories')
            ->assertOk()
            ->assertSee('Rent')
            ->assertSee('Bank charges')
            ->assertSee('Airtime and internet');
    }

    public function test_an_admin_adds_a_head_and_it_immediately_offers_itself_on_the_expense_form(): void
    {
        $this->actingAs($this->admin)->post('/expenses/categories', ['name' => '  Courier  '])
            ->assertSessionHasNoErrors();

        $head = ExpenseCategory::where('name', 'Courier')->firstOrFail();

        $this->assertSame('Courier', $head->name, 'The spaces around what was typed are trimmed away.');
        $this->assertTrue($head->is_active);
        $this->actingAs($this->admin)->get('/expenses/create')->assertSee('Courier');
    }

    public function test_a_head_already_on_the_list_cannot_be_added_twice(): void
    {
        $this->actingAs($this->admin)->post('/expenses/categories', ['name' => 'Rent'])
            ->assertSessionHasErrors('name');

        $this->assertSame(1, ExpenseCategory::where('name', 'Rent')->count());
    }

    public function test_renaming_a_head_moves_the_payments_filed_under_it(): void
    {
        $till = Account::byCode(Account::UGX_UGANDA);
        AccountLedger::record($till, 'opening_balance', '5000000.00', 'Float');

        $expense = Expense::record([
            'expense_date' => now()->toDateString(),
            'category_id' => ExpenseCategory::where('name', 'Rent')->value('id'),
            'category' => 'Rent',
            'description' => 'Kampala office',
            'currency' => 'UGX',
            'amount' => '1000.00',
            'account_id' => $till->id,
            'created_by' => $this->admin->id,
        ]);

        $head = $expense->categoryGroup;
        $head->update(['name' => 'Office rent']);

        $this->assertSame('Office rent', $expense->fresh()->category_name);
        $this->assertSame('Rent', $expense->fresh()->category, 'The name the ledger line was written under stays as it was.');

        $this->actingAs($this->admin)->get('/expenses?category=Office rent')
            ->assertOk()
            ->assertSee('Kampala office');
    }

    public function test_a_retired_head_leaves_the_form_but_keeps_its_history_reachable(): void
    {
        $head = ExpenseCategory::where('name', 'Licences and permits')->firstOrFail();

        $this->actingAs($this->admin)->put('/expenses/categories/'.$head->id, [
            'name' => 'Licences and permits',
        ])->assertSessionHasNoErrors();

        $this->assertFalse($head->fresh()->is_active);
        $this->actingAs($this->admin)->get('/expenses/create')
            ->assertViewHas('categories', fn (array $options) => !in_array('Licences and permits', $options, true));

        $head->refresh()->update(['is_active' => true]);
        $this->actingAs($this->admin)->get('/expenses/create')
            ->assertViewHas('categories', fn (array $options) => in_array('Licences and permits', $options, true));
    }

    public function test_saving_an_expense_under_a_head_that_is_no_longer_offered_is_refused(): void
    {
        $till = Account::byCode(Account::UGX_UGANDA);
        AccountLedger::record($till, 'opening_balance', '5000000.00', 'Float');
        $head = ExpenseCategory::where('name', 'Rent')->firstOrFail();
        $head->update(['is_active' => false]);

        $this->actingAs($this->admin)->post('/expenses', [
            'expense_date' => now()->toDateString(),
            'category_id' => $head->id,
            'description' => 'Typed straight into the address',
            'amount' => '1000',
            'account_id' => $till->id,
        ])->assertSessionHasErrors('category_id');

        $this->assertSame(0, Expense::count());
        $this->assertSame('5000000.00', $till->fresh()->balance());
    }

    public function test_a_person_without_the_new_ability_cannot_reach_the_list(): void
    {
        $staff = User::create([
            'name' => 'Heads Staff',
            'email' => 'staff'.uniqid().'@plussave.test',
            'password' => 'Password123',
            'is_active' => true,
        ]);
        $staff->assignRole('staff');

        $this->actingAs($staff)->get('/expenses/categories')->assertForbidden();
        $this->actingAs($this->admin)->get('/expenses/categories')->assertOk();
    }
}
