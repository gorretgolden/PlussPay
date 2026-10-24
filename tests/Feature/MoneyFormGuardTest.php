<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\CashHandover;
use App\Models\User;
use App\Services\AccountLedger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A booking or a hand-in is a promise about cash, so the screen has to behave like one: the form
 * freezes once the request leaves, and the two moments that move money ask the person to read the
 * figures first.
 */
class MoneyFormGuardTest extends TestCase
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

    public function test_the_booking_form_freezes_while_it_is_being_sent(): void
    {
        $agent = $this->person('agent', 'Sarah Nalin', 'UG', '500000');

        $page = $this->actingAs($agent)->get('/transactions/create')->assertOk()->getContent();

        $this->assertStringContainsString('id="transactionForm"', $page);
        $this->assertMatchesRegularExpression(
            '/<form[^>]*id="transactionForm"[^>]*data-lock[^>]*data-lock-label="Booking\.\.\."/s',
            $page,
            'the booking form does not freeze while the booking is sent'
        );
    }

    public function test_a_hand_in_shows_the_agent_the_figures_before_it_is_sent(): void
    {
        $agent = $this->person('agent', 'Sarah Nalin', 'UG', '500000');
        $this->person('super-agent', 'Amina Lead');

        // The hand-in opens as a modal on the Transactions page, with the transfer's own figures
        // printed in it. The form freezes while the request is sent, and the figures are read from
        // the booking rather than typed, so they cannot disagree with the book.
        $page = $this->actingAs($agent)->get('/transactions')->assertOk()->getContent();

        $this->assertStringContainsString('id="handInModal"', $page);
        $this->assertStringContainsString('data-lock data-lock-label="Sending..."', $page);
        $this->assertStringContainsString('id="handInModalPrincipal"', $page);
        $this->assertStringContainsString('id="handInModalCharge"', $page);
        $this->assertStringContainsString('id="handInModalCollected"', $page);
        $this->assertStringContainsString('id="handInModalTransactionId"', $page);

        // The wallets and the first screen carry no hand-in box anymore, so neither can freeze one.
        foreach (['/wallet', '/dashboard'] as $url) {
            $page = $this->actingAs($agent)->get($url)->assertOk()->getContent();
            $this->assertStringNotContainsString('handInModal', $page, $url);
            $this->assertStringNotContainsString('Send this cash hand-in for approval', $page, $url);
        }
    }

    public function test_the_requests_page_confirms_before_the_cash_moves(): void
    {
        $agent = $this->person('agent', 'Sarah Nalin', 'UG', '500000');
        $lead = $this->person('super-agent', 'Amina Lead');
        $handover = CashHandover::request($agent, $lead, 'UGX', '480000', 'Carried in the evening');

        // The first screen only names what is waiting and points at the page; the deciding lives
        // on the hand-ins page, where every money move must ask before it happens.
        $board = $this->actingAs($lead)->get('/dashboard')->assertOk()->getContent();
        $this->assertStringContainsString('Open the requests', $board);
        $this->assertStringNotContainsString('data-confirm-ok="Yes, I have it"', $board, 'The dashboard card still carries a decision form.');

        $page = $this->actingAs($lead)->get('/cash-handovers')->assertOk()->getContent();

        $this->assertStringContainsString('data-confirm-title="Confirm you have received this cash"', $page);
        $this->assertStringContainsString('data-confirm-ok="Yes, I have it"', $page);
        $this->assertStringContainsString('data-lock-label="Approving..."', $page);
        $this->assertStringContainsString('data-confirm-title="Send this hand-in back?"', $page);
        $this->assertStringContainsString('data-confirm-ok="Send it back"', $page);
        $this->assertStringContainsString('data-confirm-danger="1"', $page);
        $this->assertStringContainsString('data-lock-label="Sending back..."', $page);

        // Both decisions name the request and carry the reason typed on the row into the card.
        $decoded = html_entity_decode($page);
        $this->assertStringContainsString($handover->reference, $decoded);
        $this->assertStringContainsString(json_encode(['Reason', 'input[name=decision_note]', 'value']), $decoded);
    }
}
