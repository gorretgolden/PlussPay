<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\CashHandover;
use App\Models\ExchangeRate;
use App\Models\Setting;
use App\Models\Transaction;
use App\Models\User;
use App\Services\AccountLedger;
use App\Support\PermissionCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * The drawer's own book is the one page an agent reads back to the office from memory, so it has to
 * answer the questions they are actually asked: which wallet, what for, when exactly, and how much
 * is left. These tests pin that the hour travels with the day, that a line made by a transfer says
 * what that transfer became, and that a filter on the page is the same filter on the sheet.
 *
 * The running figure is the reason the whole book is walked before anything is cut out of it: a
 * balance read off a filtered page is wrong the moment a line above it is hidden.
 */
class CashHistoryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (PermissionCatalog::names() as $name) {
            Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
        }

        foreach (PermissionCatalog::defaults() as $role => $permissions) {
            Role::firstOrCreate(['name' => $role, 'guard_name' => 'web'])->syncPermissions($permissions);
        }

        ExchangeRate::setPair('USD', 'UGX', 3760, 3750, 3760);
        ExchangeRate::setPair('USD', 'RMB', 7.24, 7.20, 7.24);
        Setting::set('default_charge_ugx', '20000', 'float', 'transaction');
    }

    private function agent(string $name = 'Amina Nabukenya'): User
    {
        $user = User::create([
            'name' => $name,
            'email' => strtolower(str_replace(' ', '.', $name)).'@plussave.test',
            'password' => 'Password123',
            'is_active' => true,
            'country' => 'UG',
        ]);

        $user->assignRole('agent');

        return $user;
    }

    private function superAgent(string $name = 'Amina Lead'): User
    {
        $user = User::create([
            'name' => $name,
            'email' => strtolower(str_replace(' ', '.', $name)).'@plussave.test',
            'password' => 'Password123',
            'is_active' => true,
            'country' => 'UG',
        ]);

        $user->assignRole('super-agent');

        return $user;
    }

    /** Cash a desk took in shillings, so the wallet gains the principal and the charge. */
    private function shillingBooking(User $by, string $receipt, string $principal = '480000', string $charge = '20000'): Transaction
    {
        return Transaction::create([
            'receipt_number' => $receipt,
            'sender_name' => 'Sender '.$receipt,
            'sender_contact' => '0700000001',
            'receiver_name' => 'Receiver '.$receipt,
            'receiver_contact' => '8613800000001',
            'currency' => 'UGX',
            'amount' => $principal,
            'exchange_rate' => 7.24,
            'rmb_amount' => '3475200.00',
            'charge_rate' => 0.02,
            'charge_amount' => 2,
            'charge_ugx' => $charge,
            'status' => 'completed',
            'created_by' => $by->id,
        ]);
    }

    /** The desk forwards dollars whole and adds the charge in shillings on top. */
    private function dollarBooking(User $by, string $receipt): Transaction
    {
        return Transaction::create([
            'receipt_number' => $receipt,
            'sender_name' => 'Sender '.$receipt,
            'sender_contact' => '0700000001',
            'receiver_name' => 'Receiver '.$receipt,
            'receiver_contact' => '8613800000001',
            'currency' => 'USD',
            'amount' => '1000',
            'exchange_rate' => 7.24,
            'rmb_amount' => '7240.00',
            'charge_rate' => 0.02,
            'charge_amount' => 2,
            'charge_ugx' => '20000',
            'status' => 'completed',
            'created_by' => $by->id,
        ]);
    }

    private function page(User $as, string $query = ''): string
    {
        $this->flushSession();

        return $this->actingAs($as)->get('/wallet'.$query)->assertOk()->getContent();
    }

    /**
     * Only the rows, because the "What happened" filter box carries the same words as a category
     * name and would answer any question asked of the whole page.
     */
    private function rows(string $html): string
    {
        $from = strpos($html, '<tbody');
        $to = strpos($html, '</tbody>', $from);

        return $from === false || $to === false ? '' : substr($html, $from, $to - $from);
    }

    public function test_a_collected_transfer_shows_the_hour_it_was_typed_and_the_status_it_became(): void
    {
        $agent = $this->agent();
        $this->shillingBooking($agent, 'PS-20261017-0101');

        $html = $this->page($agent);

        $this->assertMatchesRegularExpression('/[A-Z][a-z]{2} \d\d, 2026<span[^>]*>, \d\d:\d\d [AP]M</', $html, 'The day and the hour sit on one line.');
        $this->assertStringContainsString('Completed', $html, 'The line names the transfer it came from.');
        $this->assertStringContainsString('href="/transactions/', $html, 'The receipt number opens the booking it was collected for.');
        $this->assertStringContainsString('Collected', $html);
        $this->assertStringContainsString('Commission', $html);
    }

    public function test_a_cancelled_booking_shows_that_status_on_its_wallet_lines(): void
    {
        $agent = $this->agent('Grace Okello');
        $booking = $this->shillingBooking($agent, 'PS-20261017-0102');
        $booking->update(['status' => 'cancelled']);

        $html = $this->page($agent);

        $this->assertStringContainsString('bg-red-100 text-red-700">Cancelled', $html);
    }

    public function test_every_line_of_the_table_is_read_left_to_right_instead_of_jumped_at(): void
    {
        $agent = $this->agent('Peter Okello');
        $this->shillingBooking($agent, 'PS-20261017-0103');

        $html = $this->page($agent);

        $this->assertStringNotContainsString('text-right text-xs font-medium text-gray-500 uppercase">In', $html);
        $this->assertStringNotContainsString('text-right text-xs font-medium text-gray-500 uppercase">Out', $html);
        $this->assertStringNotContainsString('text-right text-xs font-medium text-gray-500 uppercase">Left in hand', $html);
        $this->assertSame(0, substr_count($html, 'text-right text-sm'), 'No money cell is pushed against the right edge.');
    }

    public function test_the_wallet_filter_keeps_one_drawer_and_the_balance_it_reached(): void
    {
        $agent = $this->agent('Zainab Musa');
        $this->shillingBooking($agent, 'PS-20261017-0104');
        $this->dollarBooking($agent, 'PS-20261017-0105');

        $dollars = $this->page($agent, '?wallet=USD');

        $this->assertStringContainsString('$1,000.00', $dollars);
        $this->assertStringNotContainsString('collected in UGX', $dollars, 'The shilling lines are out of the way, not in the sheet.');

        $shillings = $this->page($agent, '?wallet=UGX');
        $this->assertStringContainsString('collected in UGX', $shillings);
        $this->assertStringNotContainsString('collected in USD', $shillings);
    }

    public function test_the_what_happened_filter_pulls_out_one_kind_of_movement(): void
    {
        $agent = $this->agent('Ruth Asawa');
        $this->shillingBooking($agent, 'PS-20261017-0106');

        $commission = $this->page($agent, '?kind=agent_fee');

        $this->assertStringContainsString('Commission on transfer PS-20261017-0106', $commission);
        $this->assertStringNotContainsString('held by', $commission, 'The collection line is not in a commission filter.');
        $this->assertStringContainsString('Left in hand', $commission);

        $handed = $this->page($agent, '?kind=transfer_out');
        $this->assertStringContainsString('No movement of your wallets fits that filter', $handed);
    }

    public function test_the_going_and_coming_filter_keeps_only_money_leaving_the_hand(): void
    {
        $agent = $this->agent('Faith Nansere');
        $lead = $this->superAgent();
        $this->shillingBooking($agent, 'PS-20261017-0107');
        CashHandover::request($agent, $lead, 'UGX', '480000')->approve();

        $out = $this->page($agent, '?direction=out');
        $in = $this->page($agent, '?direction=in');

        $this->assertStringContainsString('Cash handover', $out);
        $this->assertStringContainsString('Faith Nansere handed UGX 480,000 to Amina Lead', $out);
        $this->assertStringNotContainsString('held by', $out);
        $this->assertStringContainsString('held by', $in);
        $this->assertStringNotContainsString('Cash handover', $in);
    }

    public function test_the_search_box_finds_a_line_by_its_receipt_number(): void
    {
        $agent = $this->agent('Miriam Kase');
        $this->shillingBooking($agent, 'PS-20261017-0108');
        $this->shillingBooking($agent, 'PS-20261017-0109');

        $one = $this->page($agent, '?search=PS-20261017-0109');

        $this->assertStringContainsString('held by Miriam Kase until handed in', $one);
        $this->assertStringContainsString('Commission on transfer PS-20261017-0109', $one);
        $this->assertStringNotContainsString('PS-20261017-0108', $one);
    }

    public function test_the_date_range_closes_the_book_on_a_day_that_has_not_happened_yet(): void
    {
        $agent = $this->agent();
        $this->shillingBooking($agent, 'PS-20261017-0110');

        $this->assertStringContainsString('No movement of your wallets fits that filter', $this->page($agent, '?to='.now()->subMonth()->toDateString()));
        $this->assertStringContainsString('Collected', $this->page($agent, '?from='.now()->toDateString().'&to='.now()->toDateString()));
    }

    public function test_the_sheet_downloads_the_rows_on_the_screen_and_no_other(): void
    {
        $agent = $this->agent();
        $this->shillingBooking($agent, 'PS-20261017-0111');
        $this->dollarBooking($agent, 'PS-20261017-0112');

        $this->flushSession();
        $response = $this->actingAs($agent)->get('/export/cash-history?wallet=USD&kind=money_in')->assertOk();

        $this->assertStringContainsString('cash_history', $response->headers->get('content-disposition'));

        $file = tempnam(sys_get_temp_dir(), 'cash-history-');
        file_put_contents($file, $response->streamedContent());
        $sheet = IOFactory::load($file)->getActiveSheet();
        unlink($file);

        // Rows 1 to 4 are the letterhead and row 5 the column names, so the book the screen was
        // showing starts at row 6.
        $rows = array_values(array_filter(array_map(
            fn (array $row) => trim(implode(' ', array_map(fn ($cell) => trim((string) $cell), $row)), ' '),
            array_slice($sheet->toArray(), 5),
        ), fn (string $line) => $line !== ''));

        $this->assertCount(1, $rows, 'The filter comes along with the download.');
        $this->assertStringContainsString('PS-20261017-0112', $rows[0]);
        $this->assertStringNotContainsString('PS-20261017-0111', $rows[0]);
        $this->assertMatchesRegularExpression('/\d{4}-\d\d-\d\d \d\d:\d\d/', $rows[0], 'The sheet carries the hour the page shows.');
        $this->assertStringContainsString('Completed', $rows[0]);
    }

    public function test_a_desk_that_collected_nothing_reads_an_empty_book_and_not_the_agent_s(): void
    {
        $agent = $this->agent();
        $this->shillingBooking($agent, 'PS-20261017-0115');

        $teller = User::create([
            'name' => 'Doreen Namuli',
            'email' => 'doreen@plussave.test',
            'password' => 'Password123',
            'is_active' => true,
            'country' => 'UG',
        ]);
        $teller->assignRole('staff');

        $html = $this->page($teller);

        $this->assertStringContainsString('Nothing has moved through your wallets yet', $html);
        $this->assertStringNotContainsString('PS-20261017-0115', $html, 'Somebody else s cash is not this desk s book.');
        $this->assertStringNotContainsString('Hand cash in to your super agent', $html, 'A desk that holds no collections gets no hand-in box.');
    }

    public function test_a_hand_in_bigger_than_the_hand_is_refused_before_the_ledger_is_touched(): void
    {
        $agent = $this->agent();
        $lead = $this->superAgent();
        $booking = $this->shillingBooking($agent, 'PS-20261017-0113');

        // The desk spent most of the drawer after the booking, so the ticks no longer sit in the till.
        AccountLedger::record(Account::walletFor($agent, 'UGX'), 'adjustment_out', '450000', 'Spent before the hand-in');

        $this->flushSession();
        $this->actingAs($agent)->post(route('cash-handovers.from-transactions'), [
            'super_agent_id' => $lead->id,
            'transaction_ids' => [$booking->id],
            'note' => 'Whole day',
        ])->assertSessionHasErrors('transaction_ids');

        $this->assertStringContainsString(
            'The UGX wallet holds UGX 50,000',
            session('errors')->first('transaction_ids'),
            'The refusal says what is really in the drawer.',
        );
        $this->assertSame('50000.00', Account::walletFor($agent, 'UGX')->fresh()->balance());
        $this->assertSame('0.00', Account::byCode(Account::UGX_UGANDA)->balance());
        $this->assertSame(0, CashHandover::count());
    }

    private function office(string $name = 'Gorret Office'): User
    {
        $user = User::create([
            'name' => $name,
            'email' => strtolower(str_replace(' ', '.', $name)).uniqid().'@plussave.test',
            'password' => 'Password123',
            'is_active' => true,
            'country' => 'UG',
        ]);

        $user->assignRole('admin');

        return $user;
    }

    /**
     * A credit or a debit is the one line on a drawer's book that they cannot explain from their own
     * day, because the office moved their money without asking. So the line says who, on the screen
     * and in the sheet they download, and the confirmation the office gets names the same person.
     */
    public function test_a_credit_or_debit_names_the_person_who_moved_the_money(): void
    {
        $agent = $this->agent('Sarah Naigaga');
        $admin = $this->office();

        $this->flushSession();
        $this->actingAs($admin)
            ->post(route('wallets.adjust'), [
                'agent_id' => $agent->id,
                'direction' => 'credit',
                'amount' => '50000',
                'description' => 'Advance for the October float',
            ])
            ->assertSessionHas('success', $admin->name.' credited Sarah Naigaga\'s wallet with UGX 50,000.');

        $this->flushSession();
        $this->actingAs($admin)->post(route('wallets.adjust'), [
            'agent_id' => $agent->id,
            'direction' => 'debit',
            'amount' => '30000',
            'description' => 'Cash advance returned',
        ]);

        $rows = $this->rows($this->page($agent));
        $this->assertStringContainsString('Credited by '.$admin->name, $rows);
        $this->assertStringContainsString('Debited by '.$admin->name, $rows);
        $this->assertStringNotContainsString('the office', $rows, 'A row with a person on it does not hide behind the office.');

        // The same words in the download, because she reads the sheet back over the phone.
        $this->flushSession();
        $sheet = $this->actingAs($agent)->get('/export/cash-history?kind=adjustment_in')->streamedContent();
        $file = tempnam(sys_get_temp_dir(), 'cash-credit-');
        file_put_contents($file, $sheet);
        $rows = array_slice(IOFactory::load($file)->getActiveSheet()->toArray(), 5);
        unlink($file);

        $line = trim(implode(' ', array_map(fn ($cell) => trim((string) $cell), $rows[0])));
        $this->assertStringContainsString('Credited by '.$admin->name, $line);
        $this->assertStringContainsString('Advance for the October float', $line);
    }

    /**
     * A movement written by a job rather than by a hand has nobody to name, and an account deleted
     * since takes its name with it. Both read as the office rather than as a blank or a guess.
     */
    public function test_a_movement_written_without_a_person_still_reads_the_office(): void
    {
        $agent = $this->agent();

        AccountLedger::record(Account::walletFor($agent, 'UGX'), 'adjustment_in', '20000', 'Opened the drawer');

        $this->assertStringContainsString('Credited by the office', $this->rows($this->page($agent)));
    }
}
