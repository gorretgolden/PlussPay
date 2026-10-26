<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\ActivityLog;
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
        Permission::firstOrCreate(['name' => 'view-expenses', 'guard_name' => 'web']);

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

    public function test_a_person_who_can_read_expenses_sees_the_list_but_cannot_change_it(): void
    {
        $staff = User::create([
            'name' => 'Heads Staff',
            'email' => 'staff'.uniqid().'@plussave.test',
            'password' => 'Password123',
            'is_active' => true,
        ]);
        $staff->assignRole('staff');
        $staff->givePermissionTo('view-expenses');

        $this->actingAs($staff)->get('/expenses/categories')
            ->assertOk()
            ->assertSee('Expense Categories')
            ->assertSee('Rent')
            ->assertSee('In use')
            ->assertDontSee('Add a category')
            ->assertDontSee('Delete selected');

        $head = ExpenseCategory::where('name', 'Rent')->firstOrFail();

        $this->actingAs($staff)->post('/expenses/categories', ['name' => 'Sneak'])->assertForbidden();
        $this->actingAs($staff)->put('/expenses/categories/'.$head->id, ['name' => 'Sneak'])->assertForbidden();
        $this->actingAs($staff)->delete('/expenses/categories/'.$head->id)->assertForbidden();
        $this->assertSame(0, ExpenseCategory::where('name', 'Sneak')->count());

        $this->actingAs($this->admin)->get('/expenses/categories')->assertOk();
    }

    public function test_editing_a_category_stamps_who_changed_it(): void
    {
        $head = ExpenseCategory::where('name', 'Rent')->firstOrFail();

        $this->actingAs($this->admin)->put('/expenses/categories/'.$head->id, [
            'name' => 'Office rent',
        ])->assertSessionHasNoErrors();

        $fresh = $head->fresh();
        $this->assertSame('Office rent', $fresh->name);
        $this->assertSame($this->admin->id, $fresh->updated_by);
        $this->assertSame('Heads Admin', $fresh->editor->name);
    }

    public function test_a_category_with_payments_filed_under_it_cannot_be_deleted(): void
    {
        $till = Account::byCode(Account::UGX_UGANDA);
        AccountLedger::record($till, 'opening_balance', '5000000.00', 'Float');

        Expense::record([
            'expense_date' => now()->toDateString(),
            'category_id' => ExpenseCategory::where('name', 'Rent')->value('id'),
            'category' => 'Rent',
            'description' => 'Kampala office',
            'currency' => 'UGX',
            'amount' => '1000.00',
            'account_id' => $till->id,
            'created_by' => $this->admin->id,
        ]);

        $head = ExpenseCategory::where('name', 'Rent')->firstOrFail();

        $this->actingAs($this->admin)->delete('/expenses/categories/'.$head->id)
            ->assertSessionHasErrors('error');

        $this->assertStringContainsString('cannot be deleted', $this->flashedError('error'));
        $this->assertDatabaseHas('expense_categories', ['name' => 'Rent']);
        $this->assertSame(1, Expense::count());
    }

    public function test_an_unused_category_can_be_deleted_and_the_deletion_is_audited(): void
    {
        $courier = ExpenseCategory::create([
            'name' => 'Courier', 'is_active' => true, 'created_by' => $this->admin->id,
        ]);

        $this->actingAs($this->admin)->delete('/expenses/categories/'.$courier->id)
            ->assertSessionHasNoErrors();

        $this->assertDatabaseMissing('expense_categories', ['id' => $courier->id]);
        $log = ActivityLog::where('action', 'expense_category_deleted')->first();
        $this->assertNotNull($log);
        $this->assertStringContainsString('Courier', $log->description);
    }

    public function test_bulk_delete_refuses_the_whole_batch_when_any_category_is_in_use(): void
    {
        $till = Account::byCode(Account::UGX_UGANDA);
        AccountLedger::record($till, 'opening_balance', '5000000.00', 'Float');

        Expense::record([
            'expense_date' => now()->toDateString(),
            'category_id' => ExpenseCategory::where('name', 'Rent')->value('id'),
            'category' => 'Rent',
            'description' => 'Kampala office',
            'currency' => 'UGX',
            'amount' => '1000.00',
            'account_id' => $till->id,
            'created_by' => $this->admin->id,
        ]);

        $rent = ExpenseCategory::where('name', 'Rent')->firstOrFail();
        $courier = ExpenseCategory::create([
            'name' => 'Courier', 'is_active' => true, 'created_by' => $this->admin->id,
        ]);

        $this->actingAs($this->admin)->post('/expenses/categories/bulk-delete', [
            'category_ids' => [$rent->id, $courier->id],
        ])->assertSessionHasErrors('error');

        $this->assertStringContainsString('Rent', $this->flashedError('error'));
        $this->assertDatabaseHas('expense_categories', ['name' => 'Rent']);
        $this->assertDatabaseHas('expense_categories', ['name' => 'Courier']);
    }

    public function test_bulk_delete_removes_only_free_categories_and_audits_it(): void
    {
        $courier = ExpenseCategory::create([
            'name' => 'Courier', 'is_active' => true, 'created_by' => $this->admin->id,
        ]);
        $fuel = ExpenseCategory::create([
            'name' => 'Fuel', 'is_active' => true, 'created_by' => $this->admin->id,
        ]);

        $this->actingAs($this->admin)->post('/expenses/categories/bulk-delete', [
            'category_ids' => [$courier->id, $fuel->id],
        ])->assertSessionHasNoErrors();

        $this->assertDatabaseMissing('expense_categories', ['name' => 'Courier']);
        $this->assertDatabaseMissing('expense_categories', ['name' => 'Fuel']);
        $log = ActivityLog::where('action', 'expense_categories_bulk_deleted')->first();
        $this->assertNotNull($log);
        $this->assertStringContainsString('Courier', $log->description);
    }
}
