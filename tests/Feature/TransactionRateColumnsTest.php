<?php

namespace Tests\Feature;

use App\Models\ExchangeRate;
use App\Models\Setting;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * The accountant follows a transfer up from the prices it was cut at, so the book shows the four
 * figures every booking stored: what a dollar cost in shillings and the price it went out at, and
 * the same two on the yuan side. Each pair can also be filtered to, because the desk reprices and
 * needs to see which transfers fell on which side of the change. The prices are the business's own
 * numbers, so a teller who cannot see profit cannot see them either.
 */
class TransactionRateColumnsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['superadmin', 'admin', 'agent', 'staff', 'accountant'] as $role) {
            Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        }

        ExchangeRate::setPair('USD', 'UGX', 3760, 3750, 3760);
        ExchangeRate::setPair('USD', 'RMB', 7.24, 7.20, 7.24);

        Setting::set('default_charge_ugx', '20000', 'float', 'transaction');
    }

    private function user(string $role): User
    {
        $user = User::create([
            'name' => 'Rates '.ucfirst($role),
            'email' => strtolower($role).uniqid().'@plussave.test',
            'password' => 'Password123',
            'is_active' => true,
        ]);
        $user->assignRole($role);

        return $user;
    }

    /**
     * UGX 3,780,000 handed over with a UGX 20,000 charge, so UGX 3,760,000 travels and buys
     * exactly $1,000 at the selling price of 3,760.
     */
    private function book(User $by, string $cash = '3780000', string $charge = '20000'): Transaction
    {
        $this->actingAs($by)->get('/transactions/create');
        $this->actingAs($by)->post('/transactions', [
            'form_id' => session('transaction_form_id'),
            'sender_name' => 'Rates Sender',
            'receiver_name' => 'Rates Receiver',
            'currency' => 'UGX',
            'amount' => $cash,
            'charge_ugx' => $charge,
        ])->assertSessionHasNoErrors();

        return Transaction::latest('id')->firstOrFail();
    }

    private function html(string $path, User $as): string
    {
        return $this->actingAs($as)->get($path)->getContent();
    }

    /**
     * The book exactly as that viewer sees it with those filters applied. The request carries the
     * viewer because narrowing by price is gated on the profit ability, so a request built without
     * one would prove nothing about what a teller can do.
     *
     * @return array<int, int>
     */
    private function filtered(User $as, array $query): array
    {
        $request = Request::create('/transactions', 'GET', $query);
        $request->setUserResolver(fn () => $as);

        return Transaction::visibleTo($as)->filtered($request)->pluck('id')->all();
    }

    public function test_the_book_shows_the_four_prices_each_transfer_was_booked_at(): void
    {
        $this->book($this->user('admin'));

        $html = $this->html('/transactions', $this->user('admin'));

        foreach (['Rates Used', 'bought / sent'] as $header) {
            $this->assertStringContainsString($header, $html);
        }

        // Shillings bought at 3,750 and sent at 3,760; yuan bought at 7.20 and sent at 7.24. Each
        // pair is named by its currency so the two lines cannot be read the wrong way round.
        $this->assertStringContainsString('UGX 3750 / 3760', $html);
        $this->assertStringContainsString('RMB 7.2 / 7.24', $html);

        // The tooltip spells out which half is which, so nobody has to remember the order.
        $this->assertStringContainsString('Bought at 3750 and sent at 3760 UGX per 1 USD', $html);
    }

    public function test_a_teller_sees_no_prices_at_all(): void
    {
        $teller = $this->user('staff');
        $row = $this->book($this->user('admin'));
        $row->forceFill(['created_by' => $teller->id])->save();

        $html = $this->html('/transactions', $teller);

        foreach (['Rates Used', 'bought / sent', 'UGX 3750 / 3760', 'RMB 7.2 / 7.24', 'UGX Rate', 'RMB Rate'] as $gone) {
            $this->assertStringNotContainsString($gone, $html);
        }

        // The customer's own figures stay on the row.
        $this->assertStringContainsString('UGX 20,000', $html);

        // A teller who asks the book to narrow by a price is obeyed nowhere: that row was not
        // booked at 3,770, and it still shows because the filter never reached the query.
        $this->assertSame([$row->id], $this->filtered($teller, ['usd_rate' => '3770', 'rmb_rate' => '9.99']));
    }

    public function test_a_transfer_with_no_recorded_price_says_n_a_rather_than_borrowing_todays(): void
    {
        $legacy = $this->book($this->user('admin'));
        $legacy->forceFill(['rate_buy_ugx' => null, 'rmb_buying_rate' => null])->save();

        $html = $this->html('/transactions', $this->user('admin'));

        // Both pairs are incomplete, so neither prints a half-guessed figure.
        $this->assertSame(2, substr_count($html, 'No price was recorded on this transfer'));
        $this->assertStringNotContainsString('3750 / 3760', $html);
    }

    public function test_the_book_can_be_cut_to_one_shilling_price_on_either_side_of_the_pair(): void
    {
        $admin = $this->user('admin');
        $first = $this->book($admin);

        // The desk reprices, and the next transfer is booked at the new pair.
        ExchangeRate::setPair('USD', 'UGX', 3800, 3790, 3800);
        $second = $this->book($admin);

        $viewer = $this->user('admin');

        // The selling price of the first day, and the buying price of the second, each find
        // exactly the transfers that met them, which is what following a reprice needs.
        foreach ([['3760', $first], ['3750', $first], ['3800', $second], ['3790', $second]] as [$price, $wanted]) {
            $html = $this->html('/transactions?usd_rate='.$price, $viewer);
            $this->assertSame([$wanted->id], $this->filtered($viewer, ['usd_rate' => $price]), 'price '.$price);
            $this->assertStringContainsString('Rates Sender', $html);
        }

        // A price neither transfer was booked at returns an empty book, not both of them.
        $this->assertSame([], $this->filtered($viewer, ['usd_rate' => '3770']));
    }

    public function test_the_book_can_be_cut_to_one_yuan_price(): void
    {
        $admin = $this->user('admin');
        $first = $this->book($admin);

        ExchangeRate::setPair('USD', 'RMB', 7.40, 7.35, 7.40);
        $second = $this->book($admin);

        $viewer = $this->user('admin');
        foreach ([['7.24', $first], ['7.2', $first], ['7.4', $second], ['7.35', $second]] as [$price, $wanted]) {
            $this->assertSame([$wanted->id], $this->filtered($viewer, ['rmb_rate' => $price]), 'price '.$price);
        }
    }

    public function test_a_price_that_is_not_a_number_is_ignored_rather_than_run_as_sql(): void
    {
        $this->book($this->user('admin'));

        $hostile = "3760 or 1=1--";
        $html = $this->html('/transactions?usd_rate='.urlencode($hostile), $this->user('admin'));

        // The booking is still there: a value that cannot be a price narrows nothing at all.
        $this->assertStringContainsString('Rates Sender', $html);
        $this->assertStringNotContainsString('SQLSTATE', $html);
    }

    public function test_the_price_dropdown_offers_only_what_this_desk_has_booked(): void
    {
        $this->book($this->user('admin'));

        ExchangeRate::setPair('USD', 'UGX', 3800, 3790, 3800);
        $agent = $this->user('agent');
        $this->book($agent);

        // The price menu is built from the viewer's own book, so an agent who has only ever booked
        // at the new pair is never offered the office's older prices.
        $this->assertSame(['3800', '3790'], Transaction::bookedPrices($agent, ['rate_buy_ugx', 'rate_sell_ugx']));
        $this->assertSame(['3800', '3790', '3760', '3750'],
            Transaction::bookedPrices($this->user('admin'), ['rate_buy_ugx', 'rate_sell_ugx']));

        // The menu rides on the profit ability, so an agent booking transfers never sees one.
        $this->assertStringNotContainsString('UGX Rate', $this->html('/transactions', $agent));
        $this->assertStringContainsString('value="3760"', $this->html('/transactions', $this->user('admin')));
    }

    public function test_the_transfer_page_names_all_four_prices_and_never_prints_a_zero_rate(): void
    {
        $author = $this->user('admin');
        $priced = $this->book($author);
        $legacy = $this->book($author);
        $legacy->forceFill(['rate_buy_ugx' => null, 'rmb_buying_rate' => null])->save();

        $admin = $this->user('admin');

        $html = $this->html('/transactions/'.$priced->id, $admin);
        foreach (['Shillings bought at:', 'Shillings sent at:', 'Yuan bought at:', 'Yuan sent at:',
                  'UGX 3750 per 1 USD', 'UGX 3760 per 1 USD', '7.2 per 1 USD', '7.24 per 1 USD'] as $line) {
            $this->assertStringContainsString($line, $html);
        }
        $this->assertSame(0, substr_count($html, 'not recorded</div>'));

        // A price that was never stored is named as missing rather than priced at nothing, which an
        // accountant could otherwise read as a dollar bought for zero shillings.
        $missing = $this->html('/transactions/'.$legacy->id, $admin);
        $this->assertSame(2, substr_count($missing, 'not recorded</div>'));
        foreach (['UGX 0 per 1 USD', ' 0 per 1 USD'] as $zero) {
            $this->assertStringNotContainsString($zero, $missing);
        }
    }

    public function test_the_download_honours_the_price_filter_and_carries_the_prices(): void
    {
        $admin = $this->user('admin');
        $this->book($admin);
        ExchangeRate::setPair('USD', 'UGX', 3800, 3790, 3800);
        $this->book($admin);

        $bytes = $this->actingAs($admin)->get('/export/transactions?usd_rate=3750')->streamedContent();
        $rows = $this->sheet($bytes);

        // One transfer was touched by the 3,750 price, and it downloads with its prices beside it.
        $this->assertSame('PS-20261016-0001', $rows[6][0]);
        $this->assertStringContainsString('1 record', implode(' ', array_map(fn ($row) => implode(' ', $row), $rows)));
        $this->assertContains('UGX Bought At (per USD)', $rows[5]);
        $this->assertSame('3750', $rows[6][12]);
        $this->assertSame('3760', $rows[6][13]);
    }

    public function test_the_shilling_amount_that_travels_prints_as_a_flat_figure(): void
    {
        $transaction = $this->book($this->user('admin'));
        $admin = $this->user('admin');

        // Shillings are counted out in notes, so the whole book prints the amount with no decimals
        // while the yuan and the computed profits keep the one decimal a rate lands in.
        $this->assertSame('UGX 3,760,000', $transaction->principalLabel());
        $this->assertSame('UGX 3,780,000', $transaction->collectedAmount());

        foreach (['/transactions', '/transactions/'.$transaction->id, '/dashboard', '/reports'] as $path) {
            $html = $this->html($path, $admin);
            $this->assertStringContainsString('UGX 3,760,000', $html, $path);
            $this->assertStringNotContainsString('UGX 3,760,000.0', $html, $path);
        }

        $print = $this->html('/receipts/'.$transaction->receipt_number.'/print', $admin);
        $this->assertStringContainsString('UGX 3,760,000', $print);
        $this->assertStringNotContainsString('UGX 3,760,000.0', $print);

        // A dollar booking keeps its decimal, because dollars are cut by a rate.
        $dollars = $this->bookUsd($admin);
        $this->assertSame('USD 1,000.5', $dollars->principalLabel());
        $this->assertStringContainsString('USD 1,000.5', $this->html('/transactions', $admin));
    }

    private function bookUsd(User $by): Transaction
    {
        $this->actingAs($by)->get('/transactions/create');
        $this->actingAs($by)->post('/transactions', [
            'form_id' => session('transaction_form_id'),
            'sender_name' => 'Dollar Sender',
            'receiver_name' => 'Rates Receiver',
            'currency' => 'USD',
            'amount' => '1000.5',
            'charge_ugx' => '20000',
        ])->assertSessionHasNoErrors();

        return Transaction::latest('id')->firstOrFail();
    }

    /**
     * The downloaded workbook read back as a grid of cell text, because what matters is which
     * column each figure lands in.
     *
     * @return array<int, array<int, string>>
     */
    private function sheet(string $bytes): array
    {
        $path = storage_path('app/rates-sheet-check.xlsx');
        file_put_contents($path, $bytes);
        $sheet = IOFactory::load($path)->getActiveSheet();
        unlink($path);

        $rows = [];
        foreach (range(1, 7) as $row) {
            foreach (range('A', 'V') as $column) {
                $rows[$row][] = (string) $sheet->getCell($column.$row)->getValue();
            }
        }

        return $rows;
    }
}
