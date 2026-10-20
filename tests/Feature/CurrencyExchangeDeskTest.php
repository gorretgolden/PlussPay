<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\AccountEntry;
use App\Models\CurrencyExchange;
use App\Models\Customer;
use App\Models\ExchangeRate;
use App\Models\Setting;
use App\Models\Transaction;
use App\Models\User;
use App\Services\AccountLedger;
use App\Services\ExchangeCalculator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * The counter deals: money taken in one currency and handed back in another, priced off the same
 * card the transfers use, with both cash books moved by the one reference.
 */
class CurrencyExchangeDeskTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

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

        $this->admin = $this->makeUser('admin');
    }

    private function makeUser(string $role): User
    {
        $user = User::create([
            'name' => ucfirst($role).' Desk',
            'email' => strtolower($role).uniqid().'@plussave.test',
            'password' => 'Password123',
            'is_active' => true,
        ]);

        $user->assignRole($role);

        return $user;
    }

    private function fund(string $code, string $amount): Account
    {
        $account = Account::byCode($code);

        AccountLedger::record($account, 'opening_balance', $amount, 'Float for the desk');

        return $account;
    }

    private function deal(array $overrides = []): CurrencyExchange
    {
        return CurrencyExchange::record(array_merge([
            'exchange_date' => now()->toDateString(),
            'person_name' => 'Wei Chen',
            'in_currency' => 'UGX',
            'in_amount' => '1000000.00',
            'in_account_id' => Account::byCode(Account::UGX_UGANDA)->id,
            'out_currency' => 'USD',
            'out_amount' => '265.96',
            'out_account_id' => Account::byCode(Account::USD_UGANDA)->id,
            'rate' => '3759.963904',
            'margin_ugx' => '2650.00',
            'created_by' => $this->admin->id,
        ], $overrides));
    }

    public function test_the_card_prices_a_shilling_to_dollar_deal_by_dividing_at_the_selling_rate(): void
    {
        $card = ExchangeCalculator::card();

        $this->assertSame(['usd_buy' => '3750', 'usd_sell' => '3760', 'rmb_buy' => '6.75', 'rmb_sell' => '6.75'], $card);
        $this->assertSame('265.96', ExchangeCalculator::suggest($card, 'UGX', '1000000.00', 'USD'));

        // Shillings taken in are worth their face; the dollars handed over cost what it takes to
        // buy them back at 3,750, so the desk keeps the gap.
        $this->assertSame('2650.00', ExchangeCalculator::margin($card, 'UGX', '1000000.00', 'USD', '265.96'));
        $this->assertStringContainsString('sold to customers at UGX 3,760', ExchangeCalculator::rule($card, 'UGX', 'USD'));
    }

    public function test_the_card_prices_a_dollar_to_shilling_deal_at_the_buying_rate(): void
    {
        $card = ExchangeCalculator::card();

        $this->assertSame('375000.00', ExchangeCalculator::suggest($card, 'USD', '100.00', 'UGX'));

        // The dollars taken in are worth UGX 3,760 each to us, so paying out 375,000 shillings for
        // them keeps the 1,000 gap.
        $this->assertSame('1000.00', ExchangeCalculator::margin($card, 'USD', '100.00', 'UGX', '375000.00'));

        // Handing over more than the card justifies is a loss, and it is shown as one.
        $this->assertSame('-4000.00', ExchangeCalculator::margin($card, 'USD', '100.00', 'UGX', '380000.00'));
    }

    public function test_a_deal_moves_both_accounts_and_writes_both_legs_under_one_reference(): void
    {
        $dollars = $this->fund(Account::USD_UGANDA, '1000.00');
        $shillings = $this->fund(Account::UGX_UGANDA, '5000000.00');

        $this->actingAs($this->admin)->post('/exchanges', [
            'exchange_date' => now()->toDateString(),
            'person_name' => 'wei chen',
            'in_currency' => 'UGX',
            'in_amount' => '1000000',
            'in_account_id' => $shillings->id,
            'out_currency' => 'USD',
            'out_amount' => '265.96',
            'out_account_id' => $dollars->id,
        ])->assertSessionHasNoErrors()->assertRedirect();

        $exchange = CurrencyExchange::latest('id')->firstOrFail();

        $this->assertSame('Wei Chen', $exchange->person_name);
        $this->assertSame('2650.00', (string) $exchange->margin_ugx);
        $this->assertSame('3759.963904', (string) $exchange->rate);
        $this->assertSame('6000000.00', $shillings->balance());
        $this->assertSame('734.04', $dollars->balance());

        $legs = $exchange->legs();
        $this->assertCount(2, $legs);
        $this->assertTrue($legs->contains(fn (AccountEntry $e) => $e->account_id === $shillings->id && $e->direction === 'in'));
        $this->assertTrue($legs->contains(fn (AccountEntry $e) => $e->account_id === $dollars->id && $e->direction === 'out'));

        // Each leg names the other account, which is what lets any screen say which rose and which fell.
        $this->assertSame($dollars->id, $legs->firstWhere('account_id', $shillings->id)->counterparty_account_id);
    }

    public function test_the_deal_is_shown_as_so_many_shillings_to_the_dollar_rather_than_a_fraction(): void
    {
        $this->fund(Account::USD_UGANDA, '1000.00');
        $this->fund(Account::UGX_UGANDA, '5000000.00');

        $exchange = $this->deal();

        $this->assertSame('3759.963904 UGX per 1 USD', $exchange->quotedRate());
        $this->actingAs($this->admin)->get('/exchanges/'.$exchange->id)
            ->assertOk()
            ->assertSee('3759.963904 UGX per 1 USD')
            ->assertSee('Which accounts moved')
            ->assertSee('UGX Cash (Uganda)');
    }

    public function test_cancelling_a_deal_puts_both_accounts_back_and_keeps_every_line(): void
    {
        $dollars = $this->fund(Account::USD_UGANDA, '1000.00');
        $shillings = $this->fund(Account::UGX_UGANDA, '5000000.00');

        $exchange = $this->deal();

        $this->assertSame('6000000.00', $shillings->balance());
        $this->assertSame('734.04', $dollars->balance());

        $this->actingAs($this->admin)->post('/exchanges/'.$exchange->id.'/void', [
            'reason' => 'Gave the wrong rate',
        ])->assertSessionHasNoErrors();

        $this->assertSame('void', $exchange->fresh()->status);
        $this->assertSame('5000000.00', $shillings->balance());
        $this->assertSame('1000.00', $dollars->balance());

        // Nothing is deleted: the deal, the undo and both sides stay on the trail.
        $this->assertCount(4, AccountEntry::where('reference', $exchange->reference)->get());
        $this->assertDatabaseHas('account_entries', ['reference' => $exchange->reference, 'type' => 'exchange_back_out']);
        $this->assertDatabaseHas('account_entries', ['reference' => $exchange->reference, 'type' => 'exchange_back_in']);

        $this->actingAs($this->admin)->get('/exchanges/'.$exchange->id)->assertOk()->assertSee('Cancelled');
    }

    public function test_cancelling_needs_a_reason_and_cannot_be_done_twice(): void
    {
        $this->fund(Account::USD_UGANDA, '1000.00');
        $this->fund(Account::UGX_UGANDA, '5000000.00');

        $exchange = $this->deal();

        $this->actingAs($this->admin)->post('/exchanges/'.$exchange->id.'/void', [])
            ->assertSessionHasErrors('reason');

        $this->assertSame('active', $exchange->fresh()->status);

        $exchange->void('Wrong rate');

        $this->actingAs($this->admin)->post('/exchanges/'.$exchange->id.'/void', ['reason' => 'Again'])
            ->assertSessionHasErrors('reason');
    }

    public function test_the_two_sides_have_to_be_different_currencies_and_different_accounts(): void
    {
        $this->fund(Account::USD_UGANDA, '1000.00');
        $shillings = $this->fund(Account::UGX_UGANDA, '5000000.00');

        $this->actingAs($this->admin)->post('/exchanges', [
            'exchange_date' => now()->toDateString(),
            'person_name' => 'Same Wallet',
            'in_currency' => 'UGX',
            'in_amount' => '1000',
            'in_account_id' => $shillings->id,
            'out_currency' => 'UGX',
            'out_amount' => '900',
            'out_account_id' => $shillings->id,
        ])->assertSessionHasErrors('out_currency');

        // The account has to hold the currency it is said to have taken in.
        $this->actingAs($this->admin)->post('/exchanges', [
            'exchange_date' => now()->toDateString(),
            'person_name' => 'Wrong Book',
            'in_currency' => 'USD',
            'in_amount' => '100',
            'in_account_id' => $shillings->id,
            'out_currency' => 'RMB',
            'out_amount' => '675',
            'out_account_id' => Account::byCode(Account::RMB_CHINA)->id,
        ])->assertSessionHasErrors('in_account_id');

        $this->assertSame(0, CurrencyExchange::count());
    }

    public function test_a_deal_is_left_unpriced_when_no_rate_card_is_saved(): void
    {
        $this->fund(Account::USD_UGANDA, '1000.00');
        $this->fund(Account::UGX_UGANDA, '5000000.00');

        ExchangeRate::query()->delete();

        $this->assertNull(ExchangeCalculator::card());

        $exchange = $this->deal(['margin_ugx' => null]);

        $this->assertNull($exchange->margin_ugx);
        $this->assertCount(2, $exchange->legs());

        $this->actingAs($this->admin)->get('/exchanges/'.$exchange->id)
            ->assertOk()
            ->assertSee('No rate card');

        $this->actingAs($this->admin)->get('/exchanges')->assertOk()->assertSee('Unpriced deals');
    }

    public function test_the_book_lists_deals_and_adds_the_margin_only(): void
    {
        $this->fund(Account::USD_UGANDA, '1000.00');
        $this->fund(Account::UGX_UGANDA, '5000000.00');

        $exchange = $this->deal();

        $this->actingAs($this->admin)->get('/exchanges')
            ->assertOk()
            ->assertSee($exchange->reference)
            ->assertSee('Wei Chen')
            ->assertSee('2,650.00')
            ->assertSee('Margin on');
    }

    public function test_the_lead_agent_can_record_a_deal_but_not_cancel_one(): void
    {
        $lead = $this->makeUser('super-agent');

        $this->fund(Account::USD_UGANDA, '1000.00');
        $this->fund(Account::UGX_UGANDA, '5000000.00');

        $this->actingAs($lead)->get('/exchanges/create')->assertOk();

        $exchange = $this->deal();

        // Recording the counter deal is the lead agent's work; undoing one stays with the office.
        $this->actingAs($lead)->post('/exchanges/'.$exchange->id.'/void', ['reason' => 'Not mine to undo'])
            ->assertForbidden();

        $this->assertSame('active', $exchange->fresh()->status);

        $this->actingAs($this->makeUser('agent'))->get('/exchanges/create')->assertForbidden();
    }

    public function test_someone_outside_the_desks_cannot_open_the_book(): void
    {
        $this->actingAs($this->makeUser('staff'))->get('/exchanges')->assertForbidden();
    }

    public function test_the_customer_profile_shows_everything_changed_for_that_person(): void
    {
        $this->fund(Account::USD_UGANDA, '1000.00');
        $this->fund(Account::UGX_UGANDA, '5000000.00');

        $customer = Customer::create([
            'name' => 'Wei Chen',
            'phone' => '+256700000000',
            'type' => 'sender',
            'country' => 'Uganda',
            'is_active' => true,
            'created_by' => $this->admin->id,
        ]);

        $this->deal(['customer_id' => $customer->id]);
        $this->deal(['customer_id' => $customer->id, 'in_amount' => '500000.00', 'out_amount' => '132.98', 'margin_ugx' => '1325.00']);

        // The profile view is where she asked to see how much has been exchanged for one person.
        $this->actingAs($this->admin)->get('/customers/'.$customer->id)
            ->assertOk()
            ->assertSee('Times they changed money')
            ->assertSee('2')
            ->assertSee('3,975.00');
    }

    public function test_the_exchange_book_filters_to_one_customer_and_exports(): void
    {
        $this->fund(Account::USD_UGANDA, '1000.00');
        $this->fund(Account::UGX_UGANDA, '5000000.00');

        $customer = Customer::create([
            'name' => 'Anita Kayo',
            'phone' => '+256700111222',
            'type' => 'both',
            'country' => 'Uganda',
            'is_active' => true,
            'created_by' => $this->admin->id,
        ]);

        $hers = $this->deal(['customer_id' => $customer->id, 'person_name' => 'Anita Kayo']);
        $theirs = $this->deal(['person_name' => 'Someone Else']);

        $this->actingAs($this->admin)->get('/exchanges?customer_id='.$customer->id)
            ->assertOk()
            ->assertSee($hers->reference)
            ->assertDontSee($theirs->reference);

        $this->actingAs($this->admin)->get('/export/exchanges')->assertOk();
    }

    public function test_the_movements_page_names_both_accounts_of_a_deal(): void
    {
        $this->fund(Account::USD_UGANDA, '1000.00');
        $this->fund(Account::UGX_UGANDA, '5000000.00');

        $exchange = $this->deal();

        $this->actingAs($this->admin)->get('/accounts/movements')
            ->assertOk()
            ->assertSee('Money Movements')
            ->assertSee($exchange->reference)
            ->assertSee('Dollar Account (Uganda)');

        $this->actingAs($this->admin)->get('/accounts/movements?search='.$exchange->reference)
            ->assertOk()
            ->assertSee('UGX Cash (Uganda)');

        $this->actingAs($this->admin)->get('/export/movements?search='.$exchange->reference)->assertOk();
    }

    public function test_a_completed_transfer_shows_the_till_that_rose_and_prompts_for_the_payout_that_reduces(): void
    {
        $this->actingAs($this->admin)->get('/transactions/create');
        $this->actingAs($this->admin)->post('/transactions', [
            'form_id' => session('transaction_form_id'),
            'agent_id' => 'counter',
            'sender_name' => 'Okello John',
            'receiver_name' => 'Li Na',
            'currency' => 'UGX',
            'amount' => '3780000',
            'charge_ugx' => '20000',
            'payment_method' => 'Cash',
        ])->assertSessionHasNoErrors();

        $transaction = Transaction::latest('id')->firstOrFail();

        // Uganda side is in the books; nobody has recorded the yuan leaving China yet.
        $this->assertSame('3780000.00', Account::byCode(Account::UGX_UGANDA)->balance());
        $this->assertSame('0.00', Account::byCode(Account::RMB_CHINA)->balance());

        $this->actingAs($this->admin)->get('/transactions/'.$transaction->id)
            ->assertOk()
            ->assertSee('Which accounts moved')
            ->assertSee('Record the China payout');

        $rmb = (float) $transaction->rmb_amount;
        $this->assertGreaterThan(0, $rmb);

        $this->actingAs($this->admin)->post('/accounts/entries', [
            'kind' => 'money_out',
            'account_id' => Account::byCode(Account::RMB_CHINA)->id,
            'amount' => (string) $rmb,
            'reference' => $transaction->receipt_number,
            'description' => 'Paid '.$transaction->yuanLabel().' to '.$transaction->receiver_name.' for '.$transaction->receipt_number,
        ])->assertSessionHasNoErrors();

        $this->assertSame('-'.number_format($rmb, 2, '.', ''), Account::byCode(Account::RMB_CHINA)->balance());

        $this->actingAs($this->admin)->get('/transactions/'.$transaction->id)
            ->assertOk()
            ->assertDontSee('Record the China payout')
            ->assertSee('RMB Account (China)');
    }
}
