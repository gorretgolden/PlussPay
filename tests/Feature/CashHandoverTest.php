<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\AccountEntry;
use App\Models\ActivityLog;
use App\Models\CashHandover;
use App\Models\User;
use App\Services\AccountLedger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A hand-in is a request before it is a movement. These tests pin the two decisions a super agent
 * can make, that neither one can be taken twice, and that each side reads the same request with the
 * words that side needs: the agent sees why it came back, the super agent sees who is bringing what.
 */
class CashHandoverTest extends TestCase
{
    use RefreshDatabase;

    private function person(string $role, string $name, string $country = 'UG', ?string $float = null): User
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
            AccountLedger::record(Account::walletFor($user, 'UGX'), 'adjustment_in', $float, 'Float from the office');
        }

        return $user;
    }

    private function request(User $agent, User $lead, string $amount = '480000', ?string $note = null): CashHandover
    {
        return CashHandover::request($agent, $lead, 'UGX', $amount, $note);
    }

    public function test_the_request_only_reaches_the_super_agent_it_names(): void
    {
        $agent = $this->person('agent', 'Sarah Nalin', 'UG', '500000');
        $lead = $this->person('super-agent', 'Amina Lead');
        $other = $this->person('super-agent', 'Peter Lead');
        $handover = $this->request($agent, $lead);

        $this->flushSession();
        $this->actingAs($lead)->get('/dashboard')->assertOk()
            ->assertSee('Cash Handed To You')
            ->assertSee($handover->reference)
            ->assertSee('Approve receipt')
            ->assertSee('Sarah Nalin');

        $this->flushSession();
        $this->actingAs($other)->get('/dashboard')->assertOk()
            ->assertSee('No cash has been handed to you yet.')
            ->assertDontSee($handover->reference);
    }

    public function test_a_decline_moves_nothing_and_tells_the_agent_why(): void
    {
        $agent = $this->person('agent', 'Sarah Nalin', 'UG', '500000');
        $lead = $this->person('super-agent', 'Amina Lead');
        $handover = $this->request($agent, $lead, '480000', 'Carried in the evening');

        $this->actingAs($lead)->post(route('cash-handovers.decline', $handover), [])
            ->assertSessionHasErrors('decision_note');
        $this->assertSame(CashHandover::PENDING, $handover->fresh()->status);

        $this->flushSession();
        $this->actingAs($lead)->post(route('cash-handovers.decline', $handover), [
            'decision_note' => 'The note says 480,000 but the bag holds 450,000.',
        ])->assertRedirect()->assertSessionHas('success');

        $this->assertSame(CashHandover::DECLINED, $handover->fresh()->status);
        $this->assertSame('500000.00', Account::walletFor($agent, 'UGX')->fresh()->balance());
        $this->assertSame('0.00', Account::walletFor($lead, 'UGX')->fresh()->balance());
        $this->assertSame(0, AccountEntry::whereIn('type', ['handover_out', 'handover_in'])->count(),
            'A decline does not touch the ledger.');

        $log = ActivityLog::where('action', 'cash_handover_declined')->latest('id')->first();
        $this->assertSame($lead->id, $log->user_id);
        $this->assertStringContainsString('The note says 480,000', $log->description);

        $this->flushSession();
        $page = $this->actingAs($agent)->get('/wallet')->assertOk()->getContent();

        $this->assertStringContainsString('Cash hand-in requests', $page);
        $this->assertStringContainsString('Declined', $page);
        $this->assertStringContainsString('The note says 480,000 but the bag holds 450,000.', $page);
    }

    public function test_a_decided_request_cannot_be_decided_again(): void
    {
        $agent = $this->person('agent', 'Sarah Nalin', 'UG', '500000');
        $lead = $this->person('super-agent', 'Amina Lead');
        $handover = $this->request($agent, $lead);

        $this->actingAs($lead)->post(route('cash-handovers.approve', $handover))
            ->assertRedirect()->assertSessionHas('success');

        $this->assertSame('480000.00', Account::walletFor($lead, 'UGX')->fresh()->balance());

        $this->flushSession();
        $this->actingAs($lead)->post(route('cash-handovers.approve', $handover))
            ->assertSessionHasErrors('handover');

        $this->flushSession();
        $this->actingAs($lead)->post(route('cash-handovers.decline', $handover), [
            'decision_note' => 'Too late to send it back',
        ])->assertSessionHasErrors('handover');

        $this->assertSame(CashHandover::APPROVED, $handover->fresh()->status);
        $this->assertSame('480000.00', Account::walletFor($lead, 'UGX')->fresh()->balance());
        $this->assertSame('20000.00', Account::walletFor($agent, 'UGX')->fresh()->balance());
    }

    public function test_the_agent_dashboard_names_the_money_waiting_for_approval(): void
    {
        $agent = $this->person('agent', 'Sarah Nalin', 'UG', '500000');
        $lead = $this->person('super-agent', 'Amina Lead');
        $handover = $this->request($agent, $lead);

        // The wallet card is one figure now; the money a lead has not taken yet is named on the
        // hand-in insight card below it. The test pins the figure beside "Waiting on approval"
        // there. It falls to zero when the lead agent approves, and the wallet total never changes
        // at that moment.
        $pendingOnBoard = function (User $viewer): string {
            $this->flushSession();
            $html = $this->actingAs($viewer)->get('/dashboard')->assertOk()->getContent();

            $start = strpos($html, 'Cash You Hand In');
            $end = strpos($html, 'Quick Actions', $start);
            $this->assertNotFalse($start, 'The hand-in insight card is on the board.');
            $this->assertNotFalse($end, 'Quick Actions follows the hand-in insight card.');

            $insight = substr($html, $start, $end - $start);
            $this->assertSame(
                1,
                preg_match('#Waiting on approval</div>.*?<div class="text-\[13px\] text-gray-700 mt-0\.5 tabular-nums">([^<]+)</div>#s', $insight, $m),
                'The insight card names the part of the balance a super agent has not taken yet.'
            );

            return $m[1];
        };

        $this->assertSame('UGX 480,000', $pendingOnBoard($agent));
        $this->assertStringContainsString('UGX 500,000', $this->actingAs($agent)->get('/dashboard')->getContent());

        $handover->approve();

        $this->assertSame('UGX 0', $pendingOnBoard($agent));
    }

    public function test_a_super_agent_does_not_hand_cash_to_another_super_agent(): void
    {
        $lead = $this->person('super-agent', 'Amina Lead', 'UG', '500000');
        $other = $this->person('super-agent', 'Peter Lead');

        $this->actingAs($lead)->post(route('cash-handovers.store'), [
            'super_agent_id' => $other->id,
            'currency' => 'UGX',
            'amount' => '100000',
        ])->assertSessionHasErrors('super_agent_id');

        $this->assertSame(0, CashHandover::count());
    }

    public function test_cash_cannot_be_asked_into_a_wallet_the_super_agent_does_not_hold(): void
    {
        $agent = $this->person('agent', 'Sarah Nalin', 'UG', '500000');
        $chinaLead = $this->person('super-agent', 'Wei Chen', 'CN');

        $this->actingAs($agent)->post(route('cash-handovers.store'), [
            'super_agent_id' => $chinaLead->id,
            'currency' => 'UGX',
            'amount' => '100000',
        ])->assertSessionHasErrors('super_agent_id');

        $this->assertSame(0, CashHandover::count());
    }

    public function test_the_super_agent_reads_what_was_received_and_who_brought_it(): void
    {
        $agent = $this->person('agent', 'Sarah Nalin', 'UG', '500000');
        $lead = $this->person('super-agent', 'Amina Lead');
        $handover = $this->request($agent, $lead);
        $handover->approve();

        $page = $this->actingAs($lead)->get('/wallet')->assertOk()->getContent();

        $this->assertStringContainsString('UGX Wallet', $page);
        $this->assertStringContainsString('UGX 480,000', $page);
        $this->assertStringContainsString('Cash hand-in requests', $page);
        $this->assertStringContainsString('Sarah Nalin', $page);
        $this->assertStringContainsString('Approved', $page);
    }

    public function test_references_are_numbered_within_their_day(): void
    {
        $agent = $this->person('agent', 'Sarah Nalin', 'UG', '500000');
        $lead = $this->person('super-agent', 'Amina Lead');

        $first = $this->request($agent, $lead, '100000');
        $second = $this->request($agent, $lead, '200000');

        $day = now()->format('Ymd');
        $this->assertSame('CH-'.$day.'-0001', $first->reference);
        $this->assertSame('CH-'.$day.'-0002', $second->reference);
    }
}
