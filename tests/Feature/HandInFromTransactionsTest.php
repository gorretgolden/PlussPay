<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\AccountEntry;
use App\Models\ActivityLog;
use App\Models\CashHandover;
use App\Models\ExchangeRate;
use App\Models\Transaction;
use App\Models\User;
use Database\Seeders\PermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Handing cash in by naming the transfers it came from, rather than typing a figure nobody can
 * check. The request is the pair of piles the booking put in the agent's hand: the money that
 * travels on, and the charge that does not. These tests pin that the names become a request, that
 * the request is the only thing that moves the money, that the agent can call it back, and that a
 * charge never crosses to China whatever the desk does afterwards.
 */
class HandInFromTransactionsTest extends TestCase
{
    use RefreshDatabase;

    private User $office;
    private User $agent;
    private User $lead;
    private User $other;
    private User $china;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PermissionsSeeder::class);

        ExchangeRate::create(['from_currency' => 'USD', 'to_currency' => 'RMB', 'rate' => 6.75, 'buy_rate' => 6.75, 'sell_rate' => 6.75, 'is_active' => true]);
        ExchangeRate::create(['from_currency' => 'USD', 'to_currency' => 'UGX', 'rate' => 3760, 'buy_rate' => 3750, 'sell_rate' => 3760, 'is_active' => true]);

        $this->office = $this->person('Office Admin', 'admin', 'UG');
        $this->agent = $this->person('Kampala Agent', 'agent', 'UG');
        $this->lead = $this->person('Lead Agent', 'super-agent', 'UG');
        $this->other = $this->person('Other Agent', 'agent', 'UG');
        $this->china = $this->person('China Desk', 'agent', 'CN');
    }

    private function person(string $name, string $role, string $country): User
    {
        $user = User::create([
            'name' => $name,
            'email' => strtolower(str_replace(' ', '.', $name)).'@plussave.test',
            'password' => 'Password123',
            'is_active' => true,
            'country' => $country,
            'timezone' => User::timezoneForCountry($country),
        ]);

        $user->assignRole($role);

        return $user;
    }

    /**
     * Money already taken in at the counter: the principal that travels, and the charge the
     * customer agreed on top of it. Both are in the collecting hand before any of this runs.
     */
    private function booked(string $receipt, User $creator, string $currency = 'UGX', string $principal = '480000', string $charge = '20000'): Transaction
    {
        $dollars = $currency === 'USD';

        return Transaction::create([
            'receipt_number' => $receipt,
            'sender_name' => 'Sender '.$receipt,
            'sender_contact' => '',
            'receiver_name' => 'Receiver '.$receipt,
            'receiver_contact' => '',
            'destination' => 'CN',
            'payout_currency' => 'RMB',
            'currency' => $currency,
            'amount' => $principal,
            'exchange_rate' => $dollars ? 6.75 : 0,
            'rmb_amount' => $dollars ? (float) bcmul($principal, '6.75', 2) : (float) bcmul($principal, '0.0018', 2),
            'total_usd_equivalent' => $dollars ? (float) $principal : (float) bcdiv($principal, '3760', 4),
            'charge_rate' => 0,
            'charge_amount' => 0,
            'charge_ugx' => $charge,
            'status' => 'completed',
            'created_by' => $creator->id,
        ]);
    }

    /**
     * @param  array<int, int>  $ids
     */
    private function handIn(User $who, array $ids, ?int $leadId = null): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($who)->post(route('cash-handovers.from-transactions'), [
            'super_agent_id' => $leadId ?? $this->lead->id,
            'transaction_ids' => $ids,
            'note' => 'Counter cash',
        ]);
    }

    private function balance(User $who, string $currency): string
    {
        return Account::walletFor($who, $currency)->fresh()->balance();
    }

    public function test_naming_transfers_raises_one_request_for_the_cash_and_the_charges(): void
    {
        $first = $this->booked('HT-001', $this->agent, 'UGX', '480000', '20000');
        $second = $this->booked('HT-002', $this->agent, 'UGX', '240000', '10000');

        $this->handIn($this->agent, [$first->id, $second->id])
            ->assertRedirect()
            ->assertSessionHas('success');

        $handover = CashHandover::sole();

        $this->assertSame('720000.00', $handover->amount, 'The cash is the two principals added.');
        $this->assertSame('30000.00', $handover->charge_ugx, 'The charges ride with it as their own pile.');
        $this->assertSame('UGX', $handover->currency);
        $this->assertSame(CashHandover::PENDING, $handover->status);
        $this->assertSame([$first->receipt_number, $second->receipt_number], $handover->receiptNumbers()->all());

        foreach ([$first, $second] as $booking) {
            $booking = $booking->fresh();
            $this->assertSame($handover->id, $booking->cash_handover_id);
            $this->assertSame(CashHandover::PENDING, $booking->hand_in_status);
            $this->assertNotNull($booking->handed_in_at);
            $this->assertSame('Waiting for hand-in approval', $booking->progress()['label']);
        }

        // A request is a promise, not a movement: nothing has left either wallet.
        $this->assertSame('750000.00', $this->balance($this->agent, 'UGX'));
        $this->assertSame('0.00', $this->balance($this->lead, 'UGX'));
        $this->assertSame(0, AccountEntry::whereIn('type', ['handover_out', 'handover_in', 'fee_handover_out', 'fee_handover_in'])->count());

        // The list reads the booking as waiting, and no longer offers to hand its cash in.
        $page = $this->actingAs($this->agent)->get('/transactions')->assertOk()->getContent();
        $this->assertStringContainsString('Waiting for hand-in approval', $page);
        $this->assertStringNotContainsString('>Hand in this cash</button>', $page);

        // The request and its reference are the agent's own record on the wallet page.
        $this->assertStringContainsString($handover->reference, $this->actingAs($this->agent)->get('/wallet')->assertOk()->getContent());
    }

    public function test_approving_moves_the_cash_to_the_lead_and_the_charges_into_his_shillings(): void
    {
        $booking = $this->booked('HT-010', $this->agent, 'UGX', '480000', '20000');
        $this->handIn($this->agent, [$booking->id]);
        $handover = CashHandover::sole();

        $this->actingAs($this->lead)->post(route('cash-handovers.approve', $handover))
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertSame('0.00', $this->balance($this->agent, 'UGX'), 'Both piles left the agent\'s drawer.');
        $this->assertSame('500000.00', $this->balance($this->lead, 'UGX'), 'Cash and charges both land with the lead.');

        $lines = AccountEntry::where('reference', $handover->reference)
            ->with('account')
            ->get()
            ->mapWithKeys(fn (AccountEntry $entry) => [$entry->type.':'.$entry->account->code => (string) $entry->amount]);

        $this->assertSame([
            'handover_out:W-UGX-'.$this->agent->id => '480000.00',
            'handover_in:W-UGX-'.$this->lead->id => '480000.00',
            'fee_handover_out:W-UGX-'.$this->agent->id => '20000.00',
            'fee_handover_in:W-UGX-'.$this->lead->id => '20000.00',
        ], $lines->all(), 'The cash and the charges are written as two separate piles between the same two drawers.');

        $charges = AccountEntry::where('type', 'fee_handover_in')->sole();
        $this->assertSame($this->lead->id, (int) Account::whereKey($charges->account_id)->first()->owner_id);
        $this->assertSame('20000.00', (string) $charges->amount);

        $booking = $booking->fresh();
        $this->assertSame(CashHandover::APPROVED, $booking->hand_in_status);
        $this->assertSame('Ready to send to China', $booking->progress()['label']);
    }

    public function test_a_dollar_request_hands_the_shilling_charges_across_separately(): void
    {
        $booking = $this->booked('HT-020', $this->agent, 'USD', '1000', '20000');

        $this->handIn($this->agent, [$booking->id])
            ->assertSessionHas('success');

        $handover = CashHandover::sole();
        $this->assertSame('USD', $handover->currency);
        $this->assertSame('1000.00', $handover->amount);
        $this->assertSame('20000.00', $handover->charge_ugx);

        $this->actingAs($this->lead)->post(route('cash-handovers.approve', $handover))->assertSessionHas('success');

        $this->assertSame('0.00', $this->balance($this->agent, 'USD'));
        $this->assertSame('0.00', $this->balance($this->agent, 'UGX'));
        $this->assertSame('1000.00', $this->balance($this->lead, 'USD'), 'Only the dollars that travel changed hands.');
        $this->assertSame('20000.00', $this->balance($this->lead, 'UGX'), 'The charges came up in shillings and stay in Uganda.');
    }

    public function test_a_charge_stops_being_kept_money_the_moment_it_is_handed_up(): void
    {
        $booking = $this->booked('HT-025', $this->agent, 'UGX', '480000', '20000');
        $this->handIn($this->agent, [$booking->id]);

        $shilling = Account::walletFor($this->agent, 'UGX')->id;

        // While the request waits nothing has moved, so the charge is still his to keep.
        $this->actingAs($this->agent)->get('/wallet')
            ->assertViewHas('commission', fn (array $totals) => ($totals[$shilling] ?? null) === '20000.00');

        $this->actingAs($this->lead)->post(route('cash-handovers.approve', CashHandover::sole()))
            ->assertSessionHas('success');

        // It went over with the cash in the same hand, so a card that still counted it would pay him
        // twice for one transfer.
        $this->actingAs($this->agent)->get('/wallet')
            ->assertViewHas('commission', fn (array $totals) => ($totals[$shilling] ?? null) === '0.00');

        // The same pile is now what the lead holds and never sends on.
        $this->actingAs($this->lead)->get('/wallet')->assertViewHas('chargesKept', '20000.00');
    }

    public function test_the_charges_a_lead_holds_are_never_sent_to_china(): void
    {
        $booking = $this->booked('HT-030', $this->agent, 'USD', '1000', '20000');
        $this->handIn($this->agent, [$booking->id]);
        $this->actingAs($this->lead)->post(route('cash-handovers.approve', CashHandover::sole()))->assertSessionHas('success');

        $source = Account::walletFor($this->lead, 'USD');

        $this->actingAs($this->lead)->post('/china/send', [
            'transaction_ids' => [$booking->fresh()->id],
            'recipient_id' => $this->china->id,
            'source_account_id' => $source->id,
        ])->assertRedirect()->assertSessionHas('success');

        $this->assertSame('0.00', Account::walletFor($this->lead, 'USD')->fresh()->balance());
        $this->assertSame('1000.00', Account::walletFor($this->china, 'USD')->fresh()->balance());
        $this->assertSame('20000.00', $this->balance($this->lead, 'UGX'), 'The charge stayed behind with the lead.');

        $log = ActivityLog::where('action', 'sent_to_china')->sole();
        $this->assertSame('20000.00', json_decode((string) $log->new_value, true)['charges_left_behind_ugx']);
    }

    public function test_a_transfer_still_waiting_for_approval_cannot_be_sent(): void
    {
        $booking = $this->booked('HT-040', $this->agent, 'USD', '500', '5000');
        $this->handIn($this->agent, [$booking->id]);

        // Give the lead dollars from somewhere else, so the refusal can only be about the request.
        \App\Services\AccountLedger::record(Account::walletFor($this->lead, 'USD'), 'opening_balance', '500.00', 'Float');

        $this->actingAs($this->lead)->post('/china/send', [
            'transaction_ids' => [$booking->id],
            'recipient_id' => $this->china->id,
            'source_account_id' => Account::walletFor($this->lead, 'USD')->id,
        ])->assertSessionHasErrors('transaction_ids');

        $this->assertNull($booking->fresh()->settled_at);
        $this->assertSame('500.00', $this->balance($this->lead, 'USD'));
    }

    public function test_the_agent_can_call_their_own_request_back_and_the_transfers_are_free_again(): void
    {
        $booking = $this->booked('HT-050', $this->agent, 'UGX', '480000', '20000');
        $this->handIn($this->agent, [$booking->id]);
        $handover = CashHandover::sole();

        $this->actingAs($this->other)->post(route('cash-handovers.cancel', $handover), [
            'reason' => 'Not mine to cancel',
        ])->assertForbidden();

        $this->actingAs($this->agent)->post(route('cash-handovers.cancel', $handover), [
            'reason' => 'One transfer needs correcting first',
        ])->assertRedirect()->assertSessionHas('success');

        $this->assertSame(CashHandover::CANCELLED, $handover->fresh()->status);
        $booking = $booking->fresh();
        $this->assertNull($booking->cash_handover_id);
        $this->assertNull($booking->hand_in_status);
        $this->assertSame('Cash taken', $booking->progress()['label']);
        $this->assertSame('0.00', $this->balance($this->lead, 'UGX'), 'Calling it back moves no money.');

        // The same booking can be handed in again once the request is called back.
        $this->handIn($this->agent, [$booking->id])->assertSessionHas('success');
        $this->assertSame('480000.00', CashHandover::latest('id')->first()->amount);
    }

    public function test_a_decline_releases_the_transfers_it_named(): void
    {
        $booking = $this->booked('HT-060', $this->agent, 'UGX', '480000', '20000');
        $this->handIn($this->agent, [$booking->id]);
        $handover = CashHandover::sole();

        $this->actingAs($this->lead)->post(route('cash-handovers.decline', $handover), [
            'decision_note' => 'The bag holds less than the ticks say.',
        ])->assertRedirect()->assertSessionHas('success');

        $this->assertSame(CashHandover::DECLINED, $handover->fresh()->status);
        $booking = $booking->fresh();
        $this->assertNull($booking->cash_handover_id);
        $this->assertNull($booking->hand_in_status);
        $this->assertSame('500000.00', $this->balance($this->agent, 'UGX'));
        $this->assertSame('0.00', $this->balance($this->lead, 'UGX'));
    }

    public function test_the_names_are_read_back_from_the_book_and_not_from_the_form(): void
    {
        $mine = $this->booked('HT-070', $this->agent, 'UGX', '480000', '20000');
        $theirs = $this->booked('HT-071', $this->other, 'UGX', '300000', '10000');
        $cancelled = $this->booked('HT-072', $this->agent, 'UGX', '100000', '5000');
        $cancelled->update(['status' => 'cancelled']);
        $usd = $this->booked('HT-073', $this->agent, 'USD', '100', '4000');

        // Another hand's booking buys a refusal, not a request.
        $this->handIn($this->agent, [$theirs->id])->assertSessionHasErrors('transaction_ids');
        $this->assertSame(0, CashHandover::count());

        // A cancelled booking has no cash in front of anybody.
        $this->handIn($this->agent, [$cancelled->id])->assertSessionHasErrors('transaction_ids');

        // One request, one money.
        $this->handIn($this->agent, [$mine->id, $usd->id])->assertSessionHasErrors('transaction_ids');

        $this->assertSame(0, CashHandover::count());

        $this->handIn($this->agent, [$mine->id])->assertSessionHas('success');

        // A second request rides while the first is open, because each one names its own transfers.
        $this->handIn($this->agent, [$usd->id])->assertSessionHas('success');
        $this->assertSame(2, CashHandover::count());

        // And a booking already named on that request cannot be spoken for again.
        $this->flushSession();
        $this->handIn($this->agent, [$mine->id])->assertSessionHasErrors('transaction_ids');
        $this->assertSame(2, CashHandover::count());
    }

    public function test_the_office_can_decide_a_request_and_the_money_goes_to_the_lead_it_names(): void
    {
        $booking = $this->booked('HT-080', $this->agent, 'UGX', '480000', '20000');
        $this->handIn($this->agent, [$booking->id]);
        $handover = CashHandover::sole();

        $this->actingAs($this->office)->post(route('cash-handovers.approve', $handover))
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertSame(CashHandover::APPROVED, $handover->fresh()->status);
        $this->assertSame('0.00', $this->balance($this->agent, 'UGX'));
        $this->assertSame('500000.00', $this->balance($this->lead, 'UGX'), 'The lead named on the request holds it.');
        $this->assertSame('0.00', $this->balance($this->office, 'UGX'), 'An office approval does not put money in the approver\'s pocket.');

        $desk = $this->actingAs($this->office)->get(route('cash-handovers.index'))->assertOk()->getContent();
        $this->assertStringContainsString('Charges (UGX)', $desk);
        $this->assertStringContainsString($booking->receipt_number, $desk);
        $this->assertStringContainsString('Approved', $desk);
    }

    public function test_a_booking_named_on_a_request_cannot_be_cancelled_out_from_under_it(): void
    {
        $booking = $this->booked('HT-090', $this->agent, 'UGX', '480000', '20000');
        $this->handIn($this->agent, [$booking->id]);

        $this->actingAs($this->office)->patch(route('transactions.update-status', $booking->fresh()), [
            'status' => 'cancelled',
            'reason' => 'Customer changed their mind',
        ])->assertSessionHasErrors('status');

        $this->assertSame('completed', $booking->fresh()->status);
        $this->assertSame('500000.00', $this->balance($this->agent, 'UGX'));
    }

    public function test_the_list_offers_the_new_stages_and_the_badge_colors(): void
    {
        $booking = $this->booked('HT-100', $this->agent, 'UGX', '480000', '20000');
        $this->handIn($this->agent, [$booking->id]);
        $handover = CashHandover::sole();

        $waiting = $this->actingAs($this->agent)->get('/transactions?stage=hand_in')->assertOk()->getContent();
        $this->assertStringContainsString($booking->receipt_number, $waiting);

        $this->actingAs($this->lead)->post(route('cash-handovers.approve', $handover))->assertSessionHas('success');

        $ready = $this->actingAs($this->office)->get('/transactions?stage=ready')->assertOk()->getContent();
        $this->assertStringContainsString($booking->receipt_number, $ready);
        $this->assertStringContainsString('Ready to send to China', $ready);

        $this->assertStringNotContainsString($booking->receipt_number, $this->actingAs($this->office)->get('/transactions?stage=cash')->assertOk()->getContent());
    }

    /**
     * The shortcut on the board and the form it opens are checked together, because the complaint
     * that started this was an agent saying the feature does not exist. A tile that names the job
     * but lands on a page with no form on it would fail the same test.
     */
    public function test_the_agent_board_shortcut_reaches_the_hand_in_form(): void
    {
        $booking = $this->booked('HT-101', $this->agent, 'UGX', '480000', '20000');

        $board = $this->actingAs($this->agent)->get('/dashboard')->assertOk()->getContent();
        $this->assertStringContainsString('>Hand in Cash</span>', $board, 'The hand holding collected notes is offered the way in.');
        $this->assertStringContainsString('/cash-handovers/create', $board, 'The shortcut opens the form.');

        $form = $this->actingAs($this->agent)->get('/cash-handovers/create')->assertOk()->getContent();
        $this->assertStringContainsString($booking->receipt_number, $form, 'The transfer he is holding is on the form.');
        $this->assertStringContainsString('Send for approval', $form, 'The form carries the pen.');
        $this->assertStringContainsString('transaction_ids[]', $form, 'The ticks are the names the request will carry.');

        // The form sends through the same pen as the row menu, so what it raises is one request.
        $this->actingAs($this->agent)->from('/cash-handovers/create')->post(route('cash-handovers.from-transactions'), [
            'super_agent_id' => $this->lead->id,
            'transaction_ids' => [$booking->id],
        ])->assertSessionHas('success');

        $this->assertSame(1, CashHandover::count(), 'The form raised exactly one request.');
        $this->assertSame('500000.00', $this->balance($this->agent, 'UGX'), 'Nothing moved until the lead decides.');

        // The mirror: a lead receives what his hands sent up, and the China desk pays its receivers
        // out of what was sent to it. Neither is offered the shortcut or the form.
        foreach ([$this->lead, $this->china] as $mirror) {
            $this->assertStringNotContainsString(
                '>Hand in Cash</span>',
                $this->actingAs($mirror)->get('/dashboard')->assertOk()->getContent(),
                $mirror->name.' does not hand anything further up.'
            );
            $this->actingAs($mirror)->get('/cash-handovers/create')->assertRedirect(route('cash-handovers.index'));
        }
    }

    /**
     * The form must never offer a row the store would bounce, so the same cuts the store applies
     * are applied before the ticks are drawn: the hand's own bookings only, completed, and not
     * already named on a request. A row on the form that the pen refuses would be a trap.
     */
    public function test_the_form_lists_only_what_the_hand_can_send(): void
    {
        $this->booked('HT-102', $this->agent, 'UGX', '480000', '20000');
        $this->booked('HT-103', $this->other, 'UGX', '300000', '10000');
        $cancelled = $this->booked('HT-104', $this->agent, 'UGX', '100000', '5000');
        $cancelled->update(['status' => 'cancelled']);
        $spoken = $this->booked('HT-105', $this->agent, 'UGX', '200000', '8000');
        $this->handIn($this->agent, [$spoken->id]);

        $form = $this->actingAs($this->agent)->get('/cash-handovers/create')->assertOk()->getContent();

        $this->assertStringContainsString('HT-102', $form, 'The cash in hand is on the form.');
        $this->assertStringNotContainsString('HT-103', $form, 'Another hand\'s cash is not theirs to tick.');
        $this->assertStringNotContainsString('HT-104', $form, 'A cancelled booking gave its money back.');
        $this->assertStringNotContainsString('HT-105', $form, 'A transfer already on a request stays off the form.');

        // One money per request, so a mixed hand is split into groups the page can keep apart.
        $this->booked('HT-106', $this->agent, 'USD', '400', '10000');
        $mixed = $this->actingAs($this->agent)->get('/cash-handovers/create')->assertOk()->getContent();
        $this->assertStringContainsString('UGX money', $mixed);
        $this->assertStringContainsString('USD money', $mixed);

        // The requests page offers the same way in, and only to a hand that may hand in.
        $requests = $this->actingAs($this->agent)->get('/cash-handovers')->assertOk()->getContent();
        $this->assertStringContainsString('New hand-in', $requests);
        $this->assertStringNotContainsString(
            'New hand-in',
            $this->actingAs($this->lead)->get('/cash-handovers')->assertOk()->getContent(),
            'The lead receives requests; he does not raise them.'
        );
    }
}
