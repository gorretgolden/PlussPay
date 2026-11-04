<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\AccountEntry;
use App\Models\ActivityLog;
use App\Models\ExchangeRate;
use App\Models\Transaction;
use App\Models\User;
use App\Services\AccountLedger;
use Database\Seeders\PermissionsSeeder;
use Database\Seeders\RoleAndUserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;

/**
 * The corridor's second half, which used to be assumed rather than recorded: the dollars a transfer
 * is worth are sent from Uganda to a desk in China, and that desk hands the receiver their money.
 * What it hands over is the currency the booking promised, yuan out of the desk's yuan wallet or the
 * very dollars that crossed, and each step moves real ledger money and stamps the transfer, so the
 * money the boards count and the cash the two desks hold stay one story.
 */
class ChinaSettlementTest extends TestCase
{
    use RefreshDatabase;

    private User $office;
    private User $agent;
    private User $lead;
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
     * A booking already collected and priced: one thousand dollars travelling, and at the far end
     * either six thousand seven hundred and fifty yuan or those dollars handed over as they are.
     */
    private function booked(string $receipt, User $creator, float $usd = 1000.0, string $payout = 'RMB'): Transaction
    {
        return Transaction::withoutEvents(fn () => Transaction::create([
            'receipt_number' => $receipt,
            'sender_name' => 'Sender '.$receipt,
            'sender_contact' => '',
            'receiver_name' => 'Receiver '.$receipt,
            'receiver_contact' => '',
            'destination' => 'CN',
            'payout_currency' => $payout,
            'currency' => 'USD',
            'amount' => $usd,
            'exchange_rate' => $payout === 'RMB' ? 6.75 : 0,
            'rmb_amount' => $payout === 'RMB' ? $usd * 6.75 : 0,
            'total_usd_equivalent' => $usd,
            'charge_rate' => 0,
            'charge_amount' => 0,
            'charge_ugx' => 0,
            'status' => 'completed',
            'fx_profit_ugx' => 0,
            'fx_profit_usd' => 0,
            'usd_rmb_profit_ugx' => 0,
            'usd_rmb_profit_cny' => 0,
            'rate_buy_ugx' => 3750,
            'created_by' => $creator->id,
        ]));
    }

    private function dollars(float $amount): Account
    {
        $account = Account::byCode(Account::USD_UGANDA);

        AccountLedger::record($account, 'opening_balance', number_format($amount, 2, '.', ''), 'Float for the desk');

        return $account;
    }

