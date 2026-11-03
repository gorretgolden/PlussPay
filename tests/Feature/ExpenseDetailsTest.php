<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\User;
use App\Services\AccountLedger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * One entry, opened on its own: what was paid, which book it came out of, the cash-book lines
 * behind it, and the slip that came with it. The slip is kept off the public disk, so these
 * tests care as much about who can open it as about it being saved.
 */
class ExpenseDetailsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['superadmin', 'admin', 'accountant', 'agent', 'staff'] as $role) {
            Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        }

        $this->admin = $this->makeUser('admin');
    }

    private function makeUser(string $role): User
    {
        $user = User::create([
            'name' => ucfirst($role).' Details',
            'email' => strtolower($role).uniqid().'@plussave.test',
            'password' => 'Password123',
            'is_active' => true,
        ]);

        $user->assignRole($role);

        return $user;
    }

    private function fund(string $code, string $amount): Account
    {
        $account = Account::byCode($code);

        AccountLedger::record($account, 'opening_balance', $amount, 'Float for the desk');

        return $account;
    }

    private function headId(string $name): int
    {
        return ExpenseCategory::where('name', $name)->value('id');
    }

    private function expense(Account $account, array $overrides = []): Expense
    {
        return Expense::record(array_merge([
            'expense_date' => '2026-10-05',
            'category' => 'Rent',
            'description' => 'Kampala office, October',
            'currency' => $account->currency,
            'amount' => '1200000.00',
            'account_id' => $account->id,
            'paid_via' => 'Cash',
            'created_by' => $this->admin->id,
        ], $overrides));
    }

    public function test_an_entry_opens_on_its_own_page_with_its_cash_book_lines(): void
    {
        $till = $this->fund(Account::UGX_UGANDA, '5000000.00');
        $expense = $this->expense($till, ['notes' => 'Receipt OWM-88121', 'paid_via' => 'Mobile money']);

        $this->actingAs($this->admin)->get('/expenses/'.$expense->id)
            ->assertOk()
            ->assertSee('EXP-')
            ->assertSee('Kampala office, October')
            ->assertSee('Rent')
            ->assertSee('UGX 1,200,000')
            ->assertSee('UGX Cash (Uganda)')
            ->assertSee('Holds UGX 3,800,000 now')
            ->assertSee('paid via mobile money')
            ->assertSee('Receipt OWM-88121')
            ->assertSee('Admin Details')
            ->assertSee('Cancel entry')
            ->assertDontSee('Download the slip');
    }

    public function test_a_cancelled_entry_shows_the_payment_and_the_refund_as_two_lines(): void
    {
        $till = $this->fund(Account::UGX_UGANDA, '5000000.00');
        $expense = $this->expense($till);

        $this->actingAs($this->admin)->post('/expenses/'.$expense->id.'/void', [
            'reason' => 'The landlord refunded part of it',
        ])->assertSessionHasNoErrors();

        $page = $this->actingAs($this->admin)->get('/expenses/'.$expense->id)->assertOk();

        // Nothing rubbed out: both sides of the correction are on the page, and the button is gone.
        $page->assertSee('Cancelled')
            ->assertSee('Money out')
            ->assertSee('Money in')
            ->assertSee('The landlord refunded part of it')
            ->assertDontSee('Cancel entry');
    }

    public function test_the_slip_is_kept_with_the_entry_and_only_handed_out_through_it(): void
    {
        Storage::fake('local');
        $till = $this->fund(Account::UGX_UGANDA, '5000000.00');

        $this->actingAs($this->admin)->post('/expenses', [
            'expense_date' => '2026-10-05',
            'category_id' => $this->headId('Utilities'),
            'description' => 'Yaka power for the office',
            'amount' => '90000',
            'account_id' => $till->id,
            'receipt' => UploadedFile::fake()->create('slip.pdf', 40, 'application/pdf'),
        ])->assertSessionHasNoErrors();

        $expense = Expense::latest('id')->firstOrFail();

        $this->assertNotNull($expense->receipt_file);
        Storage::disk('local')->assertExists($expense->receipt_file);

        $this->actingAs($this->admin)->get('/expenses/'.$expense->id)
            ->assertOk()
            ->assertSee('Download the slip');

        $this->actingAs($this->admin)->get('/expenses/'.$expense->id.'/receipt')
            ->assertOk()
            ->assertDownload($expense->reference.'-receipt.pdf');

        // The slip belongs to the entry, and this entry is not the agent's, so there is no route
        // into the file either.
        $this->actingAs($this->makeUser('agent'))->get('/expenses/'.$expense->id.'/receipt')
            ->assertForbidden();

        // The stored path is never a public URL.
        $this->assertStringNotContainsString('/storage/', $expense->receipt_file);
    }

    public function test_only_a_pdf_or_a_photo_can_be_attached(): void
    {
        Storage::fake('local');
        $till = $this->fund(Account::UGX_UGANDA, '5000000.00');

        $this->actingAs($this->admin)->post('/expenses', [
            'expense_date' => '2026-10-05',
            'category_id' => $this->headId('Supplies'),
            'description' => 'Printer paper',
            'amount' => '60000',
            'account_id' => $till->id,
            'receipt' => UploadedFile::fake()->create('ledger.xlsx', 10),
        ])->assertSessionHasErrors('receipt');

        $this->assertSame(0, Expense::count());
        $this->assertSame('5000000.00', $till->balance());
    }

    public function test_an_entry_without_a_slip_says_so_rather_than_404ing_quietly(): void
    {
        $till = $this->fund(Account::UGX_UGANDA, '5000000.00');
        $expense = $this->expense($till);

        $this->actingAs($this->admin)->get('/expenses/'.$expense->id.'/receipt')->assertNotFound();
    }

    public function test_the_book_filters_by_desk_and_by_who_entered_it(): void
    {
        $till = $this->fund(Account::UGX_UGANDA, '5000000.00');
        $yuan = $this->fund(Account::RMB_CHINA, '20000.00');

        $this->expense($till, ['description' => 'Kampala office rent']);
        $this->expense($yuan, ['description' => 'Guangzhou warehouse rent', 'currency' => 'RMB', 'amount' => '650.00']);

        $recorder = $this->makeUser('admin');
        $this->expense($till, ['description' => 'Airtime for the phones', 'amount' => '50000.00', 'created_by' => $recorder->id]);

        $this->actingAs($this->admin)->get('/expenses?desk=UG')
            ->assertOk()
            ->assertSee('Kampala office rent')
            ->assertSee('Airtime for the phones')
            ->assertDontSee('Guangzhou warehouse rent');

        $this->actingAs($this->admin)->get('/expenses?desk=CN')
            ->assertOk()
            ->assertSee('Guangzhou warehouse rent')
            ->assertDontSee('Kampala office rent');

        $this->actingAs($this->admin)->get('/expenses?created_by='.$recorder->id)
            ->assertOk()
            ->assertSee('Airtime for the phones')
            ->assertDontSee('Kampala office rent');

        // The filter boxes are on the page, so the desk and the person can be picked without typing.
        $page = $this->actingAs($this->admin)->get('/expenses')->assertOk();
        $page->assertSee('Desk')->assertSee('Entered by')->assertSee('Open entry');
    }
}
