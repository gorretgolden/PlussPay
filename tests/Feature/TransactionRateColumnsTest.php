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
 * Every transfer in the book carries the two selling prices it went out at: the shillings each
 * dollar cost the customer and the yuan each dollar became in China. Those are the figures a desk
 * quotes and a receiver is paid on, so they are the only rates a transaction record shows. The
 * buying prices stay stored and keep feeding the profit, but they are not printed here. Either
 * selling price can be filtered to, because the desk reprices and needs to see which transfers fell
 * on which side of the change. The prices are the business's own numbers, so a teller who cannot
 * see profit cannot see them either. The customer copy carries the same two prices, because the
 * money travels in two steps and a slip quoting only the yuan price leaves the shilling one
 * unexplained.
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
    private function book(User $by, string $cash = '3780000', string $charge = '20000', ?string $purpose = null): Transaction
    {
        $this->actingAs($by)->get('/transactions/create');
        $this->actingAs($by)->post('/transactions', [
            'form_id' => session('transaction_form_id'),
            'agent_id' => 'counter',
            'sender_name' => 'Rates Sender',
            'receiver_name' => 'Rates Receiver',
            'currency' => 'UGX',
            'amount' => $cash,
            'charge_ugx' => $charge,
            'purpose' => $purpose,
        ])->assertSessionHasNoErrors();

        return Transaction::latest('id')->firstOrFail();
    }

    /**
     * The two selling prices used to share one cell, so a row had to be read top to bottom to know
     * which figure was which. Each now stands under its own heading, and the money columns keep the
     * order the transfer takes: what travelled, what it became in China, the prices, the charge.
     */
    public function test_each_selling_price_stands_under_a_heading_of_its_own(): void
    {
        $this->book($this->user('admin'));

        $html = $this->html('/transactions', $this->user('admin'));

        $this->assertMatchesRegularExpression('/Yuan Selling Price<\/th>\s*<th[^>]*>Charge \(UGX\)<\/th>/', $html);
        $this->assertMatchesRegularExpression('/Dollar Selling Price<\/th>\s*<th[^>]*>Yuan Selling Price<\/th>/', $html);

        // One figure to a cell, not two lines stacked inside one box.
        $this->assertSame(0, preg_match_all('/<td[^>]*>\s*<div[^>]*>UGX 3760<\/div>/', $html));
    }

    /**
     * The reason a customer gave is on the booking and on the receipt, so the desk can match a row to
     * a conversation without opening the receipt. The receipt number is the link a teller reads out
     * over the phone, so it has to stay whole on one line rather than break mid-number.
     */
    public function test_the_reason_reads_on_the_row_and_the_receipt_number_stays_whole(): void
    {
        $row = $this->book($this->user('admin'), '3780000', '20000', 'School fees');

        $html = $this->html('/transactions', $this->user('admin'));

        $this->assertMatchesRegularExpression('/<th[^>]*>Reason<\/th>/', $html);
        $this->assertStringContainsString('School fees', $html);

        // The number is one unbroken run, and the cell that holds it refuses to wrap.
        $this->assertMatchesRegularExpression(
            '/<td class="[^"]*whitespace-nowrap[^"]*">\s*<a [^>]*class="[^"]*whitespace-nowrap[^"]*">'.$row->receipt_number.'<\/a>/',
            $html
        );

        // A booking with no reason says so rather than leaving an empty box a teller reads as a bug.
        $this->book($this->user('admin'));
        $plain = $this->html('/transactions', $this->user('admin'));
        $this->assertMatchesRegularExpression('/<td class="[^"]*text-gray-700[^"]*">-<\/td>/', $plain);
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

    public function test_the_book_shows_the_two_selling_prices_each_transfer_went_out_at(): void
    {
        $this->book($this->user('admin'));

        $html = $this->html('/transactions', $this->user('admin'));

        // Each price has its own column, so a row can be read without working out which of the two
        // numbers is which. The sentence explaining how each price runs sits once under the table
        // rather than wrapped inside a header.
        $this->assertStringContainsString('Dollar Selling Price', $html);
        $this->assertStringContainsString('Yuan Selling Price', $html);
        $this->assertStringNotContainsString('what it went out at, per 1 USD', $html);

        // Sent out at 3,760 shillings to the dollar and 7.24 yuan to the dollar. Each line is named
        // by its currency so the two cannot be read the wrong way round.
        $this->assertStringContainsString('UGX 3760', $html);
        $this->assertStringContainsString('RMB 7.24', $html);

        // The tooltip says which way the price runs, and the buying price the profit was cut from
        // appears nowhere on the row.
        $this->assertStringContainsString('Sent at 3760 UGX per 1 USD', $html);
        $this->assertStringNotContainsString('3750', $html);
        $this->assertStringNotContainsString('7.2 ', $html);
    }

    /**
     * The money on a booking travels in two steps, shillings into dollars then dollars into yuan,
     * so a customer copy that quotes only the second price leaves the first one unexplained. Both
     * selling prices print on the screen receipt and on the slip handed over the counter.
     */
    public function test_both_customer_copies_carry_both_selling_prices(): void
    {
        $row = $this->book($this->user('admin'));

        foreach (['/receipts/'.$row->receipt_number, '/receipts/'.$row->receipt_number.'/print'] as $path) {
            $html = $this->html($path, $this->user('admin'));

            // The slip prints in capitals, so the heading is matched whatever case the copy uses.
            $this->assertTrue(str_contains(strtolower($html), 'per 1 usd'), $path.' has no rate heading');
            $this->assertStringContainsString('UGX 3760', $html, $path);
            $this->assertStringContainsString('RMB 7.24', $html, $path);

            // The buying prices the margin was cut from stay off the document the customer keeps.
            $this->assertStringNotContainsString('3750', $html, $path);
            $this->assertStringNotContainsString('7.2 ', $html, $path);
        }
    }

    public function test_a_teller_sees_no_prices_at_all(): void    {
        $teller = $this->user('staff');
        $row = $this->book($this->user('admin'));
        $row->forceFill(['created_by' => $teller->id])->save();

        $html = $this->html('/transactions', $teller);

        foreach (['Selling Price', 'UGX 3760', 'RMB 7.24', 'Dollar Selling Price', 'Yuan Selling Price'] as $gone) {
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
        // A transfer cannot be booked without a yuan price, so the oldest rows carry it as nothing
        // rather than as null; both readings have to come out as n/a.
        $legacy->forceFill(['rate_sell_ugx' => null, 'exchange_rate' => 0])->save();

        $html = $this->html('/transactions', $this->user('admin'));

        // Neither side has a price of its own, so neither prints a half-guessed figure.
        $this->assertSame(2, substr_count($html, 'No price was recorded on this transfer'));
        $this->assertStringNotContainsString('UGX 3760', $html);
    }

    public function test_the_book_can_be_cut_to_one_shilling_selling_price(): void
    {
        $admin = $this->user('admin');
        $first = $this->book($admin);

        // The desk reprices, and the next transfer goes out at the new selling price.
        ExchangeRate::setPair('USD', 'UGX', 3800, 3790, 3800);
        $second = $this->book($admin);

        $viewer = $this->user('admin');

        foreach ([['3760', $first], ['3800', $second]] as [$price, $wanted]) {
            $html = $this->html('/transactions?usd_rate='.$price, $viewer);
            $this->assertSame([$wanted->id], $this->filtered($viewer, ['usd_rate' => $price]), 'price '.$price);
            $this->assertStringContainsString('Rates Sender', $html);
        }

        // A buying price is not a filter any more, so the 3,750 the office paid for those first
        // dollars finds nothing rather than reaching into a column the book no longer shows.
        $this->assertSame([], $this->filtered($viewer, ['usd_rate' => '3750']));
        $this->assertSame([], $this->filtered($viewer, ['usd_rate' => '3770']));
    }

    public function test_the_book_can_be_cut_to_one_yuan_selling_price(): void
    {
        $admin = $this->user('admin');
        $first = $this->book($admin);

        ExchangeRate::setPair('USD', 'RMB', 7.40, 7.35, 7.40);
        $second = $this->book($admin);

        $viewer = $this->user('admin');
        foreach ([['7.24', $first], ['7.4', $second]] as [$price, $wanted]) {
            $this->assertSame([$wanted->id], $this->filtered($viewer, ['rmb_rate' => $price]), 'price '.$price);
        }

        $this->assertSame([], $this->filtered($viewer, ['rmb_rate' => '7.2']));
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

        // The price menu is built from the viewer's own book, so an agent who has only ever gone out
        // at the new price is never offered the office's older one. It lists selling prices only.
        $this->assertSame(['3800'], Transaction::bookedPrices($agent, ['rate_sell_ugx']));
        $this->assertSame(['3800', '3760'], Transaction::bookedPrices($this->user('admin'), ['rate_sell_ugx']));

        // The menu rides on the profit ability, so an agent booking transfers never sees one.
        $this->assertStringNotContainsString('Dollar Selling Price', $this->html('/transactions', $agent));
        $this->assertStringContainsString('value="3760"', $this->html('/transactions', $this->user('admin')));
    }

    public function test_the_transfer_page_names_both_selling_prices_and_never_prints_a_zero_rate(): void
    {
        $author = $this->user('admin');
        $priced = $this->book($author);
        $legacy = $this->book($author);
        $legacy->forceFill(['rate_sell_ugx' => null, 'exchange_rate' => 0])->save();

        $admin = $this->user('admin');

        $html = $this->html('/transactions/'.$priced->id, $admin);
        foreach (['Dollar selling rate:', 'Yuan selling rate:',
                  'UGX 3760 per 1 USD', '7.24 per 1 USD'] as $line) {
            $this->assertStringContainsString($line, $html);
        }
        // What the office paid for those dollars is not on a customer's record.
        $this->assertStringNotContainsString('UGX 3750 per 1 USD', $html);
        $this->assertStringNotContainsString('bought at', $html);
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
        // The receipt number carries the day it was booked, so the clock is pinned or this test
        // fails the moment the date rolls over.
        $this->travelTo(\Carbon\Carbon::parse('2026-10-16 09:05:00'));
        $admin = $this->user('admin');
        $this->book($admin);
        ExchangeRate::setPair('USD', 'UGX', 3800, 3790, 3800);
        $this->book($admin);

        $bytes = $this->actingAs($admin)->get('/export/transactions?usd_rate=3760')->streamedContent();
        $rows = $this->sheet($bytes);

        // One transfer went out at 3,760 to the dollar, and it downloads with both selling prices
        // beside it.
        $this->assertSame('PS-20261016-0001', $rows[6][0]);
        $this->assertStringContainsString('1 record', implode(' ', array_map(fn ($row) => implode(' ', $row), $rows)));
        $this->assertContains('Dollar Selling Price (per USD)', $rows[5]);
        $this->assertContains('Yuan Selling Price (per USD)', $rows[5]);
        $this->assertSame('3760', $rows[6][12]);
        $this->assertSame('7.24', $rows[6][13]);
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
            'agent_id' => 'counter',
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
