<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\CurrencyExchange;
use App\Models\ExchangeRate;
use App\Models\Setting;
use App\Models\Transaction;
use App\Models\User;
use App\Services\AccountLedger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * What a desk is allowed to read out of the report book. The rows were always cut down to the
 * person who booked them; this is the page doors, the filters a desk can type by hand, and the
 * exports, which are the same report in a file rather than on screen.
 */
class AgentReportDeskTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['superadmin', 'admin', 'accountant', 'agent', 'staff'] as $role) {
            Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        }

        ExchangeRate::create(['from_currency' => 'USD', 'to_currency' => 'RMB', 'rate' => 6.75, 'buy_rate' => 6.75, 'sell_rate' => 6.75, 'is_active' => true]);
        ExchangeRate::create(['from_currency' => 'USD', 'to_currency' => 'UGX', 'rate' => 3760, 'buy_rate' => 3750, 'sell_rate' => 3760, 'is_active' => true]);

        Setting::set('service_fee_ugx', '2000', 'float', 'transaction');
        Setting::set('service_fee_usd', '100', 'float', 'transaction');
    }

    private function user(string $name, string $role): User
    {
        $user = User::create([
            'name' => $name,
            'email' => strtolower(str_replace(' ', '.', $name)).uniqid().'@plussave.test',
            'password' => 'Password123',
            'is_active' => true,
        ]);

        $user->assignRole($role);

        return $user;
    }

    private function booking(User $by, string $receipt): Transaction
    {
        return Transaction::create([
            'receipt_number' => $receipt,
            'sender_name' => 'Sender '.$receipt,
            'sender_contact' => '0700000001',
            'receiver_name' => 'Receiver '.$receipt,
            'receiver_contact' => '8613800000001',
            'currency' => 'USD',
            'amount' => 250,
            'exchange_rate' => 6.75,
            'rate_buy_ugx' => 3750,
            'rate_sell_ugx' => 3760,
            'rmb_amount' => 1687.5,
            'charge_rate' => 0.01,
            'charge_amount' => 2.5,
            'charge_ugx' => 9400,
            'total_usd_equivalent' => 250,
            'status' => 'completed',
            'created_by' => $by->id,
        ]);
    }

    private function deal(User $by, string $person): CurrencyExchange
    {
        $in = Account::byCode(Account::UGX_UGANDA);
        $out = Account::byCode(Account::USD_UGANDA);

        // The counter needs the float to pay the deal out of, and each new deal draws on the same
        // two accounts, so both are topped up every time rather than once in setUp.
        AccountLedger::record($in, 'opening_balance', '50000000.00', 'Office float');
        AccountLedger::record($out, 'opening_balance', '100000.00', 'Office float');

        return CurrencyExchange::record([
            'exchange_date' => now()->toDateString(),
            'person_name' => $person,
            'in_currency' => 'UGX',
            'in_amount' => '1000000.00',
            'in_account_id' => $in->id,
            'out_currency' => 'USD',
            'out_amount' => '265.96',
            'out_account_id' => $out->id,
            'rate' => '3759.963904',
            'margin_ugx' => '2650.00',
            'created_by' => $by->id,
        ]);
    }

    /** @return string[] Every office report a desk has no door to. */
    private function officeReports(): array
    {
        return ['revenue', 'profit', 'commission', 'agent', 'customer', 'rates'];
    }

    public function test_a_desk_is_offered_his_own_five_reports_and_none_of_the_offices(): void
    {
        $agent = $this->user('Sarah Nalin', 'agent');
        $this->booking($agent, 'DESK-OWN');

        $html = $this->actingAs($agent)->get('/reports')->assertOk()->getContent();

        foreach (['My Reports', 'My Transfers', 'My Exchanges', 'My Charge Collected', 'My Wallet Summary', 'My Daily Summary'] as $label) {
            $this->assertStringContainsString($label, $html);
        }

        foreach (['Reports Hub', 'Transaction Report', 'Revenue Report', 'Profit Report', 'Commission Report', 'Agent Report', 'Customer Report', 'Exchange Rate Report'] as $label) {
            $this->assertStringNotContainsString($label, $html);
        }

        // The office tables are not on the page at all, not even emptied out. The charts a desk
        // does get are drawn from his own rows, the same as every other figure on the page.
        foreach (['id="section-profit"', 'id="section-commission"', 'id="section-agent"', 'id="section-customer"', 'id="section-rates"'] as $marker) {
            $this->assertStringNotContainsString($marker, $html);
        }
    }

    public function test_a_desk_typing_an_office_report_into_the_address_bar_is_turned_away(): void
    {
        $agent = $this->user('Sarah Nalin', 'agent');

        foreach ($this->officeReports() as $type) {
            $this->actingAs($agent)->get('/reports?type='.$type)->assertForbidden();
            $this->actingAs($agent)->get('/reports/export?type='.$type)->assertForbidden();
        }

        // The same answer for the two screens the office report pages link out to.
        $this->actingAs($agent)->get('/agents/performance')->assertForbidden();
        $this->actingAs($agent)->get('/export/profit-report')->assertForbidden();
        $this->actingAs($agent)->get('/export/agent-performance')->assertForbidden();
    }

    public function test_a_desks_own_five_reports_all_open(): void
    {
        $agent = $this->user('Sarah Nalin', 'agent');
        $this->booking($agent, 'DESK-OPEN');
        $this->deal($agent, 'Wei Chen');

        foreach (['hub', 'transaction', 'exchanges', 'charge', 'wallet', 'daily'] as $type) {
            $this->actingAs($agent)->get('/reports?type='.$type)->assertOk();
        }
    }

    public function test_one_desk_never_sees_another_desks_booking_on_screen_or_in_a_file(): void
    {
        $mine = $this->user('Sarah Nalin', 'agent');
        $theirs = $this->user('Peter Okello', 'agent');

        $this->booking($mine, 'MINE-100');
        $this->booking($theirs, 'THEIRS-200');

        // The page, every section of it, and the one-line-per-transfer export sheet together.
        $this->actingAs($mine)->get('/reports')->assertOk()->assertSee('MINE-100')->assertDontSee('THEIRS-200');
        $this->actingAs($mine)->get('/transactions')->assertOk()->assertSee('MINE-100')->assertDontSee('THEIRS-200');

        // A desk has no JSON endpoint of his own, so the file is the machine-readable way out.
        $this->assertStringNotContainsString('THEIRS-200', $this->spreadsheet($this->actingAs($mine)->get('/reports/export')));
        $this->assertStringNotContainsString('THEIRS-200', $this->spreadsheet($this->actingAs($mine)->get('/export/transactions')));
        $this->assertStringNotContainsString('THEIRS-200', $this->spreadsheet($this->actingAs($mine)->get('/export/receipts')));

        // And the slip itself stays shut.
        $this->actingAs($mine)->get('/receipts/THEIRS-200')->assertNotFound();
        $this->actingAs($mine)->get('/receipts/THEIRS-200/print')->assertNotFound();
        $this->actingAs($mine)->get('/receipts/MINE-100')->assertOk();
    }

    public function test_a_desk_cannot_point_the_report_at_another_person_by_typing_the_agent_filter(): void
    {
        $mine = $this->user('Sarah Nalin', 'agent');
        $theirs = $this->user('Peter Okello', 'agent');
        $boss = $this->user('Gorret Admin', 'admin');

        $this->booking($mine, 'FILTER-OWN');
        $this->booking($theirs, 'FILTER-HIS');

        // The filter is read off the address bar, so the only safe form of it is to ignore it.
        foreach ([$theirs->id, $boss->id] as $value) {
            $this->actingAs($mine)
                ->get('/reports?agent_id='.$value.'&date_from='.now()->startOfMonth()->toDateString().'&date_to='.now()->toDateString())
                ->assertOk()
                ->assertSee('FILTER-OWN')
                ->assertDontSee('FILTER-HIS');
        }

        // Searching for another desk's receipt number finds nothing, because the search runs on the
        // rows already cut down to this reader. The word comes back in the box he typed it in, so
        // the proof is the set of rows rather than the page text.
        $this->assertCount(0, $this->actingAs($mine)->get('/reports?search=FILTER-HIS')->assertOk()->viewData('transactions'));
        $this->assertSame(['FILTER-OWN'], $this->actingAs($mine)->get('/reports?search=FILTER-OWN')->assertOk()->viewData('transactions')->pluck('receipt_number')->all());
    }

    public function test_an_office_reader_sees_every_desk_and_can_narrow_the_book_to_one(): void
    {
        $mine = $this->user('Sarah Nalin', 'agent');
        $theirs = $this->user('Peter Okello', 'agent');
        $boss = $this->user('Gorret Admin', 'admin');

        $this->booking($mine, 'ALL-A');
        $this->booking($theirs, 'ALL-B');

        $this->actingAs($boss)->get('/reports')->assertOk()->assertSee('ALL-A')->assertSee('ALL-B');

        $this->actingAs($boss)
            ->get('/reports?agent_id='.$mine->id)
            ->assertOk()
            ->assertSee('ALL-A')
            ->assertDontSee('ALL-B');

        // The accountant reads the same book the office does, so the doors open for the ability,
        // not for the role name.
        $accounts = $this->user('Joan Accountant', 'accountant');
        foreach ($this->officeReports() as $type) {
            $this->actingAs($accounts)->get('/reports?type='.$type)->assertOk();
        }
    }

    public function test_a_desks_report_page_carries_the_period_pills_and_the_receipt_search(): void
    {
        $agent = $this->user('Sarah Nalin', 'agent');
        $this->booking($agent, 'PILL-100');

        $html = $this->actingAs($agent)->get('/reports?type=transaction')->assertOk()->getContent();

        foreach (['>Today<', '>This Month<', '>Last Month<', '>All Time<', 'name="search"', 'name="date_from"', 'name="date_to"'] as $marker) {
            $this->assertStringContainsString($marker, $html);
        }

        // The desk filter is an office tool, so the box itself is not on his page.
        $this->assertStringNotContainsString('name="agent_id"', $html);

        $this->actingAs($agent)->get('/reports?type=transaction&search=PILL-100')->assertOk()->assertSee('PILL-100');
        $this->actingAs($agent)->get('/reports?type=transaction&all_time=1')->assertOk()->assertSee('PILL-100');
    }

    public function test_a_desk_holds_no_route_that_edits_or_destroys_a_booking(): void
    {
        $agent = $this->user('Sarah Nalin', 'agent');
        $booking = $this->booking($agent, 'IMMUTABLE');

        // There is nothing to forbid because nothing was ever built: the book only ever gains a
        // cancelling line, and that one sits behind the office approval ability.
        $this->assertNull(Route::getRoutes()->getByName('transactions.edit'));
        $this->assertNull(Route::getRoutes()->getByName('transactions.update'));
        $this->assertNull(Route::getRoutes()->getByName('transactions.destroy'));

        $this->actingAs($agent)->patch('/transactions/'.$booking->id.'/status', ['status' => 'cancelled'])->assertForbidden();
        $this->actingAs($agent)->delete('/transactions/'.$booking->id)->assertStatus(405);
    }

    public function test_a_desks_exchange_tab_carries_only_his_own_deals(): void
    {
        $mine = $this->user('Sarah Nalin', 'agent');
        $theirs = $this->user('Peter Okello', 'agent');

        $this->deal($mine, 'Alice Auma');
        $this->deal($theirs, 'Bob Kato');

        $this->actingAs($mine)
            ->get('/reports?type=exchanges')
            ->assertOk()
            ->assertSee('Alice Auma')
            ->assertDontSee('Bob Kato');

        $this->assertStringNotContainsString('Bob Kato', $this->spreadsheet($this->actingAs($mine)->get('/export/exchanges')));

        $this->actingAs($theirs)->get('/reports?type=exchanges')->assertOk()->assertSee('Bob Kato')->assertDontSee('Alice Auma');
    }

    private function spreadsheet($response): string
    {
        $file = tempnam(sys_get_temp_dir(), 'report-desk-');

        try {
            file_put_contents($file, $response->streamedContent());

            return implode('|', array_map(fn ($row) => implode(' ', array_map('strval', $row)),
                IOFactory::load($file)->getActiveSheet()->toArray()));
        } finally {
            @unlink($file);
        }
    }
}