    private function send(Transaction $transaction, Account $source, User $recipient): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($this->office)->post('/china/send', [
            'transaction_ids' => [$transaction->id],
            'recipient_id' => $recipient->id,
            'source_account_id' => $source->id,
        ]);
    }

    /**
     * What the transfer page pre-fills into the cash-book form for a payout, read back off the link
     * it offers, so the wallet named and the figure carried can be checked against the money the
     * receiver was actually promised. Null means the page offers nothing, which is what a transfer
     * whose payout is already in the books should do.
     *
     * @return array<string, string>|null
     */
    private function payoutOffer(User $viewer, Transaction $transaction): ?array
    {
        $html = $this->actingAs($viewer)->get('/transactions/'.$transaction->id)
            ->assertOk()
            ->getContent();

        if (! preg_match('/href="([^"]+)"\s+class="[^"]+">Record the (?:China|Uganda) payout/', $html, $matches)) {
            return null;
        }

        $query = [];
        parse_str((string) parse_url(html_entity_decode($matches[1]), PHP_URL_QUERY), $query);

        return $query;
    }

    public function test_a_fresh_booking_holds_its_yuan_in_uganda_and_waits_on_the_send_list(): void
    {
        $transaction = $this->booked('PS-UG-0001', $this->agent);

        $this->assertNull($transaction->settled_at);
        $this->assertNull($transaction->delivered_at);
        $this->assertSame('collected', $transaction->stage()['key']);
        $this->assertSame(1, Transaction::awaitingSendToChina()->count());
        $this->assertSame(0, Transaction::awaitingPayoutInChina()->count());

        $this->actingAs($this->office)->get('/china')
            ->assertOk()
            ->assertSee('Cash taken, not yet sent')
            ->assertSee('PS-UG-0001');
    }

    public function test_sending_moves_the_dollars_into_the_desk_s_own_wallet_and_stamps_the_transfer(): void
    {
        $source = $this->dollars(2000);
        $transaction = $this->booked('PS-UG-0002', $this->agent);

        $this->send($transaction, $source, $this->china)
            ->assertRedirect(route('china.index'))
            ->assertSessionHasNoErrors();

        $sent = $transaction->fresh();

        $this->assertNotNull($sent->settled_at);
        $this->assertNull($sent->delivered_at);
        $this->assertSame($this->china->id, (int) $sent->settled_to);
        $this->assertSame('crossed', $sent->stage()['key']);

        $this->assertSame('1000.00', $source->fresh()->balance());
        $this->assertSame('1000.00', Account::walletFor($this->china, 'USD')->fresh()->balance());

        $legs = AccountEntry::where('transaction_id', $sent->id)->get();
        $this->assertSame(2, $legs->count());
        $this->assertSame('out', $legs->firstWhere('direction', 'out')->direction);
        $this->assertSame('USD', $legs->firstWhere('direction', 'in')->account->currency);
    }

    public function test_the_payout_leaves_the_yuan_wallet_of_the_desk_that_was_sent_the_dollars(): void
    {
        $transaction = $this->booked('PS-UG-0003', $this->agent);
        $this->send($transaction, $this->dollars(1000), $this->china);

        $yuanWallet = Account::walletFor($this->china, 'RMB');

        $this->actingAs($this->china)->post('/china/payout', [
            'transaction_ids' => [$transaction->fresh()->id],
        ])->assertRedirect(route('china.index'))
            ->assertSessionHasNoErrors();

        $paid = $transaction->fresh();

        $this->assertNotNull($paid->delivered_at);
        $this->assertSame('paid', $paid->stage()['key']);
        $this->assertSame('-6750.00', $yuanWallet->fresh()->balance());
        $this->assertSame('0.00', Account::byCode(Account::RMB_CHINA)->balance());
    }

    /**
     * The route she asked for: the receiver in China is handed dollars, so the desk there pays out of
     * the very dollars that crossed and no yuan wallet is touched at either step.
     */
    public function test_a_receiver_paid_in_dollars_is_paid_straight_out_of_the_dollars_that_crossed(): void
    {
        $transaction = $this->booked('PS-UG-0040', $this->agent, 1000.0, 'USD');
        $source = $this->dollars(1000);

        $this->send($transaction, $source, $this->china)->assertSessionHasNoErrors();

        $waiting = $transaction->fresh();
        $this->assertSame('$1,000.00', $waiting->payoutLabel());
        $this->assertSame('0.00', Account::walletFor($this->china, 'RMB')->fresh()->balance());

        $this->actingAs($this->china)->post('/china/payout', [
            'transaction_ids' => [$waiting->id],
        ])->assertRedirect(route('china.index'))
            ->assertSessionHas('success', fn (string $flash) => str_contains($flash, '$1,000.00 paid in China for PS-UG-0040'));

        $paid = $transaction->fresh();

        $this->assertNotNull($paid->delivered_at);
        $this->assertSame('paid', $paid->stage()['key']);
        $this->assertSame('0.00', Account::walletFor($this->china, 'USD')->fresh()->balance());
        $this->assertSame('0.00', Account::walletFor($this->china, 'RMB')->fresh()->balance());

        $leg = AccountEntry::where('transaction_id', $paid->id)->firstWhere('type', 'money_out');

        $this->assertNotNull($leg, 'The payout has to be money leaving a wallet.');
        $this->assertSame('USD', $leg->account->currency);
        $this->assertSame('1000.00', (string) $leg->amount);
        $this->assertStringContainsString('paid to Receiver PS-UG-0040 in China', $leg->description);
    }

    /**
     * One tick list can hold a receiver paid in yuan and a receiver paid in dollars. Those are two
     * piles of the book sitting in two wallets, so the batch is recorded, audited and reported as
     * two figures rather than a total in a currency nobody holds.
     */
    public function test_a_mixed_batch_is_paid_out_of_both_wallets_and_reported_as_two_figures(): void
    {
        $yuan = $this->booked('PS-UG-0041', $this->agent);
        $dollars = $this->booked('PS-UG-0042', $this->agent, 250.0, 'USD');

        $source = $this->dollars(1250);
        $this->send($yuan, $source, $this->china)->assertSessionHasNoErrors();
        $this->send($dollars, $source, $this->china)->assertSessionHasNoErrors();

        $this->actingAs($this->office)->post('/china/payout', [
            'transaction_ids' => [$yuan->fresh()->id, $dollars->fresh()->id],
        ])->assertSessionHas('success', fn (string $flash) => str_contains($flash, '¥6,750.00 and $250.00 paid in China'));

        $this->assertSame('-6750.00', Account::walletFor($this->china, 'RMB')->fresh()->balance());

        // The desk was sent 1,250 dollars and handed over 250 of them, so what is left in its dollar
        // wallet is the 1,000 it still has to turn into the yuan it owes on the other transfer.
        $this->assertSame('1000.00', Account::walletFor($this->china, 'USD')->fresh()->balance());

        $dollarLeg = AccountEntry::where('transaction_id', $dollars->fresh()->id)->firstWhere('type', 'money_out');
        $this->assertSame('USD', $dollarLeg->account->currency);
        $this->assertSame(Account::walletFor($this->china, 'USD')->id, $dollarLeg->account_id);

        $audit = ActivityLog::where('action', 'china_payout_recorded')->latest('id')->firstOrFail();
        $this->assertStringContainsString('¥6,750.00', $audit->description);
        $this->assertStringContainsString('$250.00', $audit->description);
    }

    public function test_the_desk_page_names_the_money_each_receiver_is_handed(): void
    {
        $yuan = $this->booked('PS-UG-0043', $this->agent);
        $dollars = $this->booked('PS-UG-0044', $this->agent, 250.0, 'USD');

        $source = $this->dollars(1250);
        $this->send($yuan, $source, $this->china)->assertSessionHasNoErrors();
        $this->send($dollars, $source, $this->china)->assertSessionHasNoErrors();

        $this->actingAs($this->office)->get('/china')
            ->assertOk()
            ->assertSee('Waiting to be paid')
            // Two wallets hold the money, so the waiting figure is two lines rather than one total
            // in a currency nobody has.
            ->assertSee('¥6,750.00')
            ->assertSee('$250.00')
            ->assertSee('Still to hand over')
            ->assertSee('China Desk');

        $this->actingAs($this->office)->get('/transactions/'.$dollars->fresh()->id)
            ->assertOk()
            ->assertSee('Receiver Is Handed')
            ->assertSee('$250.00')
            // A booking that was never turned through yuan has no yuan price to print, and a bare
            // zero there would read as yuan bought for nothing.
            ->assertDontSee('Exchange Rate:');

        $this->actingAs($this->office)->get('/transactions/'.$yuan->fresh()->id)
            ->assertOk()
            ->assertSee('Exchange Rate:')
            ->assertSee('¥6,750.0');
    }

    /**
     * The button that records a payout written by hand has to point at the wallet the money actually
     * leaves, or the office would book a dollar payment against the yuan book.
     */
    public function test_the_payout_button_offers_the_wallet_of_the_money_the_receiver_was_promised(): void
    {
        $dollars = $this->booked('PS-UG-0045', $this->agent, 250.0, 'USD');
        $yuan = $this->booked('PS-UG-0046', $this->agent);

        $source = $this->dollars(1250);
        $this->send($dollars, $source, $this->china)->assertSessionHasNoErrors();
        $this->send($yuan, $source, $this->china)->assertSessionHasNoErrors();

        $dollarOffer = $this->payoutOffer($this->office, $dollars->fresh());
        $this->assertNotNull($dollarOffer);
        $this->assertSame(Account::walletFor($this->china, 'USD')->id, (int) $dollarOffer['account_id']);
        $this->assertSame('250', $dollarOffer['amount']);
        $this->assertStringContainsString('$250.00', $dollarOffer['description']);
        $this->assertSame('money_out', $dollarOffer['kind']);

        $yuanOffer = $this->payoutOffer($this->office, $yuan->fresh());
        $this->assertNotNull($yuanOffer);
        $this->assertSame(Account::walletFor($this->china, 'RMB')->id, (int) $yuanOffer['account_id']);
        $this->assertSame('6750', $yuanOffer['amount']);

        // Once the desk has paid, the money is in the books and the offer goes away.
        $this->actingAs($this->china)->post('/china/payout', [
            'transaction_ids' => [$dollars->fresh()->id],
        ])->assertSessionHasNoErrors();

        $this->assertNull($this->payoutOffer($this->office, $dollars->fresh()));
    }

    public function test_a_transfer_that_promises_yuan_but_holds_none_is_never_paid_out_for_nothing(): void
    {
        $transaction = $this->booked('PS-UG-0047', $this->agent);
        $this->send($transaction, $this->dollars(1000), $this->china)->assertSessionHasNoErrors();

        // A row whose yuan figure was never filled in: the desk must not be able to close it by
        // handing over nothing, so it stays off the list instead of showing as a free payout.
        $transaction->forceFill(['rmb_amount' => 0])->save();

        $this->assertSame(0, Transaction::awaitingPayoutInChina()->count());
        $this->assertNull($transaction->fresh()->stage());

        $this->actingAs($this->china)->post('/china/payout', [
            'transaction_ids' => [$transaction->id],
        ])->assertSessionHasErrors('transaction_ids');

        $this->assertNull($transaction->fresh()->delivered_at);
        $this->assertSame('0.00', Account::walletFor($this->china, 'RMB')->fresh()->balance());
    }

    public function test_a_batch_that_costs_more_dollars_than_are_in_hand_is_refused_and_nothing_moves(): void
    {
        $source = $this->dollars(500);
        $first = $this->booked('PS-UG-0004', $this->agent);
        $second = $this->booked('PS-UG-0005', $this->agent);

        $this->actingAs($this->office)->post('/china/send', [
            'transaction_ids' => [$first->id, $second->id],
            'recipient_id' => $this->china->id,
            'source_account_id' => $source->id,
        ])->assertSessionHasErrors('source_account_id');

        $this->assertStringContainsString('Buy the dollars', $this->flashedError('source_account_id'));
        $this->assertSame('500.00', $source->fresh()->balance());
        $this->assertNull($first->fresh()->settled_at);
        $this->assertNull($second->fresh()->settled_at);
    }

    public function test_a_transfer_someone_else_has_already_sent_is_named_instead_of_being_dropped_quietly(): void
    {
        $source = $this->dollars(5000);
        $first = $this->booked('PS-UG-0006', $this->agent);
        $second = $this->booked('PS-UG-0007', $this->agent);

        $this->send($second, $source, $this->china)->assertSessionHasNoErrors();

        $this->actingAs($this->office)->post('/china/send', [
            'transaction_ids' => [$first->id, $second->id],
            'recipient_id' => $this->china->id,
            'source_account_id' => $source->id,
        ])->assertSessionHasErrors('transaction_ids');

        $this->assertStringContainsString('PS-UG-0007', $this->flashedError('transaction_ids'));
        $this->assertNull($first->fresh()->settled_at);
    }

    public function test_a_desk_can_only_be_sent_dollars_that_are_a_desk_in_china(): void
    {
        $this->actingAs($this->office)->post('/china/send', [
            'transaction_ids' => [$this->booked('PS-UG-0008', $this->agent)->id],
            'recipient_id' => $this->agent->id,
            'source_account_id' => $this->dollars(1000)->id,
        ])->assertSessionHasErrors('recipient_id');
    }

    /**
     * The dropdown offers a China desk because it can pay receivers, so the same list guards the
     * click: dollars sent to a China user with no payout pen would sit in a queue nobody there can
     * clear, and the sender would be told the money had arrived.
     */
    public function test_a_china_user_who_cannot_pay_receivers_never_receives_the_dollars(): void
    {
        $staff = $this->person('China Staff', 'staff', 'CN');
        $source = $this->dollars(1000);
        $transaction = $this->booked('PS-UG-0062', $this->agent);

        $this->actingAs($this->office)->get('/china')
            ->assertOk()
            ->assertDontSee('China Staff');

        $this->send($transaction, $source, $staff)->assertSessionHasErrors('recipient_id');

        $this->assertStringContainsString('cannot pay receivers', $this->flashedError('recipient_id'));
        $this->assertSame('1000.00', $source->fresh()->balance());
        $this->assertSame('0.00', Account::walletFor($staff, 'USD')->fresh()->balance());
        $this->assertNull($transaction->fresh()->settled_at);
    }

    public function test_a_uganda_agent_clears_the_payout_queue_but_never_sends_the_dollars(): void
    {
        $transaction = $this->booked('PS-UG-0009', $this->agent);
        $this->send($transaction, $this->dollars(1000), $this->china);

        // One person often runs both ends of the corridor, so the payout pen clears the waiting
        // list wherever its desk stands. Sending the dollars is the Uganda office's job and the
        // road stays closed.
        $this->actingAs($this->agent)->get('/china')
            ->assertOk()
            ->assertDontSee('Cash taken, not yet sent')
            ->assertSee('Waiting to be paid')
            ->assertSee('PS-UG-0009');

        $this->actingAs($this->agent)->post('/china/send', [
            'transaction_ids' => [$transaction->fresh()->id],
            'recipient_id' => $this->china->id,
            'source_account_id' => Account::walletFor($this->agent, 'USD')->id,
        ])->assertForbidden();

        $this->actingAs($this->agent)->post('/china/payout', [
            'transaction_ids' => [$transaction->fresh()->id],
        ])->assertRedirect(route('china.index'))->assertSessionHasNoErrors();

        $this->assertNotNull($transaction->fresh()->delivered_at);
    }

    /**
     * The lead agent sends the dollars for every Uganda booking, so the withdrawal queue on the
     * other side is his to clear too. This is the queue the dashboard shortcut opens: money that
     * came from Uganda, reached a desk in China, and is still owed to its receiver.
     */
    public function test_the_lead_agent_clears_the_withdrawal_queue_that_came_from_uganda(): void
    {
        $transaction = $this->booked('PS-UG-0060', $this->agent);
        $this->send($transaction, $this->dollars(1000), $this->china);

        $this->actingAs($this->lead)->get('/china')
            ->assertOk()
            ->assertSee('Pay receivers in China')
            ->assertSee('PS-UG-0060');

        $this->actingAs($this->lead)->post('/china/payout', [
            'transaction_ids' => [$transaction->fresh()->id],
        ])->assertRedirect(route('china.index'))->assertSessionHasNoErrors();

        $paid = $transaction->fresh();

        $this->assertNotNull($paid->delivered_at);
        $this->assertSame('paid', $paid->stage()['key']);
    }

    /**
     * The withdrawal is the China desk's own work: the money that came from Uganda is handed to
     * its receiver there. The shortcut counts that queue, the desk can clear it, and the Uganda-only
     * hand-in is not offered to a desk that has nothing to hand up.
     */
    public function test_the_china_desk_is_the_hand_that_withdraws_money_sent_from_uganda(): void
    {
        $transaction = $this->booked('PS-UG-0061', $this->agent);
        $this->send($transaction, $this->dollars(1000), $this->china);

        $this->actingAs($this->china)->get('/dashboard')
            ->assertOk()
            ->assertSee('Pay out in China')
            ->assertSee('1 receiver waiting to be paid')
            ->assertDontSee('Hand Cash In');

        $this->actingAs($this->china)->get('/wallet')
            ->assertOk()
            ->assertDontSee('Hand cash in to your super agent');

        // The road is closed as well as hidden, so a hand-in from the China desk is refused at the
        // counter even when it ticks transfers.
        $this->actingAs($this->china)->post(route('cash-handovers.from-transactions'), [
            'super_agent_id' => $this->lead->id,
            'transaction_ids' => [$transaction->fresh()->id],
        ])->assertSessionHasErrors('super_agent_id');

        // The lead agent keeps the same shortcut and count, as the hand that may clear any desk.
        $this->actingAs($this->lead)->get('/dashboard')
            ->assertOk()
            ->assertSee('Pay out in China')
            ->assertSee('1 receiver waiting to be paid');

        $this->actingAs($this->china)->post('/china/payout', [
            'transaction_ids' => [$transaction->fresh()->id],
        ])->assertRedirect(route('china.index'))->assertSessionHasNoErrors();

        $this->assertNotNull($transaction->fresh()->delivered_at);
    }

    public function test_the_wallets_of_every_china_desk_stay_with_the_side_that_keeps_the_books(): void
    {
        $transaction = $this->booked('PS-UG-0011', $this->agent);
        $this->send($transaction, $this->dollars(1000), $this->china);

        // The desk sending dollars needs each name and what that desk is holding, because that is
        // the choice it is making. The emails, roles and yuan floats beside them are the Accounts
        // screen's business, and an agent is 403 there.
        $this->actingAs($this->agent)->get('/china')
            ->assertOk()
            ->assertSee('China Desk')
            ->assertDontSee('The desks in China')
            ->assertDontSee('china.desk@plussave.test');

        $this->actingAs($this->office)->get('/china')
            ->assertOk()
            ->assertSee('The desks in China')
            ->assertSee('china.desk@plussave.test');
    }

    public function test_a_tick_from_another_desk_s_book_is_refused_without_its_receipt_number(): void
    {
        $other = $this->person('Mbarara Agent', 'agent', 'UG');

        // Only the lead agent sends, and only across the book they were given. With the whole-book
        // reach taken away, this lead is a desk like any other and cannot forward a booking that is
        // not on their list, however the id got into the form.
        \Spatie\Permission\Models\Role::findByName('super-agent', 'web')->revokePermissionTo('view-all-transactions');

        $mine = $this->booked('PS-UG-0012', $this->lead);
        $theirs = $this->booked('PS-UG-0013', $other);

        // A desk sends out of its own dollar wallet, which is all the send form offers it.
        $wallet = Account::walletFor($this->lead, 'USD');
        AccountLedger::record($wallet, 'opening_balance', '2000.00', 'Dollars collected at the counter');

        $this->actingAs($this->lead)->post('/china/send', [
            'transaction_ids' => [$mine->id, $theirs->id],
            'recipient_id' => $this->china->id,
            'source_account_id' => $wallet->id,
        ])->assertSessionHasErrors('transaction_ids');

        $error = $this->flashedError('transaction_ids');

        $this->assertStringNotContainsString('PS-UG-0013', $error, 'A typed id list cannot be read back for numbers.');
        $this->assertStringContainsString('1 of the ticks are not transfers this desk lists', $error);
        $this->assertNull($mine->fresh()->settled_at, 'The whole batch stays where it was.');
    }

    public function test_a_desk_in_china_is_never_offered_dollars_that_are_not_theirs_to_send(): void
    {
        $this->actingAs($this->china)->get('/china')
            ->assertOk()
            ->assertDontSee('Cash taken, not yet sent');

        $this->actingAs($this->china)->post('/china/send', [
            'transaction_ids' => [$this->booked('PS-UG-0010', $this->agent)->id],
            'recipient_id' => $this->china->id,
            'source_account_id' => Account::byCode(Account::USD_UGANDA)->id,
        ])->assertForbidden();
    }

    public function test_cancelling_is_refused_once_the_dollars_have_left_uganda(): void
    {
        $transaction = $this->booked('PS-UG-0020', $this->agent);
        $this->send($transaction, $this->dollars(2000), $this->china);

        $this->actingAs($this->office)->patch('/transactions/'.$transaction->id.'/status', [
            'status' => 'cancelled',
            'reason' => 'Customer asked for the money back',
        ])->assertSessionHasErrors('status');

        $this->assertStringContainsString('cannot be cancelled', $this->flashedError('status'));
        $this->assertSame('completed', $transaction->fresh()->status);

        // The same click on a transfer whose dollars are still in this till goes through.
        $held = $this->booked('PS-UG-0021', $this->agent);

        $this->actingAs($this->office)->patch('/transactions/'.$held->id.'/status', [
            'status' => 'cancelled',
            'reason' => 'Customer asked for the money back',
        ])->assertSessionHasNoErrors();

        $this->assertSame('cancelled', $held->fresh()->status);
    }

    /**
     * The book used to call a booking "Completed" the moment the counter took the cash, while the
     * receiver in China was still waiting. The lists follow the money instead: Cash taken, then
     * Waiting in China, then Paid, and the paid transfer says so on its own page.
     */
    public function test_the_lists_read_cash_taken_then_waiting_in_china_then_paid(): void
    {
        $transaction = $this->booked('PS-UG-0070', $this->agent);

        $this->actingAs($this->office)->get('/transactions')
            ->assertOk()
            ->assertSee('>Cash taken</span>', false)
            ->assertDontSee('>Waiting in China</span>', false)
            ->assertDontSee('>Paid</span>', false)
            ->assertDontSee('>Completed</span>', false);

        $this->send($transaction, $this->dollars(1000), $this->china)->assertSessionHasNoErrors();

        $this->actingAs($this->office)->get('/transactions')
            ->assertOk()
            ->assertSee('>Waiting in China</span>', false)
            ->assertDontSee('>Cash taken</span>', false);

        $this->actingAs($this->china)->post('/china/payout', [
            'transaction_ids' => [$transaction->fresh()->id],
        ])->assertSessionHasNoErrors();

        $this->actingAs($this->office)->get('/transactions')
            ->assertOk()
            ->assertSee('>Paid</span>', false)
            ->assertDontSee('>Waiting in China</span>', false);

        $this->actingAs($this->office)->get('/transactions/'.$transaction->fresh()->id)
            ->assertOk()
            ->assertSee('Paid in China')
            ->assertSee('was handed to Receiver PS-UG-0070')
            ->assertSee('This transfer is finished on both sides');
    }

    /**
     * The progress filter cuts the list on the same three stamps the badges are read from, so a
     * desk can ask for the money still in its hand, the money waiting in China, or the money that
     * has finished, and the desk totals beside the list narrow with it.
     */
    public function test_the_progress_filter_cuts_the_list_to_the_stage_chosen(): void
    {
        $this->booked('PS-UG-0071', $this->agent);
        $waiting = $this->booked('PS-UG-0072', $this->agent);
        $paid = $this->booked('PS-UG-0073', $this->agent);

        $source = $this->dollars(2000);
        $this->send($waiting, $source, $this->china)->assertSessionHasNoErrors();
        $this->send($paid, $source, $this->china)->assertSessionHasNoErrors();
        AccountLedger::payOutReceiver($paid->fresh(), Account::walletFor($this->china, 'RMB'));

        $this->actingAs($this->office)->get('/transactions?stage=cash')
            ->assertOk()
            ->assertSee('PS-UG-0071')
            ->assertDontSee('PS-UG-0072')
            ->assertDontSee('PS-UG-0073');

        $this->actingAs($this->office)->get('/transactions?stage=waiting')
            ->assertOk()
            ->assertSee('PS-UG-0072')
            ->assertDontSee('PS-UG-0071')
            ->assertDontSee('PS-UG-0073');

        $this->actingAs($this->office)->get('/transactions?stage=paid')
            ->assertOk()
            ->assertSee('PS-UG-0073')
            ->assertDontSee('PS-UG-0071')
            ->assertDontSee('PS-UG-0072');
    }

    /**
     * The list no longer offers a cancellation the server would refuse: once the dollars have
     * crossed, the row menu drops the action and the transfer page says why it is gone.
     */
    public function test_the_lists_stop_offering_a_cancel_once_the_money_has_left(): void
    {
        $this->booked('PS-UG-0074', $this->agent);
        $sent = $this->booked('PS-UG-0075', $this->agent);

        $this->send($sent, $this->dollars(2000), $this->china)->assertSessionHasNoErrors();

        // Only the transfer still in the till offers it, so the action is named once, not twice.
        $list = $this->actingAs($this->office)->get('/transactions')->assertOk()->getContent();
        $this->assertSame(1, substr_count($list, 'Cancel transfer'));

        $this->actingAs($this->office)->get('/transactions/'.$sent->fresh()->id)
            ->assertOk()
            ->assertSee('cannot be cancelled from here')
            ->assertDontSee('Cancel transfer');
    }

    public function test_a_hand_written_yuan_line_in_the_cash_books_stamps_the_transfer_as_paid(): void
    {
        $transaction = $this->booked('PS-UG-0013', $this->agent);
        $yuan = Account::byCode(Account::RMB_CHINA);

        $this->actingAs($this->office)->post('/accounts/entries', [
            'kind' => 'money_out',
            'account_id' => $yuan->id,
            'amount' => '6750',
            'reference' => $transaction->receipt_number,
            'description' => 'Paid the receiver in China',
        ])->assertSessionHasNoErrors();

        $this->assertNotNull($transaction->fresh()->delivered_at);
        $this->assertSame('paid', $transaction->fresh()->stage()['key']);
    }

    public function test_a_shilling_line_in_the_cash_books_does_not_claim_a_payout_in_china(): void
    {
        $transaction = $this->booked('PS-UG-0014', $this->agent);
        $dollars = $this->dollars(1000);

        $this->actingAs($this->office)->post('/accounts/entries', [
            'kind' => 'money_out',
            'account_id' => $dollars->id,
            'amount' => '100',
            'reference' => $transaction->receipt_number,
            'description' => 'Dollars taken out of the till',
        ])->assertSessionHasNoErrors();

        $this->assertNull($transaction->fresh()->delivered_at);
    }

    public function test_the_dashboard_splits_the_yuan_by_whose_hands_it_is_in(): void
    {
        $held = $this->booked('PS-UG-0015', $this->agent);
        $waiting = $this->booked('PS-UG-0016', $this->agent);
        $paid = $this->booked('PS-UG-0017', $this->agent);

        $source = $this->dollars(3000);
        $this->send($waiting, $source, $this->china);
        $this->send($paid, $source, $this->china);

        AccountLedger::payOutReceiver($paid->fresh(), Account::walletFor($this->china, 'RMB'));

        // Two figures per direction rather than the four yuan cards the board used to carry: what
        // the desk there still owes and what it has handed over. The pile whose dollars have not
        // crossed yet is named in the note under the owed figure, because it is the same debt.
        $this->actingAs($this->office)->get('/dashboard')
            ->assertOk()
            ->assertSee('To pay in China')
            ->assertSee('¥13,500.00')
            ->assertSee('1 pile not sent yet')
            ->assertSee('Paid in China')
            ->assertSee('¥6,750.00')
            ->assertDontSee('Yuan To Receive');

        unset($held, $waiting, $paid);
    }

    public function test_the_reports_hub_reports_the_yuan_still_in_uganda_as_pending(): void
    {
        $transaction = $this->booked('PS-UG-0018', $this->agent);
        $this->send($transaction, $this->dollars(1000), $this->china);

        $this->actingAs($this->office)->get('/reports')
            ->assertOk()
            ->assertSee('RMB Still In Uganda')
            ->assertSee('RMB In China, Unpaid')
            ->assertSee('Dollars arrived, receiver not paid');
    }

    public function test_the_exchange_desk_offers_the_lead_agent_their_own_wallets_to_convert(): void
    {
        $wallets = Account::walletsFor($this->lead);

        $this->actingAs($this->lead)->get('/exchanges/create')
            ->assertOk()
            ->assertSee('Lead Agent (UGX)')
            ->assertSee('Lead Agent (USD)');

        // The dollars a transfer is bought with come out of the shillings in hand, so the shilling
        // wallet is what the overdraft guard watches.
        $this->assertSame(['UGX', 'USD'], array_keys($wallets));

        // The ordinary agent no longer runs the exchange desk.
        $this->actingAs($this->agent)->get('/exchanges/create')->assertForbidden();
    }

    public function test_a_desk_in_china_is_seeded_with_a_dollar_and_a_yuan_wallet(): void
    {
        $this->seed(RoleAndUserSeeder::class);

        $desk = User::where('email', 'china.agent@plussave.com')->firstOrFail();

        $this->assertSame('CN', $desk->country);
        $this->assertSame(['RMB', 'USD'], array_keys(Account::walletsFor($desk)));
    }

    public function test_the_download_names_where_each_transfer_s_money_is_and_a_teller_s_columns_stay_aligned(): void
    {
        $this->dollars(2000);
        $held = $this->booked('PS-UG-0031', $this->agent);
        $paid = $this->booked('PS-UG-0032', $this->agent);

        $this->send($paid, Account::byCode(Account::USD_UGANDA), $this->china);
        $paid->forceFill(['delivered_at' => now()])->save();

        [$headers, $rows] = $this->transactionsSheet($this->office);

        $this->assertSame(['Sent To China', 'Held In China By', 'Paid In China'], array_slice($headers, -3));

        $this->assertSame(
            ['-', '-', '-'],
            array_slice($this->rowFor($rows, 'PS-UG-0031'), -3),
            'A transfer nobody has sent still shows an empty chain rather than a blank column.'
        );

        $settled = array_slice($this->rowFor($rows, 'PS-UG-0032'), -3);
        $this->assertSame('China Desk', $settled[1]);
        $this->assertNotSame('-', $settled[0]);
        $this->assertNotSame('-', $settled[2]);

        // A teller's copy drops the office's four prices and three profit figures, so the header
        // and the row have to lose the same seven columns or the sheet shifts left by half.
        [$tellerHeaders, $tellerRows] = $this->transactionsSheet($this->agent);
        $this->assertCount(count($tellerHeaders), $this->rowFor($tellerRows, 'PS-UG-0032'));
        $this->assertSame(array_slice($headers, -3), array_slice($tellerHeaders, -3));
    }

    /**
     * The transactions workbook as one person downloads it: the header line and every data line.
     *
     * @return array{0: array<int, string>, 1: array<int, array<int, string>>}
     */
    private function transactionsSheet(User $viewer): array
    {
        $response = $this->actingAs($viewer)->get('/export/transactions');
        $this->assertSame(200, $response->getStatusCode());

        $path = storage_path('app/settlement-export-check.xlsx');
        file_put_contents($path, $response->streamedContent());
        $sheet = IOFactory::load($path)->getActiveSheet();
        unlink($path);

        // The sheet always starts its table on row 5, and the header is the widest line.
        $lastColumn = Coordinate::columnIndexFromString($sheet->getHighestColumn());
        $read = function (int $row) use ($sheet, $lastColumn): array {
            $cells = [];
            for ($column = 1; $column <= $lastColumn; $column++) {
                $cells[] = (string) $sheet->getCell(Coordinate::stringFromColumnIndex($column).$row)->getValue();
            }

            return $cells;
        };

        $headers = $read(5);
        $rows = [];
        foreach (range(6, $sheet->getHighestRow()) as $row) {
            $line = $read($row);
            if (trim(implode('', $line)) !== '') {
                $rows[] = $line;
            }
        }

        return [$headers, $rows];
    }

    /**
     * The one data line whose first cell is the receipt asked for.
     *
     * @param  array<int, array<int, string>>  $rows
     * @return array<int, string>
     */
    private function rowFor(array $rows, string $receipt): array
    {
        foreach ($rows as $row) {
            if ($row[0] === $receipt) {
                return $row;
            }
        }

        $this->fail('No '.$receipt.' line in the download.');
    }
}
