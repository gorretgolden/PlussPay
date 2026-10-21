<?php

namespace Tests\Feature;

use App\Models\ExchangeRate;
use App\Models\Setting;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * The desk takes money in more ways than over the counter now, and a till that does not say how it
 * was filled cannot be checked against a MoMo statement or a bank alert. So the Payment step asks
 * how the customer paid, and the two mobile money answers carry the confirmation number from the
 * payment alert. The pair travels with the booking: on the transfers list, on the download, on
 * the customer's receipt and in the booking's own book.
 */
class PaymentMethodBookingTest extends TestCase
{
    use RefreshDatabase;

    private User $desk;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['superadmin', 'admin', 'agent', 'staff'] as $role) {
            Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        }

        $this->desk = User::create([
            'name' => 'Clerk Paying',
            'email' => 'pay@plussave.test',
            'password' => 'Password123',
            'is_active' => true,
        ]);
        $this->desk->assignRole('admin');

        ExchangeRate::setPair('USD', 'UGX', 3760, 3750, 3760);
        ExchangeRate::setPair('USD', 'RMB', 7.24, 7.20, 7.24);
        Setting::set('default_charge_ugx', '20000', 'float', 'transaction');
    }

    /**
     * UGX 3,780,000 handed over with a UGX 20,000 charge, so UGX 3,760,000 travels. Each call
     * opens the form again, because one form id is good for exactly one booking.
     *
     * @return array<string, mixed>
     */
    private function payload(array $extra = []): array
    {
        $this->actingAs($this->desk)->get('/transactions/create');

        return array_merge([
            'form_id' => session('transaction_form_id'),
            'agent_id' => 'counter',
            'sender_name' => 'Asuma Aggrey',
            'receiver_name' => 'Wei Ming',
            'currency' => 'UGX',
            'amount' => '3780000',
            'charge_ugx' => '20000',
            'payment_method' => 'Cash',
        ], $extra);
    }

    private function book(array $extra = []): Transaction
    {
        $this->actingAs($this->desk)->post('/transactions', $this->payload($extra))
            ->assertSessionHasNoErrors();

        // The booking flashes its own receipt number, and that banner would otherwise show up on
        // the next page read and look like a row the filter missed.
        $this->flushSession();

        return Transaction::latest('id')->firstOrFail();
    }

    /**
     * A row written straight into the book, the way a booking from before this box existed looks:
     * nobody ever asked how the money arrived.
     */
    private function legacy(): Transaction
    {
        return Transaction::create([
            'receipt_number' => 'PS-PAY-OLD-1',
            'sender_name' => 'Old Sender',
            'sender_contact' => '0700000009',
            'receiver_name' => 'Old Receiver',
            'receiver_contact' => '8613800000009',
            'currency' => 'UGX',
            'amount' => 3760000,
            'exchange_rate' => 7.24,
            'rate_sell_ugx' => 3760,
            'rmb_amount' => 7240,
            'charge_ugx' => 20000,
            'charge_amount' => 5.32,
            'total_usd_equivalent' => 1000,
            'status' => 'completed',
            'created_by' => $this->desk->id,
        ]);
    }

    private function screen(Transaction $booking): string
    {
        return $this->actingAs($this->desk)
            ->get('/receipts/'.$booking->receipt_number)->assertOk()->getContent();
    }

    private function slip(Transaction $booking): string
    {
        return $this->actingAs($this->desk)
            ->get('/receipts/'.$booking->receipt_number.'/print')->assertOk()->getContent();
    }

    private function sheet(string $bytes): array
    {
        $path = storage_path('app/payment-method-sheet.xlsx');
        file_put_contents($path, $bytes);
        $sheet = IOFactory::load($path)->getActiveSheet();
        unlink($path);

        $rows = [];
        foreach (range(1, 7) as $row) {
            foreach (range('A', 'Z') as $column) {
                $rows[$row][] = (string) $sheet->getCell($column.$row)->getValue();
            }
        }

        return $rows;
    }

    public function test_the_payment_step_asks_how_the_customer_paid(): void
    {
        $html = $this->actingAs($this->desk)->get('/transactions/create')->assertOk()->getContent();

        $this->assertStringContainsString('Paid how? *</label>', $html);
        $this->assertMatchesRegularExpression('/<select name="payment_method" id="payment_method" required/', $html);
        $this->assertStringContainsString('Choose how the customer paid', $html);
        $this->assertStringContainsString('How the money reached this desk.', $html);

        foreach (Transaction::PAYMENT_METHODS as $method) {
            $this->assertStringContainsString('>'.$method.'</option>', $html, $method.' must be on the list');
        }

        $this->assertMatchesRegularExpression('/<input type="text" name="payment_reference" id="payment_reference"/', $html);
        $this->assertStringContainsString('id="rev_payment"', $html);
    }

    public function test_the_confirmation_box_opens_only_for_the_two_mobile_money_answers(): void
    {
        $html = $this->actingAs($this->desk)->get('/transactions/create')->assertOk()->getContent();

        // The box is part of the Payment step but starts out of sight. The script opens it for the
        // two mobile money answers and closes it for cash, bank transfer and anything else.
        $this->assertMatchesRegularExpression('/<div id="payment_reference_wrap" class="hidden">/', $html);
        $this->assertStringContainsString('const mobileMoneyPayments = ["MTN Mobile Money","Airtel Money"];', $html);
        $this->assertStringContainsString('return mobileMoneyPayments.includes(paymentSelect.value);', $html);
    }

    public function test_cash_is_the_one_answer_with_nothing_to_quote(): void
    {
        $cash = $this->book();
        $this->assertSame('Cash', $cash->payment_method);
        $this->assertNull($cash->payment_reference);

        $momo = $this->book([
            'payment_method' => 'MTN Mobile Money',
            'payment_reference' => '48219355',
        ]);
        $this->assertSame('MTN Mobile Money', $momo->payment_method);
        $this->assertSame('48219355', $momo->payment_reference);
    }

    public function test_mobile_money_is_refused_until_the_number_is_written(): void
    {
        foreach (Transaction::MOBILE_MONEY_METHODS as $method) {
            $this->actingAs($this->desk)->post('/transactions', $this->payload([
                'payment_method' => $method,
            ]))->assertSessionHasErrors('payment_reference');
        }

        $this->assertStringContainsString('confirmation number', $this->flashedError('payment_reference'));
        $this->assertSame(0, Transaction::count());
    }

    public function test_bank_transfer_and_other_are_booked_without_a_confirmation_number(): void
    {
        $bank = $this->book(['payment_method' => 'Bank transfer']);
        $this->assertSame('Bank transfer', $bank->payment_method);
        $this->assertNull($bank->payment_reference);

        $other = $this->book(['payment_method' => 'Other', 'sender_name' => 'Other Caller']);
        $this->assertSame('Other', $other->payment_method);
        $this->assertNull($other->payment_reference);
    }

    public function test_a_booking_cannot_be_made_without_saying_how_the_money_arrived(): void
    {
        $this->actingAs($this->desk)->post('/transactions', $this->payload(['payment_method' => '']))
            ->assertSessionHasErrors('payment_method');

        $this->assertStringContainsString('Say how the customer paid', $this->flashedError('payment_method'));
        $this->assertSame(0, Transaction::count());
    }

    public function test_a_way_of_paying_that_is_not_on_the_list_is_refused_with_the_list(): void
    {
        $this->actingAs($this->desk)->post('/transactions', $this->payload([
            'payment_method' => 'PayPal',
            'payment_reference' => 'PP-99',
        ]))->assertSessionHasErrors('payment_method');

        $this->assertStringContainsString('MTN Mobile Money, Airtel Money, Bank transfer, or Other', $this->flashedError('payment_method'));
        $this->assertSame(0, Transaction::count());
    }

    public function test_the_transfers_list_shows_how_each_booking_was_paid(): void
    {
        $momo = $this->book([
            'payment_method' => 'Airtel Money',
            'payment_reference' => 'AG38291045',
        ]);
        $old = $this->legacy();

        $html = $this->actingAs($this->desk)->get('/transactions')->assertOk()->getContent();

        $this->assertStringContainsString('Paid how', $html);
        $this->assertStringContainsString($momo->receipt_number, $html);
        $this->assertStringContainsString('Airtel Money', $html);
        $this->assertStringContainsString('AG38291045', $html);

        // A booking nobody asked about is not guessed at on the screen.
        $this->assertStringContainsString($old->receipt_number, $html);
    }

    public function test_the_list_narrows_to_one_way_of_paying(): void
    {
        $momo = $this->book([
            'payment_method' => 'MTN Mobile Money',
            'payment_reference' => '48219355',
        ]);
        $cash = $this->book(['sender_name' => 'Cash Caller']);

        $html = $this->actingAs($this->desk)->get('/transactions?payment_method=MTN+Mobile+Money')
            ->assertOk()->getContent();

        $this->assertStringContainsString($momo->receipt_number, $html);
        $this->assertStringNotContainsString($cash->receipt_number, $html);
    }

    public function test_a_booking_is_found_by_its_confirmation_number(): void
    {
        $momo = $this->book([
            'payment_method' => 'MTN Mobile Money',
            'payment_reference' => 'TRX-7712009',
        ]);
        $other = $this->book(['sender_name' => 'Cash Caller']);

        $html = $this->actingAs($this->desk)->get('/transactions?search=TRX-7712009')->assertOk()->getContent();

        $this->assertStringContainsString($momo->receipt_number, $html);
        $this->assertStringNotContainsString($other->receipt_number, $html);
    }

    public function test_both_copies_of_the_receipt_carry_the_method_and_the_number(): void
    {
        $momo = $this->book([
            'payment_method' => 'MTN Mobile Money',
            'payment_reference' => '48219355',
        ]);

        foreach ([$this->screen($momo), $this->slip($momo)] as $html) {
            $this->assertStringContainsString('Paid by', $html);
            $this->assertStringContainsString('MTN Mobile Money', $html);
            $this->assertStringContainsString('48219355', $html);
        }

        $cash = $this->book(['sender_name' => 'Cash Caller']);
        $this->assertStringContainsString('Paid by', $this->screen($cash));
        $this->assertStringNotContainsString('Confirmation', $this->screen($cash));

        // A booking that never said is left unsaid rather than written as cash on paper.
        $old = $this->legacy();
        $this->assertStringNotContainsString('Paid by', $this->screen($old));
        $this->assertStringNotContainsString('Paid by:', $this->slip($old));
    }

    public function test_the_download_carries_the_method_and_the_confirmation_number(): void
    {
        $this->book([
            'payment_method' => 'Airtel Money',
            'payment_reference' => 'AG38291045',
        ]);

        $rows = $this->sheet($this->actingAs($this->desk)->get('/export/transactions')->streamedContent());

        $this->assertContains('Paid How', $rows[5]);
        $this->assertContains('Confirmation Number', $rows[5]);

        $paid = array_search('Paid How', $rows[5]);
        $this->assertSame('Airtel Money', $rows[6][$paid]);
        $this->assertSame('AG38291045', $rows[6][$paid + 1]);
        $this->assertSame('Reason', $rows[5][$paid - 1], 'the two columns sit where the desk reads them');

        // The profit strip a desk without the numbers cannot see is cut out by position, so the two
        // new columns must not fall inside it.
        $agent = User::create([
            'name' => 'Desk Agent',
            'email' => 'agent.pay@plussave.test',
            'password' => 'Password123',
            'is_active' => true,
        ]);
        $agent->assignRole('agent');

        $own = $this->sheet($this->actingAs($agent)->get('/export/transactions')->streamedContent())[5];

        $this->assertContains('Paid How', $own);
        $this->assertContains('Confirmation Number', $own);
        $this->assertNotContains('Exchange Profit (UGX)', $own);
    }

    public function test_the_booking_book_prints_how_the_money_came_in(): void
    {
        $momo = $this->book([
            'payment_method' => 'Airtel Money',
            'payment_reference' => 'AG38291045',
        ]);

        Artisan::call('book:show', ['receipt' => $momo->receipt_number]);
        $this->assertStringContainsString('Airtel Money, confirmation AG38291045', Artisan::output());

        Artisan::call('book:show', ['receipt' => $this->legacy()->receipt_number]);
        $this->assertStringContainsString('(nobody said)', Artisan::output());
    }

    public function test_a_bulk_import_row_that_does_not_name_a_method_is_read_as_cash(): void
    {
        $csv = "sender_name,sender_contact,receiver_name,receiver_contact,currency,amount,charge_ugx,payment_method,payment_reference\n"
            ."Imported Sender,0700111001,Imported Receiver,8613800000011,UGX,3780000,20000,Cash,\n"
            ."MoMo Sender,0700111002,MoMo Receiver,8613800000012,UGX,3780000,20000,Airtel Money,AG55512203\n"
            ."Bank Sender,0700111003,Bank Receiver,8613800000013,UGX,3780000,20000,Bank transfer,\n";

        $this->actingAs($this->desk)->post('/transactions/bulk/import', [
            'file' => UploadedFile::fake()->createWithContent('book.csv', $csv),
        ])->assertSessionHasNoErrors();

        $rows = Transaction::orderBy('id')->get();
        $this->assertSame(3, $rows->count());
        $this->assertSame('Cash', $rows[0]->payment_method);
        $this->assertNull($rows[0]->payment_reference);
        $this->assertSame('Airtel Money', $rows[1]->payment_method);
        $this->assertSame('AG55512203', $rows[1]->payment_reference);
        $this->assertSame('Bank transfer', $rows[2]->payment_method);
        $this->assertNull($rows[2]->payment_reference);
    }

    public function test_a_bulk_import_row_needs_a_confirmation_number_when_it_is_mobile_money(): void
    {
        $csv = "sender_name,receiver_name,currency,amount,charge_ugx,payment_method\n"
            ."MoMo Sender,MoMo Receiver,UGX,3780000,20000,MTN Mobile Money\n"
            ."Bank Sender,Bank Receiver,UGX,3780000,20000,Bank transfer\n";

        $this->actingAs($this->desk)->post('/transactions/bulk/import', [
            'file' => UploadedFile::fake()->createWithContent('book.csv', $csv),
        ])->assertSessionHasNoErrors();

        // The mobile money row is held back for its number; the bank row needs none and books.
        $this->assertSame(1, Transaction::count());
        $this->assertSame('Bank transfer', Transaction::first()->payment_method);
        $this->assertStringContainsString('confirmation number', implode(' ', session()->get('import_errors', [])));
    }
}
