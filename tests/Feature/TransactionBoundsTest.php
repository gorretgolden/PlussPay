<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\ExchangeRate;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\Setting;
use App\Models\Transaction;
use App\Models\User;
use App\Services\AccountLedger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * The smallest and largest booking are set per intake currency, so a shilling cap
 * never limits a dollar booking or the other way round. These tests pin that gate
 * on every door a figure enters through: the booking form, the expense desk and
 * the bulk import sheet.
 */
class TransactionBoundsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Permission::firstOrCreate(['name' => 'bulk-import-transactions', 'guard_name' => 'web']);
        $role = Role::firstOrCreate(['name' => 'agent', 'guard_name' => 'web']);
        $role->givePermissionTo('bulk-import-transactions');

        ExchangeRate::setPair('USD', 'UGX', 3760, 3750, 3760);
        ExchangeRate::setPair('USD', 'RMB', 7.24, 7.24, 7.24);
    }

    private function user(string $country = 'UG'): User
    {
        $user = User::create([
            'name' => 'Desk Agent',
            'email' => 'bounds'.uniqid().'@plussave.test',
            'password' => 'Password123',
            'is_active' => true,
            'country' => $country,
        ]);
        $user->assignRole('agent');

        return $user;
    }

    private function booking(User $agent, array $overrides = []): \Illuminate\Testing\TestResponse
    {
        $formId = $this->actingAs($agent)->get('/transactions/create')->viewData('formId');

        return $this->post('/transactions', array_merge([
            'form_id' => $formId,
            'sender_name' => 'Aisha Sender',
            'sender_contact' => '+256 700 111 222',
            'receiver_name' => 'Wei Receiver',
            'receiver_contact' => '+86 138 0000 0000',
            'currency' => 'UGX',
            'amount' => '500000',
            'charge_ugx' => '2000',
            'payment_method' => 'Cash',
        ], $overrides));
    }

    private function headId(): int
    {
        return ExpenseCategory::where('name', 'Transport')->value('id')
            ?? ExpenseCategory::orderBy('id')->value('id');
    }

    private function expense(User $agent, Account $from, string $amount): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($agent)->post('/expenses', [
            'category_id' => $this->headId(),
            'description' => 'Boda to the Mbale customer',
            'amount' => $amount,
            'account_id' => $from->id,
            'expense_date' => now()->toDateString(),
            'paid_via' => 'Mobile money',
        ]);
    }

    private function import(string $csv): \Illuminate\Testing\TestResponse
    {
        return $this->post('/transactions/bulk/import', [
            'file' => UploadedFile::fake()->createWithContent('rows.csv', $csv),
        ]);
    }

    public function test_a_shilling_booking_over_the_shilling_maximum_is_refused(): void
    {
        Setting::set('max_transaction_ugx', '400000', 'float', 'transaction');

        $agent = $this->user();

        $this->booking($agent, ['amount' => '500000'])
            ->assertSessionHasErrors(['amount' => 'Maximum amount is 400,000 UGX.']);

        $this->assertSame(0, Transaction::count());

        $this->booking($agent, ['amount' => '400000'])->assertSessionHasNoErrors();

        $this->assertSame(1, Transaction::count());
    }

    public function test_a_shilling_booking_under_the_shilling_minimum_is_refused(): void
    {
        Setting::set('min_transaction_ugx', '1000', 'float', 'transaction');

        $agent = $this->user();

        $this->booking($agent, ['amount' => '500'])
            ->assertSessionHasErrors(['amount' => 'Minimum amount is 1,000 UGX.']);

        $this->assertSame(0, Transaction::count());
    }

    public function test_a_dollar_booking_is_not_limited_by_the_shilling_caps(): void
    {
        Setting::set('min_transaction_ugx', '1000', 'float', 'transaction');
        Setting::set('max_transaction_ugx', '400000', 'float', 'transaction');

        $agent = $this->user();

        // 200 dollars is worth far more than 400,000 shillings, but the dollar
        // pair has its own caps, so the shilling figures must not touch it.
        $this->booking($agent, ['currency' => 'USD', 'amount' => '200'])->assertSessionHasNoErrors();

        $transaction = Transaction::sole();
        $this->assertSame('USD', $transaction->currency);
        $this->assertSame('200.00', (string) $transaction->amount);
    }

    public function test_a_dollar_booking_over_the_dollar_maximum_is_refused(): void
    {
        Setting::set('max_transaction_usd', '150', 'float', 'transaction');

        $agent = $this->user();

        $this->booking($agent, ['currency' => 'USD', 'amount' => '200'])
            ->assertSessionHasErrors(['amount' => 'Maximum amount is 150 USD.']);

        $this->assertSame(0, Transaction::count());
    }

    public function test_a_yuan_booking_from_the_china_desk_respects_the_yuan_caps(): void
    {
        Setting::set('min_transaction_rmb', '10', 'float', 'transaction');
        Setting::set('max_transaction_rmb', '10000', 'float', 'transaction');

        $agent = $this->user('CN');

        $this->booking($agent, ['currency' => 'RMB', 'amount' => '20000'])
            ->assertSessionHasErrors(['amount' => 'Maximum amount is 10,000 RMB.']);

        $this->assertSame(0, Transaction::count());

        $this->booking($agent, ['currency' => 'RMB', 'amount' => '5'])
            ->assertSessionHasErrors(['amount' => 'Minimum amount is 10 RMB.']);

        $this->assertSame(0, Transaction::count());

        $this->booking($agent, ['currency' => 'RMB', 'amount' => '5000', 'charge_ugx' => '0'])
            ->assertSessionHasNoErrors();

        $this->assertSame(1, Transaction::count());
    }

    public function test_an_expense_over_the_accounts_currency_maximum_is_refused(): void
    {
        Setting::set('max_transaction_ugx', '10000', 'float', 'transaction');

        $agent = $this->user();
        $wallet = Account::walletFor($agent, 'UGX');
        AccountLedger::record($wallet, 'opening_balance', '900000.00', 'Cash collected on deliveries');

        $response = $this->expense($agent, $wallet, '20000');

        $response->assertSessionHasErrors('amount');
        $this->assertStringContainsString('largest', (string) session('errors')->first('amount'));
        $this->assertSame(0, Expense::count());
    }

    public function test_an_expense_under_the_accounts_currency_minimum_is_refused(): void
    {
        Setting::set('min_transaction_ugx', '1000', 'float', 'transaction');

        $agent = $this->user();
        $wallet = Account::walletFor($agent, 'UGX');
        AccountLedger::record($wallet, 'opening_balance', '900000.00', 'Cash collected on deliveries');

        $response = $this->expense($agent, $wallet, '500');

        $response->assertSessionHasErrors('amount');
        $this->assertStringContainsString('smallest', (string) session('errors')->first('amount'));
        $this->assertSame(0, Expense::count());
    }

    public function test_an_import_row_over_its_currencys_maximum_is_rejected_but_the_rest_book(): void
    {
        Setting::set('max_transaction_ugx', '400000', 'float', 'transaction');

        $this->actingAs($this->user());

        $csv = "sender_name,receiver_name,currency,amount,charge_ugx\n"
            ."Aisha Li,Wei Zhang,UGX,500000,20000\n"
            ."Bo Chen,Xi Yang,UGX,300000,10000\n";

        $this->import($csv)
            ->assertSessionHas('import_errors')
            ->assertSessionHas('success', 'Successfully imported 1 transaction(s). 1 row(s) had errors.');

        $this->assertSame(1, Transaction::count());
        $this->assertSame('290000.00', (string) Transaction::sole()->amount);
    }
}
