<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\CashHandover;
use App\Models\ExchangeRate;
use App\Models\Transaction;
use App\Models\User;
use App\Services\AccountLedger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * An agent carries one drawer. Until the super agent has taken one hand-in in hand, a second
 * request would promise the same cash twice and leave the first request impossible to reconcile,
 * so the desk carries one request at a time and the screen says which one is open.
 */
class OneHandInAtATimeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        ExchangeRate::create(['from_currency' => 'USD', 'to_currency' => 'RMB', 'rate' => 6.75, 'buy_rate' => 6.75, 'sell_rate' => 6.75, 'is_active' => true]);
        ExchangeRate::create(['from_currency' => 'USD', 'to_currency' => 'UGX', 'rate' => 3760, 'buy_rate' => 3750, 'sell_rate' => 3760, 'is_active' => true]);
    }

    private function person(string $role, string $name, string $country = 'UG', ?string $float = null, string $currency = 'UGX'): User
    {
        $user = User::create([
            'name' => $name,
            'email' => strtolower(str_replace(' ', '', $name)).'@plussave.test',
            'password' => 'Password123',
            'is_active' => true,
            'country' => $country,
        ]);

        $user->assignRole($role);

        if ($float !== null) {
            AccountLedger::record(Account::walletFor($user, $currency), 'adjustment_in', $float, 'Float from the office');
        }

        return $user;
    }

    /**
     * Money already taken in at the counter, the same shape the Transactions page ticks.
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
            'super_agent_id' => $leadId ?? User::role('super-agent')->value('id'),
            'transaction_ids' => $ids,
            'note' => 'Counter cash',
        ]);
    }

    public function test_a_second_hand_in_is_refused_while_the_first_is_pending(): void
    {
        $agent = $this->person('agent', 'Sarah Nalin', 'UG', '500000');
        $lead = $this->person('super-agent', 'Amina Lead');
        $firstBooking = $this->booked('OH-001', $agent, 'UGX', '480000', '20000');

        $this->handIn($agent, [$firstBooking->id], $lead->id)->assertSessionHas('success');
        $first = CashHandover::sole();

        $secondBooking = $this->booked('OH-002', $agent, 'UGX', '100000', '5000');
        $before = Account::walletFor($agent, 'UGX')->fresh()->balance();
        $this->handIn($agent, [$secondBooking->id], $lead->id)->assertSessionHasErrors('handover');

        $this->assertSame(1, CashHandover::count(), 'The second request must not be written down.');
        $this->assertSame($before, Account::walletFor($agent, 'UGX')->fresh()->balance(),
            'A refused request moves no money.');
        $message = $this->flashedError('handover');
        $this->assertStringContainsString($first->reference, $message);
        $this->assertStringContainsString('Amina Lead', $message);
        $this->assertStringContainsString('UGX 480,000', $message);
    }

    public function test_a_pending_request_in_one_wallet_holds_the_whole_hand_in(): void
    {
        $agent = $this->person('agent', 'Sarah Nalin', 'UG', '500000');
        AccountLedger::record(Account::walletFor($agent, 'USD'), 'adjustment_in', '100', 'Dollar float');
        $lead = $this->person('super-agent', 'Amina Lead');

        $ugx = $this->booked('OH-010', $agent, 'UGX', '480000', '20000');
        $this->handIn($agent, [$ugx->id], $lead->id)->assertSessionHas('success');

        $usd = $this->booked('OH-011', $agent, 'USD', '100', '2000');
        $this->handIn($agent, [$usd->id], $lead->id)->assertSessionHasErrors('handover');

        $this->assertSame(1, CashHandover::count());
    }

    public function test_the_wallet_page_offers_no_typed_hand_in_anymore(): void
    {
        $agent = $this->person('agent', 'Sarah Nalin', 'UG', '500000');
        $this->person('super-agent', 'Amina Lead');

        $page = $this->actingAs($agent)->get('/wallet')->assertOk()->getContent();

        $this->assertStringNotContainsString('hand-in-amount', $page, 'The typed amount box is gone.');
        $this->assertStringNotContainsString('hand-in-super-agent', $page, 'The super agent dropdown is gone.');
        $this->assertStringNotContainsString('Hand cash in to your super agent', $page);
        $this->assertStringContainsString('ticking the transfers', $page, 'The wallet page points at the way that remains.');
    }

    public function test_the_agent_can_hand_in_again_once_the_open_request_is_sent_back(): void
    {
        $agent = $this->person('agent', 'Sarah Nalin', 'UG', '500000');
        $lead = $this->person('super-agent', 'Amina Lead');
        $firstBooking = $this->booked('OH-020', $agent, 'UGX', '480000', '20000');
        $this->handIn($agent, [$firstBooking->id], $lead->id)->assertSessionHas('success');
        CashHandover::sole()->decline('The bag holds less than the note says.');

        $secondBooking = $this->booked('OH-021', $agent, 'UGX', '240000', '10000');
        $this->handIn($agent, [$secondBooking->id], $lead->id)
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertSame(2, CashHandover::count());
        $this->assertSame(CashHandover::PENDING, CashHandover::latest('id')->first()->status);
    }
}
