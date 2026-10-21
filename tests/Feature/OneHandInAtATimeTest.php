<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\CashHandover;
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

    public function test_a_second_hand_in_is_refused_while_the_first_is_pending(): void
    {
        $agent = $this->person('agent', 'Sarah Nalin', 'UG', '500000');
        $lead = $this->person('super-agent', 'Amina Lead');
        $first = CashHandover::request($agent, $lead, 'UGX', '480000');

        $this->actingAs($agent)->post(route('cash-handovers.store'), [
            'super_agent_id' => $lead->id,
            'currency' => 'UGX',
            'amount' => '100000',
        ])->assertSessionHasErrors('handover');

        $this->assertSame(1, CashHandover::count(), 'The second request must not be written down.');
        $this->assertSame('500000.00', Account::walletFor($agent, 'UGX')->fresh()->balance());
        $message = session('errors')->first('handover');
        $this->assertStringContainsString($first->reference, $message);
        $this->assertStringContainsString('Amina Lead', $message);
        $this->assertStringContainsString('UGX 480,000', $message);
    }

    public function test_a_pending_request_in_one_wallet_holds_the_whole_hand_in(): void
    {
        $agent = $this->person('agent', 'Sarah Nalin', 'UG', '500000');
        AccountLedger::record(Account::walletFor($agent, 'USD'), 'adjustment_in', '100', 'Dollar float');
        $lead = $this->person('super-agent', 'Amina Lead');

        CashHandover::request($agent, $lead, 'UGX', '480000');

        $this->actingAs($agent)->post(route('cash-handovers.store'), [
            'super_agent_id' => $lead->id,
            'currency' => 'USD',
            'amount' => '50',
        ])->assertSessionHasErrors('handover');

        $this->assertSame(1, CashHandover::count());
    }

    public function test_the_wallet_page_stops_offering_the_form_while_a_request_is_open(): void
    {
        $agent = $this->person('agent', 'Sarah Nalin', 'UG', '500000');
        $lead = $this->person('super-agent', 'Amina Lead');
        $handover = CashHandover::request($agent, $lead, 'UGX', '480000');

        $page = $this->actingAs($agent)->get('/wallet')->assertOk()->getContent();

        $this->assertStringContainsString('A hand-in is already waiting for approval.', $page);
        $this->assertStringContainsString($handover->reference, $page);
        $this->assertStringContainsString('Amina Lead', $page);
        $this->assertStringContainsString('UGX 480,000', $page);
        $this->assertSame(0, substr_count($page, 'action="'.route('cash-handovers.store').'"'),
            'The send form must be gone while a hand-in is open.');
    }

    public function test_the_agent_can_send_again_once_the_open_request_is_sent_back(): void
    {
        $agent = $this->person('agent', 'Sarah Nalin', 'UG', '500000');
        $lead = $this->person('super-agent', 'Amina Lead');
        $first = CashHandover::request($agent, $lead, 'UGX', '480000');
        $first->decline('The bag holds less than the note says.');

        $this->actingAs($agent)->post(route('cash-handovers.store'), [
            'super_agent_id' => $lead->id,
            'currency' => 'UGX',
            'amount' => '480000',
        ])->assertRedirect()->assertSessionHas('success');

        $this->assertSame(2, CashHandover::count());
        $this->assertSame(CashHandover::PENDING, CashHandover::latest('id')->first()->status);
    }
}
