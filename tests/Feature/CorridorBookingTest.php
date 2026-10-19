<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\AccountEntry;
use App\Models\ExchangeRate;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * The three corridors booked the way a desk books them: through the form, into the books, and as
 * the ledger lines the money actually makes.
 *
 * The figures are the office sheet of 2026-10-19 (dollars 4,300 out and 4,000 in, yuan 6.56 and
 * 6.61), and each test says which hand the money went into and which account it is now sitting in.
 * The point of reading them together with ProfitCalculatorTest and CorridorPricingTest is that
 * pricing a row correctly is only half of it: a booking has to be refused when the route and the
 * money do not agree, and a booked row has to put the cash where it physically is.
 */
class CorridorBookingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['superadmin', 'admin', 'agent', 'accountant', 'staff'] as $role) {
            Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        }

        ExchangeRate::setPair('USD', 'UGX', 4300, 4000, 4300);
        ExchangeRate::setPair('USD', 'RMB', 6.56, 6.61, 6.56);
    }

    private function deskUser(string $role, string $name, string $country = 'UG'): User
    {
        $user = User::create([
            'name' => $name,
            'email' => strtolower(str_replace(' ', '', $name)).'@plussave.test',
            'password' => 'Password123',
            'is_active' => true,
            'country' => $country,
        ]);

        $user->assignRole($role);

        return $user;
    }

    /**
     * What the wizard posts, with the money left blank so each test states its own route.
     *
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function payload(array $extra = []): array
    {
        return array_merge([
            'form_id' => session('transaction_form_id'),
            'sender_name' => 'Asuma Aggrey',
            'receiver_name' => 'Zhang Wei',
            'purpose' => 'Family Support',
            'payment_method' => 'Cash',
        ], $extra);
    }

    private function book(User $booker, array $extra = []): Transaction
    {
        $this->actingAs($booker)->get('/transactions/create');

        $this->actingAs($booker)->post('/transactions', $this->payload($extra))
            ->assertSessionHasNoErrors();

        return Transaction::latest('id')->firstOrFail();
    }

    /**
     * Every ledger line a booking wrote, keyed by type and account code, so the shape of the money
     * is one assertion.
     *
     * @return array<string, string> entry type:account code => amount
     */
    private function lines(int $transactionId): array
    {
        return AccountEntry::where('transaction_id', $transactionId)
            ->with('account')
            ->get()
            ->mapWithKeys(fn (AccountEntry $entry) => [$entry->type.':'.$entry->account->code => (string) $entry->amount])
            ->all();
    }

    /**
     * A profit figure off the row, in the shillings and cents the desk counts in. The columns store
     * six decimals because the yuan leg is cut from a rate, so reading them straight would print
     * figures nobody writes down.
     */
    private function profit(Transaction $transaction, string $column): string
    {
        return number_format((float) $transaction->{$column}, 2, '.', '');
    }

    public function test_a_receiver_in_china_handed_dollars_is_booked_in_dollars_and_never_in_yuan(): void
    {
        $office = $this->deskUser('admin', 'Grace Naigaga');

        $transfer = $this->book($office, [
            'destination' => 'CN',
            'payout_currency' => 'USD',
            'currency' => 'UGX',
            'amount' => '500000',
            'charge_ugx' => '20000',
            'agent_id' => 'counter',
        ]);

        $this->assertSame('CN', $transfer->destination);
        $this->assertSame('USD', $transfer->payout_currency);
        $this->assertSame('480000.00', $transfer->amount);
        $this->assertSame('111.63', (string) $transfer->total_usd_equivalent);
        $this->assertSame('0.00', (string) $transfer->rmb_amount, 'No yuan is promised on this route.');
        $this->assertSame('0.00', (string) $transfer->ugx_amount);

        // 480,000 of the 500,000 travels, and 111.63 dollars cost the desk 4,000 shillings each.
        $this->assertSame('33480.00', $this->profit($transfer, 'fx_profit_ugx'));
        $this->assertSame('0.00', $this->profit($transfer, 'usd_rmb_profit_ugx'));
        $this->assertSame('53480.00', $this->profit($transfer, 'total_profit_ugx'));
        $this->assertSame('$111.63', $transfer->payoutLabel());

        // The shillings were counted over this counter, so all of them are Uganda till money.
        $this->assertSame(['money_in:UGX-UG' => '500000.00'], $this->lines($transfer->id));
        $this->assertSame('500000.00', Account::byCode(Account::UGX_UGANDA)->balance());
        $this->assertStringContainsString('UGX 500,000 posted to the ledger', session('success'));
    }

    public function test_dollars_handed_over_here_and_paid_out_in_china_land_in_two_accounts(): void
    {
        $office = $this->deskUser('admin', 'Grace Naigaga');

        $transfer = $this->book($office, [
            'destination' => 'CN',
            'payout_currency' => 'USD',
            'currency' => 'USD',
            'amount' => '112',
            'charge_ugx' => '2000',
            'agent_id' => 'counter',
        ]);

        $this->assertSame('112.00', $transfer->amount);
        $this->assertSame('112.00', (string) $transfer->total_usd_equivalent);
        $this->assertSame('0.00', $this->profit($transfer, 'fx_profit_ugx'));
        $this->assertSame('2000.00', $this->profit($transfer, 'total_profit_ugx'));

        // The dollars go forward whole and the charge arrives on top in shillings, so the deal
        // lands in two accounts and each gains only the money it actually holds.
        $this->assertSame([
            'money_in:USD-UG' => '112.00',
            'money_in:UGX-UG' => '2000.00',
        ], $this->lines($transfer->id));

        $this->assertStringContainsString('USD 112.0 plus UGX 2,000 posted to the ledger', session('success'));
    }

    public function test_yuan_handed_over_in_china_is_booked_into_the_china_yuan_account(): void
    {
        $office = $this->deskUser('admin', 'Grace Naigaga');

        $transfer = $this->book($office, [
            'destination' => 'UG',
            'currency' => 'RMB',
            'amount' => '6610',
            'charge_ugx' => '20000',
            'agent_id' => 'counter',
        ]);

        $this->assertSame('UG', $transfer->destination);
        $this->assertSame('UGX', $transfer->payout_currency, 'A receiver in Uganda is only ever paid shillings.');
        $this->assertSame('6610.00', $transfer->amount);
        $this->assertSame('1000.00', (string) $transfer->total_usd_equivalent);
        $this->assertSame('3980000.00', (string) $transfer->ugx_amount);
        $this->assertSame('0.00', (string) $transfer->rmb_amount, 'Nothing is promised to anybody in China here.');
        $this->assertSame('300000.00', $this->profit($transfer, 'fx_profit_ugx'));
        $this->assertSame('32774.39', $this->profit($transfer, 'usd_rmb_profit_ugx'));
        $this->assertSame('352774.39', $this->profit($transfer, 'total_profit_ugx'));
        $this->assertSame('UGX 3,980,000', $transfer->payoutLabel());

        // The yuan was counted in China even though this office keyed the receipt, so it is China's
        // money and sits in the China yuan account. The row still says which hand typed it in, and
        // the route says where the money came from; those are two different facts.
        $this->assertSame('UG', $transfer->country);
        $this->assertSame('Uganda', $transfer->deskLabel());
        $this->assertSame('China', $transfer->originLabel());
        $this->assertSame(['money_in:RMB-CN' => '6610.00'], $this->lines($transfer->id));
        $this->assertSame('6610.00', Account::byCode(Account::RMB_CHINA)->balance());
        $this->assertSame('0.00', Account::byCode(Account::UGX_UGANDA)->balance());

        // The charge is held back from the shillings paid out here, so it is not money the till
        // received and there is no commission line for it.
        $this->assertArrayNotHasKey('agent_fee:RMB-CN', $this->lines($transfer->id));
        $this->assertStringContainsString('RMB 6,610.0 posted to the ledger', session('success'));
    }

    public function test_dollars_a_china_agent_holds_are_kept_in_that_agent_s_dollar_wallet(): void
    {
        $collector = $this->deskUser('agent', 'Li Fang', 'CN');

        $transfer = $this->book($collector, [
            'destination' => 'UG',
            'currency' => 'USD',
            'amount' => '1000',
            'charge_ugx' => '20000',
        ]);

        $this->assertSame($collector->id, $transfer->created_by);
        $this->assertSame('CN', $transfer->country);
        $this->assertSame('3980000.00', (string) $transfer->ugx_amount);
        $this->assertSame('320000.00', $this->profit($transfer, 'total_profit_ugx'));

        // The hand beside the customer holds the dollars, so the office till only grows when that
        // cash is handed in. This agent's desk is in China, so they have a dollar wallet and never
        // a shilling one.
        $this->assertSame(['money_in:W-USD-'.$collector->id => '1000.00'], $this->lines($transfer->id));
        $this->assertSame('1000.00', Account::walletFor($collector, 'USD')->balance());
        $this->assertFalse(Account::where('owner_id', $collector->id)->where('currency', 'UGX')->exists());
        $this->assertSame('0.00', Account::byCode(Account::USD_CHINA)->balance());
    }

    /**
     * The office keys in what the China hand collected, which is how the book is actually kept. The
     * yuan then sits in that agent's own yuan wallet until it is handed in, exactly as shillings do
     * on the other corridor, and no commission line is written because the charge was never
     * collected as money.
     */
    public function test_yuan_an_office_books_into_a_china_agent_s_name_are_held_by_that_agent(): void
    {
        $office = $this->deskUser('admin', 'Grace Naigaga');
        $collector = $this->deskUser('agent', 'Li Fang', 'CN');

        $transfer = $this->book($office, [
            'destination' => 'UG',
            'currency' => 'RMB',
            'amount' => '6610',
            'charge_ugx' => '20000',
            'agent_id' => $collector->id,
        ]);

        $this->assertSame($collector->id, $transfer->created_by);
        $this->assertSame('CN', $transfer->country, 'The desk is the hand the money was booked into.');
        $this->assertSame('China', $transfer->originLabel());
        $this->assertSame(['money_in:W-RMB-'.$collector->id => '6610.00'], $this->lines($transfer->id));
        $this->assertSame('6610.00', Account::walletFor($collector, 'RMB')->balance());
        $this->assertSame('0.00', Account::byCode(Account::RMB_CHINA)->balance());
        $this->assertArrayNotHasKey('agent_fee:W-RMB-'.$collector->id, $this->lines($transfer->id));
    }

    public function test_a_booking_that_does_not_answer_the_route_is_the_corridor_the_book_has_always_run(): void
    {
        $office = $this->deskUser('admin', 'Grace Naigaga');

        $transfer = $this->book($office, [
            'currency' => 'UGX',
            'amount' => '500000',
            'charge_ugx' => '20000',
            'agent_id' => 'counter',
        ]);

        $this->assertSame('CN', $transfer->destination);
        $this->assertSame('RMB', $transfer->payout_currency);
        $this->assertSame('480000.00', $transfer->amount);
        $this->assertSame('111.63', (string) $transfer->total_usd_equivalent);
        $this->assertSame('732.28', (string) $transfer->rmb_amount);
        $this->assertEqualsWithDelta(56865.36, (float) $transfer->total_profit_ugx, 0.01);
        $this->assertSame(['money_in:UGX-UG' => '500000.00'], $this->lines($transfer->id));
    }

    public function test_the_money_a_route_cannot_take_is_refused_naming_the_box(): void
    {
        $office = $this->deskUser('admin', 'Grace Naigaga');

        $cases = [
            [
                'answers' => ['destination' => 'UG', 'currency' => 'UGX', 'payout_currency' => 'UGX', 'amount' => '500000', 'charge_ugx' => '20000', 'agent_id' => 'counter'],
                'field' => 'currency',
                'words' => 'On the way to Uganda the money is handed over in China',
            ],
            [
                'answers' => ['destination' => 'UG', 'currency' => 'RMB', 'payout_currency' => 'RMB', 'amount' => '6610', 'charge_ugx' => '20000', 'agent_id' => 'counter'],
                'field' => 'payout_currency',
                'words' => 'A receiver in Uganda is handed UGX shillings.',
            ],
            [
                'answers' => ['destination' => 'CN', 'currency' => 'UGX', 'payout_currency' => 'UGX', 'amount' => '500000', 'charge_ugx' => '20000', 'agent_id' => 'counter'],
                'field' => 'payout_currency',
                'words' => 'A receiver in China is handed either RMB yuan or USD dollars.',
            ],
            [
                'answers' => ['destination' => 'XX', 'currency' => 'UGX', 'payout_currency' => 'RMB', 'amount' => '500000', 'charge_ugx' => '20000', 'agent_id' => 'counter'],
                'field' => 'destination',
                'words' => 'Money either goes to a receiver in China or to a receiver in Uganda.',
            ],
        ];

        foreach ($cases as $case) {
            $this->actingAs($office)->get('/transactions/create');
            $this->actingAs($office)->post('/transactions', $this->payload($case['answers']))
                ->assertSessionHasErrors($case['field']);

            $this->assertStringContainsString($case['words'], $this->flashedError($case['field']));
        }

        $this->assertSame(0, Transaction::count(), 'A refused booking never reaches the book.');
    }

    public function test_a_route_is_only_blocked_by_the_prices_it_reads(): void
    {
        $office = $this->deskUser('admin', 'Grace Naigaga');

        ExchangeRate::retirePair('USD', 'RMB');

        // Dollars out in China never touch the yuan sheet, and dollars in from China do not either.
        $this->book($office, [
            'destination' => 'CN',
            'payout_currency' => 'USD',
            'currency' => 'UGX',
            'amount' => '500000',
            'charge_ugx' => '20000',
            'agent_id' => 'counter',
        ]);

        $first = Transaction::latest('id')->firstOrFail();
        $this->assertSame('53480.00', $this->profit($first, 'total_profit_ugx'));

        $dollarIn = $this->book($office, [
            'destination' => 'UG',
            'currency' => 'USD',
            'amount' => '1000',
            'charge_ugx' => '20000',
            'agent_id' => 'counter',
        ]);
        $this->assertSame('320000.00', $this->profit($dollarIn, 'total_profit_ugx'));

        // The two routes that do price yuan refuse, and say which price is missing rather than
        // quoting a rate the customer was never charged.
        foreach ([
            ['destination' => 'CN', 'payout_currency' => 'RMB', 'currency' => 'UGX', 'amount' => '500000', 'charge_ugx' => '20000'],
            ['destination' => 'UG', 'payout_currency' => 'UGX', 'currency' => 'RMB', 'amount' => '6610', 'charge_ugx' => '20000'],
        ] as $answers) {
            $this->actingAs($office)->get('/transactions/create');
            $this->actingAs($office)->post('/transactions', $this->payload($answers + ['agent_id' => 'counter']))
                ->assertSessionHasErrors('exchange_rate');

            $this->assertStringContainsString('USD to RMB', $this->flashedError('exchange_rate'));
        }

        $this->assertSame(2, Transaction::count());
    }

    public function test_a_charge_that_leaves_the_receiver_in_uganda_nothing_is_refused_where_it_is_typed(): void
    {
        $office = $this->deskUser('admin', 'Grace Naigaga');

        // 100 yuan is 15.13 dollars, or 60,529 shillings, so a charge that size leaves the receiver
        // in Uganda with nothing and the booking is refused instead of saving a negative payout.
        $this->actingAs($office)->get('/transactions/create');
        $this->actingAs($office)->post('/transactions', $this->payload([
            'destination' => 'UG',
            'currency' => 'RMB',
            'amount' => '100',
            'charge_ugx' => '70000',
            'agent_id' => 'counter',
        ]))->assertSessionHasErrors('charge_ugx');

        $this->assertSame(0, Transaction::count());
        $this->assertStringContainsString('The charge leaves nothing to send', $this->flashedError('charge_ugx'));
    }
}
