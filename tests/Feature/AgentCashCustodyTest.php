<?php

namespace Tests\Feature;

use App\Exceptions\InsufficientFundsException;
use App\Models\Account;
use App\Models\AccountEntry;
use App\Models\ActivityLog;
use App\Models\ExchangeRate;
use App\Models\Transaction;
use App\Models\User;
use App\Services\AccountLedger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * The cash a customer hands over is in somebody's hand, and until it reaches the office till the
 * only place it can be accounted for is the agent who took it. These tests pin where each booking's
 * notes land, and that the whole book never gains a shilling a customer did not actually hand over.
 */
class AgentCashCustodyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['superadmin', 'admin', 'agent', 'staff'] as $role) {
            Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        }

        ExchangeRate::create(['from_currency' => 'USD', 'to_currency' => 'RMB', 'rate' => 6.75, 'buy_rate' => 6.75, 'sell_rate' => 6.75, 'is_active' => true]);
        ExchangeRate::create(['from_currency' => 'USD', 'to_currency' => 'UGX', 'rate' => 3760, 'buy_rate' => 3750, 'sell_rate' => 3760, 'is_active' => true]);
    }

    private function agent(string $name = 'Sarah Nalin', string $country = 'UG'): User
    {
        $user = User::create([
            'name' => $name,
            'email' => strtolower(str_replace(' ', '', $name)).'@plussave.test',
            'password' => 'Password123',
            'is_active' => true,
            'country' => $country,
        ]);

        $user->assignRole('agent');

        return $user;
    }

    private function officeUser(string $role, string $name): User
    {
        $user = User::create([
            'name' => $name,
            'email' => strtolower(str_replace(' ', '', $name)).'@plussave.test',
            'password' => 'Password123',
            'is_active' => true,
            'country' => 'UG',
        ]);

        $user->assignRole($role);

        return $user;
    }

    private function booking(User $creator, string $receipt, string $currency, string $principal, string $charge): Transaction
    {
        return Transaction::create([
            'receipt_number' => $receipt,
            'sender_name' => 'Sender '.$receipt,
            'sender_contact' => '0700000001',
            'receiver_name' => 'Receiver '.$receipt,
            'receiver_contact' => '8613800000001',
            'currency' => $currency,
            'amount' => $principal,
            'exchange_rate' => 6.75,
            'rmb_amount' => bcmul($principal, '6.75', 2),
            'charge_rate' => 0.02,
            'charge_amount' => 2,
            'charge_ugx' => $charge,
            'status' => 'completed',
            'created_by' => $creator->id,
        ]);
    }

    private function lines(int $transactionId): \Illuminate\Support\Collection
    {
        return AccountEntry::where('transaction_id', $transactionId)
            ->with('account')
            ->get()
            ->mapWithKeys(fn (AccountEntry $entry) => [$entry->type.':'.$entry->account->code => (string) $entry->amount]);
    }

    public function test_shillings_an_agent_collects_stay_in_that_agent_s_wallet(): void
    {
        $agent = $this->agent();

        // 500,000 handed over: 480,000 that travels and 20,000 the customer agreed as the charge.
        $transaction = $this->booking($agent, 'CUS-001', 'UGX', '480000', '20000');

        $this->assertSame([
            'money_in:W-UGX-'.$agent->id => '480000.00',
            'agent_fee:W-UGX-'.$agent->id => '20000.00',
        ], $this->lines($transaction->id)->all());

        $this->assertSame('500000.00', Account::walletFor($agent, 'UGX')->balance());
        $this->assertSame('0.00', Account::byCode(Account::UGX_UGANDA)->balance());
    }

    public function test_dollars_an_agent_collects_are_held_as_dollars_and_the_charge_as_shillings(): void
    {
        $agent = $this->agent('Peter Okello');

        $transaction = $this->booking($agent, 'CUS-002', 'USD', '100', '20000');

        $this->assertSame([
            'money_in:W-USD-'.$agent->id => '100.00',
            'agent_fee:W-UGX-'.$agent->id => '20000.00',
        ], $this->lines($transaction->id)->all());

        $this->assertSame('100.00', Account::walletFor($agent, 'USD')->balance());
        $this->assertSame('20000.00', Account::walletFor($agent, 'UGX')->balance());
        $this->assertSame('0.00', Account::byCode(Account::USD_UGANDA)->balance());
        $this->assertSame('0.00', Account::byCode(Account::UGX_UGANDA)->balance());
    }

    public function test_a_booking_taken_at_the_office_desk_goes_straight_to_the_till(): void
    {
        $admin = $this->officeUser('admin', 'Office Admin');

        $transaction = $this->booking($admin, 'CUS-003', 'UGX', '480000', '20000');

        $this->assertSame(['money_in:UGX-UG' => '500000.00'], $this->lines($transaction->id)->all());
        $this->assertSame('0.00', Account::walletFor($admin, 'UGX')->balance());
        $this->assertSame('0', (string) AccountEntry::where('type', 'agent_fee')->count());
    }

    public function test_the_charge_is_never_counted_twice(): void
    {
        $agent = $this->agent();
        $admin = $this->officeUser('admin', 'Desk Admin');

        $this->booking($agent, 'CUS-004', 'UGX', '480000', '20000');
        $this->booking($admin, 'CUS-005', 'UGX', '480000', '20000');

        // Two customers each handed over 500,000, so the books hold exactly 1,000,000. One of those
        // sums sits with the agent and the other in the till, and neither of them appears twice.
        $this->assertSame('1000000.00', bcsub(
            (string) AccountEntry::where('direction', 'in')->sum('amount'),
            (string) AccountEntry::where('direction', 'out')->sum('amount'),
            2,
        ));
    }

    public function test_handing_the_cash_in_moves_it_to_the_till_and_changes_nobody_s_total(): void
    {
        $agent = $this->agent();
        $transaction = $this->booking($agent, 'CUS-006', 'UGX', '480000', '20000');

        $before = bcsub(
            (string) AccountEntry::where('direction', 'in')->sum('amount'),
            (string) AccountEntry::where('direction', 'out')->sum('amount'),
            2,
        );

        [$out, $in] = AccountLedger::handInCash($agent, 'UGX', '480000', "Day's collections on {$transaction->receipt_number}");

        $this->assertSame('transfer_out', $out->type);
        $this->assertSame('transfer_in', $in->type);
        $this->assertSame('480000.00', (string) $out->amount);
        $this->assertSame('W-UGX-'.$agent->id, $out->account->code);
        $this->assertSame('UGX-UG', $in->account->code);

        $this->assertSame('20000.00', Account::walletFor($agent, 'UGX')->fresh()->balance());
        $this->assertSame('480000.00', Account::byCode(Account::UGX_UGANDA)->balance());
        $this->assertSame($before, bcsub(
            (string) AccountEntry::where('direction', 'in')->sum('amount'),
            (string) AccountEntry::where('direction', 'out')->sum('amount'),
            2,
        ));

        $this->assertStringContainsString('Cash handed in by Sarah Nalin: UGX 480,000', $out->description);
        $this->assertStringContainsString("Day's collections on CUS-006", $out->description);
    }

    public function test_a_hand_in_lands_in_the_china_accounts_for_a_desk_in_china(): void
    {
        $agent = $this->agent('Mei Lin', 'CN');
        AccountLedger::record(Account::walletFor($agent, 'USD'), 'adjustment_in', '400.00', 'Float carried from the office');

        [$out, $in] = AccountLedger::handInCash($agent, 'USD', '250.00');

        $this->assertSame('USD-CN', $in->account->code);
        $this->assertSame('150.00', Account::walletFor($agent, 'USD')->fresh()->balance());
        $this->assertSame('250.00', Account::byCode(Account::USD_CHINA)->balance());
    }

    public function test_an_agent_cannot_hand_in_more_than_the_drawer_holds(): void
    {
        $agent = $this->agent();
        $this->booking($agent, 'CUS-007', 'UGX', '480000', '20000');

        try {
            AccountLedger::handInCash($agent, 'UGX', '500001');
            $this->fail('The ledger let a hand-in take cash that is not there.');
        } catch (InsufficientFundsException $e) {
            $this->assertStringContainsString('holds UGX 500,000', $e->getMessage());
        }

        $this->assertSame('500000.00', Account::walletFor($agent, 'UGX')->balance());
        $this->assertSame('0.00', Account::byCode(Account::UGX_UGANDA)->balance());
    }

    public function test_cancelling_a_collection_takes_the_cash_back_from_the_agent_holding_it(): void
    {
        $agent = $this->agent();
        $transaction = $this->booking($agent, 'CUS-008', 'UGX', '480000', '20000');

        $transaction->update(['status' => 'cancelled']);

        $this->assertSame([
            'money_in:W-UGX-'.$agent->id => '480000.00',
            'agent_fee:W-UGX-'.$agent->id => '20000.00',
            'reversal:W-UGX-'.$agent->id => '480000.00',
            'agent_fee_reversal:W-UGX-'.$agent->id => '20000.00',
        ], $this->lines($transaction->id)->all());

        $this->assertSame('0.00', Account::walletFor($agent, 'UGX')->fresh()->balance());
    }

    public function test_an_agent_records_their_own_hand_in_from_the_wallet_page(): void
    {
        $agent = $this->agent();
        $this->booking($agent, 'CUS-009', 'UGX', '480000', '20000');

        $this->actingAs($agent)->post(route('wallets.hand-in'), [
            'currency' => 'UGX',
            'amount' => '480000',
            'note' => 'Carried to the office the same evening',
        ])->assertRedirect()->assertSessionHas('success');

        $this->assertSame('20000.00', Account::walletFor($agent, 'UGX')->fresh()->balance());
        $this->assertSame('480000.00', Account::byCode(Account::UGX_UGANDA)->balance());

        $log = ActivityLog::where('action', 'cash_handed_in')->latest('id')->first();
        $this->assertNotNull($log);
        $this->assertSame($agent->id, $log->user_id);
        $this->assertStringContainsString('Sarah Nalin handed in UGX 480,000', $log->description);
    }

    public function test_handing_in_more_than_the_drawer_holds_names_the_amount_box(): void
    {
        $agent = $this->agent();
        $this->booking($agent, 'CUS-010', 'UGX', '480000', '20000');

        $this->actingAs($agent)->post(route('wallets.hand-in'), [
            'currency' => 'UGX',
            'amount' => '900000',
        ])->assertSessionHasErrors('amount');

        $this->assertSame('500000.00', Account::walletFor($agent, 'UGX')->balance());
    }

    public function test_an_agent_cannot_hand_in_money_this_desk_does_not_hold(): void
    {
        $agent = $this->agent();

        $this->actingAs($agent)->post(route('wallets.hand-in'), [
            'currency' => 'RMB',
            'amount' => '100',
        ])->assertSessionHasErrors('currency');
    }

    public function test_an_agent_cannot_record_a_hand_in_for_somebody_else(): void
    {
        $mine = $this->agent('Sarah Nalin');
        $theirs = $this->agent('Peter Okello');
        $this->booking($theirs, 'CUS-011', 'UGX', '480000', '20000');

        $this->actingAs($mine)->post(route('wallets.hand-in'), [
            'agent_id' => $theirs->id,
            'currency' => 'UGX',
            'amount' => '480000',
        ])->assertSessionHasErrors('agent_id');

        $this->assertSame('500000.00', Account::walletFor($theirs, 'UGX')->balance());
    }

    public function test_the_office_records_a_hand_in_for_an_agent(): void
    {
        $agent = $this->agent();
        $admin = $this->officeUser('admin', 'Office Admin');
        $this->booking($agent, 'CUS-012', 'UGX', '480000', '20000');

        $this->actingAs($admin)->post(route('wallets.hand-in'), [
            'agent_id' => $agent->id,
            'currency' => 'UGX',
            'amount' => '480000',
        ])->assertRedirect()->assertSessionHas('success');

        $this->assertSame('480000.00', Account::byCode(Account::UGX_UGANDA)->balance());

        $log = ActivityLog::where('action', 'cash_handed_in')->latest('id')->first();
        $this->assertSame($admin->id, $log->user_id);
        $this->assertStringContainsString('recorded by Office Admin', $log->description);
    }

    public function test_a_person_who_holds_no_collections_cannot_hand_cash_in(): void
    {
        $staff = $this->officeUser('staff', 'Desk Staff');

        $this->actingAs($staff)->post(route('wallets.hand-in'), [
            'currency' => 'UGX',
            'amount' => '5000',
        ])->assertSessionHasErrors('agent_id');
    }

    public function test_the_wallet_page_shows_the_drawer_split_into_what_is_held_and_what_was_handed_in(): void
    {
        $agent = $this->agent();
        $this->booking($agent, 'CUS-013', 'UGX', '480000', '20000');
        AccountLedger::handInCash($agent, 'UGX', '480000');

        $page = $this->actingAs($agent)->get(route('wallet.index'))->assertOk()->getContent();

        $this->assertStringContainsString('Hand cash in to the office', $page);
        $this->assertStringContainsString('Cash history', $page);
        $this->assertStringContainsString('Left in hand', $page);
        $this->assertStringContainsString('UGX 20,000', $page);
        $this->assertStringContainsString('Commission kept', $page);
        $this->assertStringContainsString('Handed in so far', $page);
        // The words a person standing at a counter would use, not the words the books use.
        $this->assertStringContainsString('Handed in', $page);
        $this->assertStringNotContainsString('Sent to another account', $page);
    }

    public function test_the_hand_in_amount_opens_at_what_the_drawer_holds(): void
    {
        $agent = $this->agent();
        $this->booking($agent, 'CUS-016', 'UGX', '480000', '20000');

        $page = $this->actingAs($agent)->get(route('wallet.index'))->assertOk()->getContent();

        // Handing in the whole bag is the usual case, so the box opens at the balance and
        // each wallet carries its own figure for the box to follow when the money changes.
        $this->assertStringContainsString('min="0.01" max="500000" required value="500000"', $page);
        $this->assertStringContainsString('value="UGX" data-balance="500000"', $page);

        $empty = $this->agent('Peter Okello');
        $page = $this->actingAs($empty)->get(route('wallet.index'))->assertOk()->getContent();

        $this->assertStringContainsString('min="0.01" max="" required value=""', $page, 'An empty drawer opens at an empty box, not at a zero it cannot send.');
    }

    public function test_the_agent_list_shows_what_each_desk_still_holds(): void
    {
        $agent = $this->agent();
        $admin = $this->officeUser('admin', 'Office Admin');

        $this->booking($agent, 'CUS-014', 'UGX', '480000', '20000');

        $page = $this->actingAs($admin)->get(route('wallet.index'))->assertOk()->getContent();

        $this->assertStringContainsString('UGX in hand', $page);
        $this->assertStringContainsString('Record cash an agent handed in', $page);
    }

    public function test_the_dashboard_says_whose_hand_the_money_is_in(): void
    {
        $agent = $this->agent();
        $this->booking($agent, 'CUS-015', 'UGX', '480000', '20000');

        $this->actingAs($agent)->get(route('dashboard'))
            ->assertOk()
            ->assertSee('In your hand until you hand it in')
            ->assertSee('UGX 500,000');
    }
}
