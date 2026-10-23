<?php

namespace Tests\Feature;

use App\Exceptions\InsufficientFundsException;
use App\Models\Account;
use App\Models\AccountEntry;
use App\Models\ActivityLog;
use App\Models\CashHandover;
use App\Models\ExchangeRate;
use App\Models\Transaction;
use App\Models\User;
use App\Services\AccountLedger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * The cash a customer hands over is in somebody's hand, and until it reaches the super agent or the
 * office till the only place it can be accounted for is the agent who took it. These tests pin where
 * each booking's notes land, that a request moves nothing until it is approved, and that the whole
 * book never gains a shilling a customer did not actually hand over.
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

    private function superAgent(string $name = 'Amina Lead', string $country = 'UG'): User
    {
        $user = User::create([
            'name' => $name,
            'email' => strtolower(str_replace(' ', '', $name)).'@plussave.test',
            'password' => 'Password123',
            'is_active' => true,
            'country' => $country,
        ]);

        $user->assignRole('super-agent');

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

    public function test_handing_the_cash_to_the_super_agent_moves_it_and_changes_nobody_s_total(): void
    {
        $agent = $this->agent();
        $lead = $this->superAgent();
        $transaction = $this->booking($agent, 'CUS-006', 'UGX', '480000', '20000');

        $before = bcsub(
            (string) AccountEntry::where('direction', 'in')->sum('amount'),
            (string) AccountEntry::where('direction', 'out')->sum('amount'),
            2,
        );

        $handover = CashHandover::request($agent, $lead, 'UGX', '480000', "Day's collections on {$transaction->receipt_number}");
        $this->assertTrue($handover->approve());

        $out = AccountEntry::where('reference', $handover->reference)->where('type', 'handover_out')->with('account')->firstOrFail();
        $in = AccountEntry::where('reference', $handover->reference)->where('type', 'handover_in')->with('account')->firstOrFail();

        $this->assertSame('480000.00', (string) $out->amount);
        $this->assertSame('W-UGX-'.$agent->id, $out->account->code);
        $this->assertSame('W-UGX-'.$lead->id, $in->account->code);

        $this->assertSame('20000.00', Account::walletFor($agent, 'UGX')->fresh()->balance());
        $this->assertSame('480000.00', Account::walletFor($lead, 'UGX')->fresh()->balance());
        $this->assertSame('0.00', Account::byCode(Account::UGX_UGANDA)->balance());
        $this->assertSame($before, bcsub(
            (string) AccountEntry::where('direction', 'in')->sum('amount'),
            (string) AccountEntry::where('direction', 'out')->sum('amount'),
            2,
        ));

        $this->assertStringContainsString('Cash handover '.$handover->reference, $out->description);
        $this->assertStringContainsString('Sarah Nalin handed UGX 480,000 to Amina Lead', $out->description);
        $this->assertStringContainsString("Day's collections on CUS-006", $out->description);
    }

    public function test_a_handover_lands_in_the_super_agent_wallet_of_the_same_currency(): void
    {
        $agent = $this->agent('Mei Lin', 'CN');
        $lead = $this->superAgent('Wei Chen', 'CN');
        AccountLedger::record(Account::walletFor($agent, 'USD'), 'adjustment_in', '400.00', 'Float carried from the office');

        $handover = CashHandover::request($agent, $lead, 'USD', '250.00');
        $this->assertTrue($handover->approve());

        $this->assertSame('150.00', Account::walletFor($agent, 'USD')->fresh()->balance());
        $this->assertSame('250.00', Account::walletFor($lead, 'USD')->fresh()->balance());
        $this->assertSame('0.00', Account::byCode(Account::USD_CHINA)->balance());
    }

    public function test_an_approval_cannot_take_cash_the_drawer_no_longer_holds(): void
    {
        $agent = $this->agent();
        $lead = $this->superAgent();
        $this->booking($agent, 'CUS-007', 'UGX', '480000', '20000');

        // The request screen refuses when the drawer does not cover the ticks, but the approval
        // carries the same guard: money can never leave a drawer that does not hold it, whichever
        // door the request came through.
        $handover = CashHandover::request($agent, $lead, 'UGX', '500001');

        try {
            $handover->approve();
            $this->fail('The ledger let an approval take cash that is not there.');
        } catch (InsufficientFundsException $e) {
            $this->assertStringContainsString('holds UGX 500,000', $e->getMessage());
        }

        $this->assertSame('500000.00', Account::walletFor($agent, 'UGX')->balance());
        $this->assertSame('0.00', Account::walletFor($lead, 'UGX')->balance());
        $this->assertSame(CashHandover::PENDING, $handover->fresh()->status);
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

    public function test_an_agent_asks_the_super_agent_to_take_the_cash_and_nothing_moves_yet(): void
    {
        $agent = $this->agent();
        $lead = $this->superAgent();
        $transaction = $this->booking($agent, 'CUS-009', 'UGX', '480000', '20000');

        $this->actingAs($agent)->post(route('cash-handovers.from-transactions'), [
            'super_agent_id' => $lead->id,
            'transaction_ids' => [$transaction->id],
            'note' => 'Carried to the office the same evening',
        ])->assertRedirect()->assertSessionHas('success');

        $handover = CashHandover::latest('id')->firstOrFail();

        $this->assertSame(CashHandover::PENDING, $handover->status);
        $this->assertSame('480000.00', (string) $handover->amount);
        $this->assertSame('500000.00', Account::walletFor($agent, 'UGX')->fresh()->balance());
        $this->assertSame('0.00', Account::walletFor($lead, 'UGX')->balance());
        $this->assertSame(0, AccountEntry::whereIn('type', ['handover_out', 'handover_in'])->count(),
            'A pending request is not a movement of money.');

        $log = ActivityLog::where('action', 'cash_handover_requested')->latest('id')->first();
        $this->assertSame($agent->id, $log->user_id);
        $this->assertStringContainsString('asked Amina Lead to take UGX 480,000', $log->description);
    }

    public function test_the_super_agent_approves_and_the_cash_moves_into_their_wallet(): void
    {
        $agent = $this->agent();
        $lead = $this->superAgent();
        $this->booking($agent, 'CUS-012', 'UGX', '480000', '20000');
        $handover = CashHandover::request($agent, $lead, 'UGX', '480000');

        $this->actingAs($lead)->post(route('cash-handovers.approve', $handover))
            ->assertRedirect()->assertSessionHas('success');

        $this->assertSame(CashHandover::APPROVED, $handover->fresh()->status);
        $this->assertSame('20000.00', Account::walletFor($agent, 'UGX')->fresh()->balance());
        $this->assertSame('480000.00', Account::walletFor($lead, 'UGX')->fresh()->balance());
        $this->assertSame('0.00', Account::byCode(Account::UGX_UGANDA)->balance());

        $log = ActivityLog::where('action', 'cash_handover_approved')->latest('id')->first();
        $this->assertSame($lead->id, $log->user_id);
        $this->assertStringContainsString('Amina Lead received UGX 480,000 from Sarah Nalin', $log->description);
    }

    public function test_an_agent_cannot_hand_in_money_this_desk_does_not_hold(): void
    {
        $agent = $this->agent();
        $lead = $this->superAgent();

        // An RMB row can only exist beside a Uganda desk by accident, but the tick flow refuses it
        // all the same: a Uganda hand-in carries shillings or dollars, never yuan.
        $transaction = $this->booking($agent, 'CUS-010', 'RMB', '100', '0');

        $this->actingAs($agent)->post(route('cash-handovers.from-transactions'), [
            'super_agent_id' => $lead->id,
            'transaction_ids' => [$transaction->id],
        ])->assertSessionHasErrors('currency');

        $this->assertStringContainsString('does not hold RMB', session('errors')->first('currency'));
        $this->assertSame(0, CashHandover::count(), 'A refused request is not written down.');
    }

    public function test_only_the_super_agent_named_on_the_request_may_decide_it(): void
    {
        $agent = $this->agent();
        $lead = $this->superAgent();
        $otherLead = $this->superAgent('Peter Lead');
        $this->booking($agent, 'CUS-011', 'UGX', '480000', '20000');
        $handover = CashHandover::request($agent, $lead, 'UGX', '480000');

        // The agent who asked cannot sign it off, and neither can a super agent it was not sent to.
        $this->actingAs($agent)->post(route('cash-handovers.approve', $handover))->assertForbidden();
        $this->actingAs($otherLead)->post(route('cash-handovers.approve', $handover))->assertForbidden();
        $this->actingAs($otherLead)->post(route('cash-handovers.decline', $handover), [
            'decision_note' => 'Not my request',
        ])->assertForbidden();

        $this->assertSame(CashHandover::PENDING, $handover->fresh()->status);
        $this->assertSame('500000.00', Account::walletFor($agent, 'UGX')->balance());
        $this->assertSame('0.00', Account::walletFor($lead, 'UGX')->balance());
    }

    public function test_a_person_who_holds_no_collections_cannot_ask_to_hand_cash_in(): void
    {
        $agent = $this->agent();
        $staff = $this->officeUser('staff', 'Desk Staff');
        $lead = $this->superAgent();
        $transaction = $this->booking($agent, 'CUS-011', 'UGX', '480000', '20000');

        // A desk that took no cash has no hand-in to raise, and ticking another person's transfers
        // does not give it one.
        $this->actingAs($staff)->post(route('cash-handovers.from-transactions'), [
            'super_agent_id' => $lead->id,
            'transaction_ids' => [$transaction->id],
        ])->assertSessionHasErrors('super_agent_id');

        $this->assertSame(0, CashHandover::count());
    }

    public function test_the_wallet_page_shows_the_drawer_the_requests_and_the_history(): void
    {
        $agent = $this->agent();
        $lead = $this->superAgent();
        $this->booking($agent, 'CUS-013', 'UGX', '480000', '20000');
        $handover = CashHandover::request($agent, $lead, 'UGX', '480000');
        $handover->approve();

        $page = $this->actingAs($agent)->get(route('wallet.index'))->assertOk()->getContent();

        $this->assertStringContainsString('ticking the transfers', $page);
        $this->assertStringContainsString('Cash hand-in requests', $page);
        $this->assertStringContainsString('Approved', $page);
        $this->assertStringContainsString('Cash history', $page);
        $this->assertStringContainsString('Left in hand', $page);
        $this->assertStringContainsString('UGX 20,000', $page);
        // The words a person standing at a counter would use, not the words the books use.
        $this->assertStringContainsString('Handed in', $page);
        $this->assertStringNotContainsString('Sent to another account', $page);
    }

    public function test_the_wallet_page_carries_no_flat_hand_in_form_whether_or_not_a_super_agent_is_active(): void
    {
        $agent = $this->agent();

        // Without a lead the old page offered a notice and nothing else; with one it offered the
        // typed form. Both are gone: the wallet page points at the tick flow and never opens a box.
        $page = $this->actingAs($agent)->get(route('wallet.index'))->assertOk()->getContent();
        $this->assertStringNotContainsString('hand-in-amount', $page);
        $this->assertStringNotContainsString('Send for approval', $page);
        $this->assertStringNotContainsString('No super agent is active yet', $page);

        $lead = $this->superAgent();
        $this->flushSession();
        $page = $this->actingAs($agent)->get(route('wallet.index'))->assertOk()->getContent();
        $this->assertStringContainsString('ticking the transfers', $page);
        $this->assertStringNotContainsString('hand-in-amount', $page);
        $this->assertStringNotContainsString('Send for approval', $page);
        $this->assertStringNotContainsString($lead->name, $page, 'The super agent list no longer rides on the wallet page.');
    }

    public function test_the_agent_list_shows_what_each_desk_still_holds(): void
    {
        $agent = $this->agent();
        $admin = $this->officeUser('admin', 'Office Admin');

        $this->booking($agent, 'CUS-014', 'UGX', '480000', '20000');

        $page = $this->actingAs($admin)->get(route('wallet.index'))->assertOk()->getContent();

        $this->assertStringContainsString('UGX in hand', $page);
        // The office no longer records a hand-in on the agent's behalf: the super agent's approval
        // is the only thing that moves the cash.
        $this->assertStringNotContainsString('Record cash an agent handed in', $page);
    }

    public function test_the_dashboard_wallet_card_prints_the_balance_and_nothing_more(): void
    {
        $agent = $this->agent();
        $this->booking($agent, 'CUS-015', 'UGX', '480000', '20000');

        $this->actingAs($agent)->get(route('dashboard'))
            ->assertOk()
            ->assertSee('UGX Wallet')
            ->assertSee('UGX 500,000')
            ->assertDontSee('Cash on hand, not yet deposited')
            ->assertDontSee('Of which awaiting approval')
            ->assertDontSee('Fees this month')
            ->assertDontSee('Handed in so far');
    }
}
