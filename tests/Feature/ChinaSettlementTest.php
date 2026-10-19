<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\AccountEntry;
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
 * is worth are sent from Uganda to a desk in China, that desk changes them and hands the yuan to the
 * receiver. Each step moves real ledger money and stamps the transfer, so the yuan the boards count
 * and the cash the two desks hold stay one story.
 */
class ChinaSettlementTest extends TestCase
{
    use RefreshDatabase;

    private User $office;
    private User $agent;
    private User $china;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PermissionsSeeder::class);

        ExchangeRate::create(['from_currency' => 'USD', 'to_currency' => 'RMB', 'rate' => 6.75, 'buy_rate' => 6.75, 'sell_rate' => 6.75, 'is_active' => true]);
        ExchangeRate::create(['from_currency' => 'USD', 'to_currency' => 'UGX', 'rate' => 3760, 'buy_rate' => 3750, 'sell_rate' => 3760, 'is_active' => true]);

        $this->office = $this->person('Office Admin', 'admin', 'UG');
        $this->agent = $this->person('Kampala Agent', 'agent', 'UG');
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
     * A booking already collected and priced: one thousand dollars travelling, six thousand seven
     * hundred and fifty yuan promised to the receiver.
     */
    private function booked(string $receipt, User $creator, float $usd = 1000.0): Transaction
    {
        return Transaction::withoutEvents(fn () => Transaction::create([
            'receipt_number' => $receipt,
            'sender_name' => 'Sender '.$receipt,
            'sender_contact' => '',
            'receiver_name' => 'Receiver '.$receipt,
            'receiver_contact' => '',
            'currency' => 'USD',
            'amount' => $usd,
            'exchange_rate' => 6.75,
            'rmb_amount' => $usd * 6.75,
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
            ->assertSee('Yuan to receive in China')
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

    public function test_an_agent_in_uganda_is_never_asked_to_confirm_a_payout_they_cannot_make(): void
    {
        $transaction = $this->booked('PS-UG-0009', $this->agent);
        $this->send($transaction, $this->dollars(1000), $this->china);

        $this->actingAs($this->agent)->get('/china')
            ->assertOk()
            ->assertSee('Yuan to receive in China')
            ->assertDontSee('Yuan in China, unpaid');

        $this->actingAs($this->agent)->post('/china/payout', [
            'transaction_ids' => [$transaction->fresh()->id],
        ])->assertForbidden();

        $this->assertNull($transaction->fresh()->delivered_at);
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
        $mine = $this->booked('PS-UG-0012', $this->agent);
        $theirs = $this->booked('PS-UG-0013', $other);

        // A desk sends out of its own dollar wallet, which is all the send form offers it.
        $wallet = Account::walletFor($this->agent, 'USD');
        AccountLedger::record($wallet, 'opening_balance', '2000.00', 'Dollars collected at the counter');

        $this->actingAs($this->agent)->post('/china/send', [
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
            ->assertDontSee('Yuan to receive in China');

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

        AccountLedger::payOutInChina($paid->fresh(), Account::walletFor($this->china, 'RMB'));

        $this->actingAs($this->office)->get('/dashboard')
            ->assertOk()
            ->assertSee('Yuan To Receive In China')
            ->assertSee('¥6,750.0')
            ->assertSee('Yuan In China, Unpaid')
            ->assertSee('Yuan Paid In China');
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

    public function test_the_exchange_desk_offers_a_person_their_own_wallets_to_convert(): void
    {
        $wallets = Account::walletsFor($this->agent);

        $this->actingAs($this->agent)->get('/exchanges/create')
            ->assertOk()
            ->assertSee('Kampala Agent (UGX)')
            ->assertSee('Kampala Agent (USD)');

        // The dollars a transfer is bought with come out of the shillings in hand, so the shilling
        // wallet is what the overdraft guard watches.
        $this->assertSame(['UGX', 'USD'], array_keys($wallets));
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
