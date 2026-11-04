<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\ExchangeRate;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\Setting;
use App\Models\User;
use App\Services\AccountLedger;
use App\Support\PermissionCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * The board showed money coming in and nothing going out, so the day's spending had to be opened
 * on its own page to be seen. It is kept apart by the desk that paid, because shillings spent in
 * Kampala and yuan spent in Guangzhou do not add up to anything, and each desk reads only its own.
 */
class DashboardSpendingTodayTest extends TestCase
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

    private function user(string $role, string $name, string $country = 'UG'): User
    {
        $user = User::create([
            'name' => $name,
            'email' => strtolower(str_replace(' ', '.', $name)).'@plussave.test',
            'password' => 'Password123',
            'is_active' => true,
            'country' => $country,
        ]);
        $user->assignRole($role);

        return $user;
    }

    private function spent(User $reader, string $currency, string $head, string $amount): Expense
    {
        $wallet = Account::walletFor($reader, $currency);
        AccountLedger::record($wallet, 'opening_balance', $amount, 'Cash at the desk');

        return Expense::record([
            'expense_date' => now()->toDateString(),
            'category_id' => ExpenseCategory::firstOrCreate(['name' => $head])->id,
            'category' => $head,
            'description' => $head.' for the desk',
            'currency' => $currency,
            'amount' => $amount,
            'account_id' => $wallet->id,
            'paid_via' => 'Cash',
            'created_by' => $reader->id,
        ]);
    }

    private function card(User $reader): string
    {
        $html = $this->actingAs($reader)->get('/dashboard')->assertOk()->getContent();
        $start = strpos($html, 'Spent Today');

        if ($start === false) {
            return '';
        }

        $end = strpos($html, 'Overview', $start);

        return preg_replace('/\s+/', ' ', strip_tags(substr($html, $start, ($end ?: $start + 2400) - $start)));
    }

    public function test_a_desk_reads_its_own_spending_on_the_board(): void
    {
        $faith = $this->user('super-agent', 'Faith Nansere');
        $this->spent($faith, 'UGX', 'Transport', '45000.00');
        $this->spent($faith, 'UGX', 'Airtime and internet', '20000.00');

        $card = $this->card($faith);

        $this->assertStringContainsString('Uganda', $card);
        $this->assertStringContainsString('UGX 65,000', $card, 'Both entries under one head of shillings.');
        $this->assertStringContainsString('2 entries today', $card);
        $this->assertStringContainsString('Faith Nansere', $card);
    }

    public function test_the_office_reads_every_desk_split_by_country(): void
    {
        $office = $this->user('superadmin', 'Gorret Nabatanzi');
        $faith = $this->user('super-agent', 'Faith Nansere');
        $mei = $this->user('super-agent', 'Mei Chen', 'CN');

        $this->spent($faith, 'UGX', 'Transport', '45000.00');
        $this->spent($mei, 'RMB', 'Salaries', '3000.00');

        $card = $this->card($office);

        $this->assertStringContainsString('Uganda', $card);
        $this->assertStringContainsString('UGX 45,000', $card);
        $this->assertStringContainsString('Faith Nansere', $card);
        $this->assertStringContainsString('China', $card);
        $this->assertStringContainsString('¥3,000.00', $card);
        $this->assertStringContainsString('Mei Chen', $card);
        $this->assertLessThan(strpos($card, 'China'), strpos($card, 'Uganda'), 'Uganda is listed first.');
    }

    public function test_a_desk_never_sees_another_desks_spending(): void
    {
        $faith = $this->user('super-agent', 'Faith Nansere');
        $mei = $this->user('super-agent', 'Mei Chen', 'CN');

        $this->spent($faith, 'UGX', 'Transport', '45000.00');
        $this->spent($mei, 'RMB', 'Salaries', '3000.00');

        $card = $this->card($faith);

        $this->assertStringContainsString('UGX 45,000', $card);
        $this->assertStringNotContainsString('China', $card);
        $this->assertStringNotContainsString('Mei Chen', $card, 'The card cuts on the same reach as the book.');
    }

    public function test_a_day_with_no_spending_says_so_and_a_role_without_the_book_gets_no_card(): void
    {
        $faith = $this->user('super-agent', 'Faith Nansere');
        $this->assertStringContainsString('Nothing recorded as spent today', $this->card($faith));

        // She ruled the agent role does no expenses at all, so an agent's board carries no card.
        $agent = $this->user('agent', 'Amina Nabukenya');
        $this->assertSame('', $this->card($agent));

        $desk = $this->user('staff', 'Office Desk');
        $this->assertSame('', $this->card($desk), 'No reach into the book, so no figure to check.');
    }

    public function test_the_card_counts_the_day_it_was_recorded_and_a_cancelled_entry_goes_back_off(): void
    {
        $office = $this->user('admin', 'Grace Naigaga');
        $amina = $this->user('super-agent', 'Amina Lead');

        $yesterday = $this->spent($amina, 'UGX', 'Supplies', '30000.00');
        $yesterday->forceFill(['created_at' => now()->subDay()])->save();

        $cancelled = $this->spent($amina, 'UGX', 'Transport', '45000.00');
        $cancelled->void('Entered twice');

        $kept = $this->spent($amina, 'UGX', 'Airtime and internet', '10000.00');

        $card = $this->card($office);

        $this->assertStringContainsString('UGX 10,000', $card);
        $this->assertStringContainsString('1 entry today', $card, 'Recorded yesterday and cancelled today are both out.');
        unset($office, $kept);
    }

    public function test_the_board_and_the_spending_book_split_the_same_rows_the_same_way(): void
    {
        $office = $this->user('admin', 'Grace Naigaga');
        $amina = $this->user('super-agent', 'Amina Lead');
        $this->spent($amina, 'UGX', 'Transport', '45000.00');

        $book = $this->actingAs($office)->get('/expenses')->assertOk()->getContent();

        $this->assertStringContainsString('UGX 45,000', $book);
        $this->assertStringContainsString('UGX 45,000', $this->card($office));
    }

    public function test_one_booking_prints_where_its_figures_come_from(): void
    {
        $faith = $this->user('agent', 'Faith Nansere');

        $this->actingAs($faith)->get('/transactions/create');
        $this->actingAs($faith)->post('/transactions', [
            'form_id' => session('transaction_form_id'),
            'agent_id' => 'counter',
            'sender_name' => 'Sender One',
            'receiver_name' => 'Receiver One',
            'currency' => 'UGX',
            'amount' => '480000',
            'charge_ugx' => '20000',
            'payment_method' => 'Cash',
        ])->assertSessionHasNoErrors();

        $booking = \App\Models\Transaction::latest('id')->firstOrFail();

        Artisan::call('book:show', ['receipt' => $booking->receipt_number]);
        $out = Artisan::output();

        $this->assertStringContainsString($booking->receipt_number, $out);
        $this->assertStringContainsString('UGX 460,000', $out, 'The money that travels.');
        $this->assertStringContainsString('Handed over = Sent + Charge = UGX 480,000', $out);
        $this->assertStringContainsString('$122.34', $out);
        $this->assertStringContainsString('¥885.74', $out);
        $this->assertStringNotContainsString('DOES NOT MATCH the stored', $out, 'Every figure comes out of the prices on the row.');
        $this->assertStringContainsString('USD to UGX', $out);

        // A row whose yuan was priced elsewhere is the thing she is asking about, so the command
        // has to say so rather than print two figures that quietly disagree.
        $booking->forceFill(['rmb_amount' => '732.30'])->save();

        Artisan::call('book:show', ['receipt' => $booking->receipt_number]);
        $this->assertStringContainsString('DOES NOT MATCH the stored yuan', Artisan::output());
    }

    public function test_an_unknown_receipt_is_answered_plainly(): void
    {
        Artisan::call('book:show', ['receipt' => 'PS-20261017-9999']);

        $this->assertStringContainsString('No booking carries that receipt number', Artisan::output());
    }
}
