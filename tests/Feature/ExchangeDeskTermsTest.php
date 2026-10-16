<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\AccountEntry;
use App\Models\CurrencyExchange;
use App\Models\ExchangeRate;
use App\Models\User;
use App\Services\AccountLedger;
use App\Services\ExchangeCalculator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * The counter terms of a currency exchange: the commission the desk keeps as its own line in the
 * cash books, how the money physically moved, a price agreed off the card that has to say why, and
 * the slip that prints only what the deal itself saved.
 *
 * The figures below are worked from a card of UGX 3,750 buying / 3,760 selling a dollar and
 * 6.75 yuan either way, so every expected amount can be checked by hand.
 */
class ExchangeDeskTermsTest extends TestCase
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

        $this->admin = $this->makeUser('admin');
    }

    private function makeUser(string $role): User
    {
        $user = User::create([
            'name' => ucfirst($role).' Terms',
            'email' => strtolower($role).'terms'.uniqid().'@plussave.test',
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

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function book(array $overrides = []): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($this->admin)->post('/exchanges', array_merge([
            'exchange_date' => now()->toDateString(),
            'person_name' => 'Wei Chen',
            'in_currency' => 'UGX',
            'in_amount' => '1000000',
            'in_account_id' => Account::byCode(Account::UGX_UGANDA)->id,
            'out_currency' => 'USD',
            'out_amount' => '260.64',
            'out_account_id' => Account::byCode(Account::USD_UGANDA)->id,
        ], $overrides));
    }

    public function test_the_commission_is_kept_as_its_own_line_in_the_cash_book(): void
    {
        $dollars = $this->fund(Account::USD_UGANDA, '1000.00');
        $shillings = $this->fund(Account::UGX_UGANDA, '5000000.00');

        // UGX 1,000,000 handed over with 20,000 kept: the 980,000 that is left buys $260.64.
        $this->book(['fee' => '20000', 'payment_method' => 'Mobile money'])->assertSessionHasNoErrors();

        $exchange = CurrencyExchange::latest('id')->firstOrFail();

        $this->assertSame('20000.00', (string) $exchange->fee);
        $this->assertSame('Mobile money', $exchange->payment_method);
        $this->assertSame('980000.00', $exchange->pricedAmount());

        // The till gains every shilling it was handed, split into the part that bought dollars and
        // the part that was kept.
        $this->assertSame('6000000.00', $shillings->fresh()->balance());
        $this->assertSame('739.36', $dollars->fresh()->balance());

        $legs = $exchange->legs();
        $this->assertCount(3, $legs);
        $this->assertSame('980000.00', (string) $legs->firstWhere('type', 'money_in')->amount);
        $this->assertSame('20000.00', (string) $legs->firstWhere('type', 'exchange_fee')->amount);
        $this->assertSame('260.64', (string) $legs->firstWhere('type', 'money_out')->amount);
        $this->assertSame('Commission kept on a deal', AccountEntry::labelFor('exchange_fee'));

        // The price the customer got is the one the priced part of their money bought, not the
        // gross figure divided by what they received, which would read as a worse deal than it was.
        $this->assertSame('3759.975445', (string) $exchange->rate);
        $this->assertSame('3759.975445 UGX per 1 USD', $exchange->quotedRate());
        $this->assertSame('22600.00', (string) $exchange->margin_ugx);
    }

    public function test_a_deal_with_no_commission_keeps_the_two_book_lines_it_always_had(): void
    {
        $this->fund(Account::USD_UGANDA, '1000.00');
        $this->fund(Account::UGX_UGANDA, '5000000.00');

        // Nothing was kept, so the whole million is priced and buys the full card price of $265.96.
        $this->book(['out_amount' => '265.96'])->assertSessionHasNoErrors();

        $exchange = CurrencyExchange::latest('id')->firstOrFail();

        // Nothing was charged, so the books carry the two lines they always did.
        $this->assertNull($exchange->fee);
        $this->assertCount(2, $exchange->legs());
        $this->assertSame('1000000.00', $exchange->pricedAmount());
    }

    public function test_cancelling_a_deal_that_kept_a_commission_puts_both_tills_back_exactly(): void
    {
        $dollars = $this->fund(Account::USD_UGANDA, '1000.00');
        $shillings = $this->fund(Account::UGX_UGANDA, '5000000.00');

        $this->book(['fee' => '20000']);

        $exchange = CurrencyExchange::latest('id')->firstOrFail();
        $exchange->void('Price agreed by hand was wrong');

        $this->assertSame('5000000.00', $shillings->fresh()->balance());
        $this->assertSame('1000.00', $dollars->fresh()->balance());

        // The refund returns the whole amount the customer handed over, commission included, and
        // nothing is deleted: all five lines stay against the one reference.
        $this->assertCount(5, AccountEntry::where('reference', $exchange->reference)->get());
        $this->assertSame('1000000.00', (string) AccountEntry::where('reference', $exchange->reference)
            ->where('type', 'exchange_back_out')->value('amount'));
    }

    public function test_a_commission_that_eats_the_whole_amount_is_refused_before_anything_is_written(): void
    {
        $this->fund(Account::USD_UGANDA, '1000.00');
        $shillings = $this->fund(Account::UGX_UGANDA, '5000000.00');

        $this->book(['fee' => '1000000'])->assertSessionHasErrors('fee');
        $this->book(['fee' => '1500000'])->assertSessionHasErrors('fee');

        $this->assertStringContainsString('UGX 1,000,000', $this->flashedError('fee'));
        $this->assertSame(0, CurrencyExchange::count());
        $this->assertSame('5000000.00', $shillings->fresh()->balance());
    }

    public function test_a_price_off_the_card_needs_the_ability_to_give_it(): void
    {
        $this->fund(Account::USD_UGANDA, '1000.00');
        $this->fund(Account::UGX_UGANDA, '5000000.00');

        $clerk = $this->makeUser('staff');
        $clerk->givePermissionTo(['view-exchanges', 'create-exchange']);

        // The card gives $265.96 for the million shillings; $260.00 was agreed by hand.
        $this->actingAs($clerk)->post('/exchanges', [
            'exchange_date' => now()->toDateString(),
            'person_name' => 'Anita Kayo',
            'in_currency' => 'UGX',
            'in_amount' => '1000000',
            'in_account_id' => Account::byCode(Account::UGX_UGANDA)->id,
            'out_currency' => 'USD',
            'out_amount' => '260.00',
            'out_account_id' => Account::byCode(Account::USD_UGANDA)->id,
        ])->assertForbidden();

        $this->assertSame(0, CurrencyExchange::count());
    }

    public function test_a_hand_agreed_price_is_refused_without_a_reason_and_keeps_the_reason_it_gives(): void
    {
        $this->fund(Account::USD_UGANDA, '1000.00');
        $this->fund(Account::UGX_UGANDA, '5000000.00');

        $this->book(['out_amount' => '260.00'])->assertSessionHasErrors('rate_reason');
        $this->assertStringContainsString('$265.96', $this->flashedError('rate_reason'));
        $this->assertSame(0, CurrencyExchange::count());

        $this->book(['out_amount' => '260.00', 'rate_reason' => 'Regular customer, manager agreed'])
            ->assertSessionHasNoErrors();

        $exchange = CurrencyExchange::latest('id')->firstOrFail();
        $this->assertSame('Regular customer, manager agreed', $exchange->rate_reason);

        $this->actingAs($this->admin)->get('/exchanges/'.$exchange->id)
            ->assertOk()
            ->assertSee('Agreed by hand: Regular customer, manager agreed')
            ->assertSee('Paid how:')
            ->assertSee('Not recorded');
    }

    public function test_the_card_price_needs_no_reason_and_records_none(): void
    {
        $this->fund(Account::USD_UGANDA, '1000.00');
        $this->fund(Account::UGX_UGANDA, '5000000.00');

        $this->book(['fee' => '20000', 'out_amount' => '260.64'])->assertSessionHasNoErrors();

        $this->assertNull(CurrencyExchange::latest('id')->firstOrFail()->rate_reason);
    }

    public function test_the_price_is_stamped_on_the_deal_and_never_moves_afterwards(): void
    {
        $this->fund(Account::USD_UGANDA, '1000.00');
        $this->fund(Account::UGX_UGANDA, '5000000.00');

        $this->book(['fee' => '20000'])->assertSessionHasNoErrors();

        $exchange = CurrencyExchange::latest('id')->firstOrFail();

        // 980,000 priced at the selling rate: $260.64, which reads back as 3,759.975445 shillings
        // to the dollar. Moving the card afterwards must not restate a deal already done.
        $this->assertSame('3759.975445', (string) $exchange->rate);
        $this->assertSame('260.64', (string) $exchange->out_amount);

        ExchangeRate::setPair('USD', 'UGX', 3900, 3800, 3900);

        $exchange->refresh();
        $this->assertSame('3759.975445', (string) $exchange->rate);
        $this->assertSame('260.64', (string) $exchange->out_amount);
    }

    public function test_the_price_is_worked_out_at_full_precision_and_only_the_answer_is_rounded(): void
    {
        $card = ExchangeCalculator::card();

        // A million shillings buys 265.95744680 dollars, which at 6.75 yuan is 1,795.2127 yuan.
        $this->assertSame('1795.21', ExchangeCalculator::suggest($card, 'UGX', '1000000.00', 'RMB'));

        // Rounding the dollars on the way to the yuan would have handed over two centesimi more.
        $viaDollars = (float) ExchangeCalculator::suggest($card, 'UGX', '1000000.00', 'USD');
        $this->assertSame('1795.23', number_format($viaDollars * 6.75, 2, '.', ''));

        // Shillings are only ever written to the whole note, and that happens last.
        $this->assertSame('375037.50', ExchangeCalculator::suggest($card, 'USD', '100.01', 'UGX'));
        $this->assertSame('375038', ExchangeCalculator::settle('UGX', '375037.50'));
        $this->assertSame('375037.50', ExchangeCalculator::settle('USD', '375037.50'));
    }

    public function test_a_dollar_to_shilling_deal_moves_both_tills_and_a_cancellation_restores_them(): void
    {
        $dollars = $this->fund(Account::USD_UGANDA, '1000.00');
        $shillings = $this->fund(Account::UGX_UGANDA, '5000000.00');

        // $500 in with $5 kept: the 495 that is priced buys UGX 1,856,250 at 3,750.
        $this->actingAs($this->admin)->post('/exchanges', [
            'exchange_date' => now()->toDateString(),
            'person_name' => 'Li Qiang',
            'in_currency' => 'USD',
            'in_amount' => '500',
            'in_account_id' => $dollars->id,
            'fee' => '5',
            'out_currency' => 'UGX',
            'out_amount' => '1856250',
            'out_account_id' => $shillings->id,
            'payment_method' => 'Cash',
        ])->assertSessionHasNoErrors();

        $exchange = CurrencyExchange::latest('id')->firstOrFail();

        $this->assertSame('3750.000000', (string) $exchange->rate);
        $this->assertSame('3750 UGX per 1 USD', $exchange->quotedRate());
        $this->assertSame('1856250.00', (string) $exchange->out_amount);
        $this->assertSame('23750.00', (string) $exchange->margin_ugx);
        $this->assertSame('3143750.00', $shillings->fresh()->balance());
        $this->assertSame('1500.00', $dollars->fresh()->balance());

        $this->actingAs($this->admin)->get('/exchanges/'.$exchange->id)
            ->assertOk()
            ->assertSee('Paid how:')
            ->assertSee('Cash');

        $this->actingAs($this->admin)->post('/exchanges/'.$exchange->id.'/void', ['reason' => 'Counted the notes wrong'])
            ->assertSessionHasNoErrors();

        $this->assertSame('5000000.00', $shillings->fresh()->balance());
        $this->assertSame('1000.00', $dollars->fresh()->balance());
    }

    public function test_a_second_deal_cannot_pay_out_shillings_the_first_one_took(): void
    {
        $dollars = $this->fund(Account::USD_UGANDA, '2000.00');
        $shillings = $this->fund(Account::UGX_UGANDA, '2000000.00');

        // 200 dollars at the card's 3,750 takes 750,000 shillings out of the till each time.
        $terms = [
            'in_currency' => 'USD',
            'in_amount' => '200',
            'in_account_id' => $dollars->id,
            'out_currency' => 'UGX',
            'out_amount' => '750000',
            'out_account_id' => $shillings->id,
        ];

        $this->book($terms)->assertSessionHasNoErrors();
        $this->book($terms)->assertSessionHasNoErrors();
        $this->assertSame('500000.00', $shillings->fresh()->balance());

        // A third would take the till below zero, and the refusal names what is actually left.
        // Two bookings arriving at the same moment are kept off the same cash by the row lock the
        // guard takes inside the transaction, which is what makes this check mean anything.
        $this->book($terms)->assertSessionHasErrors('out_amount');
        $this->assertStringContainsString('UGX 500,000', $this->flashedError('out_amount'));

        $this->assertSame(2, CurrencyExchange::count());
        $this->assertSame('500000.00', $shillings->fresh()->balance());
    }

    public function test_the_slip_prints_the_prices_the_deal_saved_rather_than_the_card_of_today(): void
    {
        $this->fund(Account::USD_UGANDA, '1000.00');
        $this->fund(Account::UGX_UGANDA, '5000000.00');

        $this->book(['fee' => '20000', 'out_amount' => '260.00', 'rate_reason' => 'Rush job, agreed with the manager', 'notes' => 'Passport number A12345'])
            ->assertSessionHasNoErrors();

        $exchange = CurrencyExchange::latest('id')->firstOrFail();
        $this->assertSame('260.00', (string) $exchange->out_amount);

        $slip = $this->actingAs($this->admin)->get('/exchanges/'.$exchange->id.'/receipt');

        $slip->assertOk()
            ->assertSee('CURRENCY EXCHANGE')
            ->assertSee($exchange->reference)
            ->assertSee('Commission kept (UGX)')
            ->assertSee('- UGX 20,000')
            ->assertSee('UGX 980,000')
            ->assertSee('Price given')
            ->assertSee('3769.230769 UGX per 1 USD', false)
            ->assertSee('HANDED OVER TO YOU (USD)')
            ->assertSee('$260.00', false)
            ->assertSee('Rush job, agreed with the manager')
            ->assertSee('Passport number A12345')
            ->assertSee('Uganda');

        // Moving the card afterwards changes nothing on the slip.
        ExchangeRate::setPair('USD', 'UGX', 3900, 3800, 3900);

        $this->actingAs($this->admin)->get('/exchanges/'.$exchange->id.'/receipt')
            ->assertOk()
            ->assertSee('3769.230769 UGX per 1 USD', false);
    }

    public function test_a_cancelled_deal_says_so_on_the_slip(): void
    {
        $this->fund(Account::USD_UGANDA, '1000.00');
        $this->fund(Account::UGX_UGANDA, '5000000.00');

        $this->book(['fee' => '20000']);
        $exchange = CurrencyExchange::latest('id')->firstOrFail();
        $exchange->void('Customer changed their mind');

        $this->actingAs($this->admin)->get('/exchanges/'.$exchange->id.'/receipt')
            ->assertOk()
            ->assertSee('THIS DEAL WAS CANCELLED');
    }

    public function test_a_desk_agent_cannot_open_a_slip_for_a_deal_they_did_not_book(): void
    {
        $this->fund(Account::USD_UGANDA, '1000.00');
        $this->fund(Account::UGX_UGANDA, '5000000.00');

        $this->book(['fee' => '20000']);
        $exchange = CurrencyExchange::latest('id')->firstOrFail();

        $this->actingAs($this->makeUser('agent'))->get('/exchanges/'.$exchange->id.'/receipt')->assertNotFound();
        $this->actingAs($this->admin)->get('/exchanges/'.$exchange->id.'/receipt')->assertOk();
    }

    public function test_the_form_offers_the_commission_the_payment_method_and_the_live_price(): void
    {
        $this->fund(Account::USD_UGANDA, '1000.00');

        $this->actingAs($this->admin)->get('/exchanges/create')
            ->assertOk()
            ->assertSee('Commission', false)
            ->assertSee('Charged on the deal and kept out of this side', false)
            ->assertSee('Paid how?', false)
            ->assertSee('Mobile money')
            ->assertSee('Bank transfer')
            ->assertSee('Live Calculation')
            ->assertSee('Amount that is priced')
            ->assertSee('Why is this price different from the card? *', false)
            ->assertSee('Review the deal')
            ->assertSee('hero_amount');
    }

    public function test_a_clerk_without_the_override_ability_is_given_a_locked_price(): void
    {
        $clerk = $this->makeUser('staff');
        $clerk->givePermissionTo(['view-exchanges', 'create-exchange']);

        $this->actingAs($clerk)->get('/exchanges/create')
            ->assertOk()
            ->assertSee('Locked to the card price. An Admin can agree a different price with a customer.', false);
    }

    public function test_saving_the_prices_already_on_the_screen_leaves_the_pair_alone(): void
    {
        $before = ExchangeRate::where('from_currency', 'USD')->where('to_currency', 'UGX')->count();

        $this->actingAs($this->admin)->put('/settings', [
            'settings_tab' => 'rates',
            'usd_ugx_buying' => '3750',
            'usd_ugx_selling' => '3760',
        ])->assertSessionHasNoErrors();

        $this->assertSame($before, ExchangeRate::where('from_currency', 'USD')->where('to_currency', 'UGX')->count());
        $this->assertSame('3750', ExchangeCalculator::card()['usd_buy']);
        $this->assertSame('3760', ExchangeCalculator::card()['usd_sell']);
    }

    public function test_a_pair_saved_at_cost_is_accepted_on_both_legs(): void
    {
        // A desk that passes money across at the same price it paid earns nothing, but it is a real
        // thing to do, so only a reversed spread is refused. Here both pairs go flat.
        $this->actingAs($this->admin)->put('/settings', [
            'settings_tab' => 'rates',
            'usd_ugx_buying' => '3760',
            'usd_ugx_selling' => '3760',
            'usd_rmb_buying' => '6.80',
            'usd_rmb_selling' => '6.80',
        ])->assertSessionHasNoErrors();

        $ugx = ExchangeRate::getActiveRate('USD', 'UGX');
        $rmb = ExchangeRate::getActiveRate('USD', 'RMB');

        $this->assertEqualsWithDelta(3760, (float) $ugx->buy_rate, 0.000001);
        $this->assertEqualsWithDelta(3760, (float) $ugx->sell_rate, 0.000001);
        $this->assertEqualsWithDelta(6.80, (float) $rmb->buy_rate, 0.000001);
        $this->assertEqualsWithDelta(6.80, (float) $rmb->sell_rate, 0.000001);

        // The booking side converts off the selling figure, so a flat pair still prices a deal.
        $this->assertSame('3760', ExchangeCalculator::card()['usd_sell']);
    }

    public function test_every_saved_price_records_the_day_it_took_effect(): void
    {
        $older = ExchangeRate::setPair('USD', 'UGX', 3700, 3690, 3700);
        $live = ExchangeRate::setPair('USD', 'UGX', 3800, 3790, 3800);

        $this->assertSame(now()->toDateString(), $live->effective_from->toDateString());

        // The retired row is read as the price that was in force from the start of the month, even
        // though it was typed today.
        $older->update(['effective_from' => now()->subDays(15)->toDateString()]);

        $this->assertSame('3700.000000', (string) ExchangeRate::rateLiveAt('USD', 'UGX', now()->subDays(5))->rate);
        $this->assertSame('3800.000000', (string) ExchangeRate::rateLiveAt('USD', 'UGX', now())->rate);
        $this->assertSame('3800.000000', (string) ExchangeRate::getActiveRate('USD', 'UGX')->rate);
    }

    public function test_the_rates_history_shows_the_date_a_price_took_effect(): void
    {
        $this->actingAs($this->admin)->put('/settings', [
            'settings_tab' => 'rates',
            'usd_ugx_buying' => '3750',
            'usd_ugx_selling' => '3760',
        ])->assertSessionHasNoErrors();

        ExchangeRate::latest('id')->firstOrFail()->update(['effective_from' => now()->subDays(3)->toDateString()]);

        $this->actingAs($this->admin)->get('/exchange-rates')
            ->assertOk()
            ->assertSee('In force from');
    }
}
