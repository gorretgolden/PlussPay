<?php

namespace Tests\Feature;

use App\Models\ExchangeRate;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Every transfer came in at one desk or the other, and the book has to say which without asking
 * where the person who booked it happens to sit today. These tests follow the desk from the stamp
 * on the row through the filter, the money strip, the download and the receipt.
 */
class TransactionDeskTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['superadmin', 'admin', 'agent', 'accountant'] as $role) {
            Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        }

        ExchangeRate::setPair('USD', 'UGX', 3760, 3750, 3760);
        ExchangeRate::setPair('USD', 'RMB', 6.75, 6.75, 6.75);
    }

    private function user(string $role, string $name, string $country = 'UG'): User
    {
        $user = User::create([
            'name' => $name,
            'email' => strtolower(str_replace(' ', '.', $name)).'@plussave.test',
            'password' => 'Password123',
            'is_active' => true,
            'country' => $country,
        ]);

        $user->assignRole($role);

        return $user;
    }

    /**
     * What the wizard posts: 500,000 handed over with the 20,000 charge still inside it.
     *
     * @param  array<string, mixed>  $extra
     */
    private function book(User $booker, array $extra = []): Transaction
    {
        $this->actingAs($booker)->get('/transactions/create');

        $this->actingAs($booker)->post('/transactions', array_merge([
            'form_id' => session('transaction_form_id'),
            'sender_name' => 'Grace Auma',
            'receiver_name' => 'Zhang Wei',
            'currency' => 'UGX',
            'amount' => '500000',
            'charge_ugx' => '20000',
            'purpose' => 'Family Support',
            'payment_method' => 'Cash',
        ], $extra))->assertSessionHasNoErrors();

        return Transaction::latest('id')->firstOrFail();
    }

    /**
     * A desk name and its code are printed as one cell, the code in the quieter tone. The same
     * shape is read by the list column and the money strip, so both are matched here.
     */
    private function assertDeskPrinted(string $html, string $desk, string $code): void
    {
        $this->assertMatchesRegularExpression(
            '/>'.$desk.'<\/span><span class="text-gray-400"> · '.$code.'<\/span>/',
            $html,
            "Expected the {$desk} ({$code}) cell on the page."
        );
    }

    public function test_a_transfer_is_booked_to_the_desk_of_the_hand_it_was_written_into(): void
    {
        $office = $this->user('admin', 'Desk Admin');
        $china = $this->user('agent', 'Mei Lin', 'CN');

        // The office sits in Uganda and books into a hand in China. The money came in at China's
        // desk because that is whose hand holds it, which is what the desk column is for.
        $forChina = $this->book($office, ['agent_id' => $china->id]);
        $this->assertSame('CN', $forChina->country);
        $this->assertSame('China', $forChina->deskLabel());

        // Left on the office counter, the same booking belongs to the desk that took the cash.
        $atCounter = $this->book($office, ['agent_id' => 'counter']);
        $this->assertSame('UG', $atCounter->country);

        // And an agent booking their own work is on their own desk by the same rule. The China
        // hand books the road that starts at their counter: yuan in China, shillings paid here.
        $this->assertSame('CN', $this->book($china, [
            'destination' => 'UG',
            'currency' => 'RMB',
            'amount' => '6610',
        ])->country);
    }

    public function test_no_form_can_type_a_desk_and_no_row_is_saved_without_one(): void
    {
        $china = $this->user('agent', 'Mei Lin', 'CN');

        // The desk is read off the hand the money was booked into, so a payload that carries a
        // different one is refused the column rather than being believed. The money itself is yuan
        // handed over in China, because that is the road a China hand books.
        $typed = $this->book($china, [
            'country' => 'UG',
            'destination' => 'UG',
            'currency' => 'RMB',
            'amount' => '6610',
        ]);

        $this->assertNotContains('country', (new Transaction)->getFillable());
        $this->assertSame('CN', $typed->country);

        // Nothing in the book is left without an origin, so no row can drop out of the desk filter.
        $this->assertSame(0, Transaction::whereNull('country')->orWhere('country', '')->count());
    }

    public function test_moving_a_holder_to_another_desk_leaves_their_older_money_where_it_happened(): void
    {
        $office = $this->user('admin', 'Desk Admin');
        $china = $this->user('agent', 'Mei Lin', 'CN');

        $transfer = $this->book($office, ['agent_id' => $china->id]);

        // She is posted to Uganda and her row is saved again for any reason at all.
        $china->update(['country' => 'UG']);
        $transfer->save();

        $this->assertSame('CN', $transfer->fresh()->country, 'A transfer does not move when its holder does.');
        $this->assertSame('Uganda', $china->fresh()->countryLabel(), 'The person is on the new desk though.');

        // The book still reads the way it did the day the money came in.
        $html = $this->actingAs($this->user('superadmin', 'The Boss'))->get('/transactions')->getContent();
        $this->assertDeskPrinted($html, 'China', 'CN');
    }

    public function test_only_a_viewer_who_sees_the_whole_book_is_offered_the_desk_filter(): void
    {
        $boss = $this->user('superadmin', 'The Boss');
        $agent = $this->user('agent', 'Sarah Nalin');

        $this->actingAs($boss)->get('/transactions')->assertOk()
            ->assertSee('name="country"', false)
            ->assertSee('Both desks');

        // A desk that only books its own work has one desk in its book, so the question is noise.
        $this->actingAs($agent)->get('/transactions')->assertOk()
            ->assertDontSee('name="country"', false);
    }

    public function test_the_whole_book_can_be_cut_to_one_desk_and_the_desk_survives_the_quick_dates(): void
    {
        $boss = $this->user('superadmin', 'The Boss');
        $office = $this->user('admin', 'Desk Admin');
        $china = $this->user('agent', 'Mei Lin', 'CN');

        $atUganda = $this->book($office, ['agent_id' => 'counter']);
        $atChina = $this->book($office, ['agent_id' => $china->id]);

        $this->actingAs($boss)->get('/transactions?country=CN')->assertOk()
            ->assertSee($atChina->receipt_number)
            ->assertDontSee($atUganda->receipt_number);

        $this->actingAs($boss)->get('/transactions?country=UG')->assertOk()
            ->assertSee($atUganda->receipt_number)
            ->assertDontSee($atChina->receipt_number);

        // The quick date chips rebuild the query string, so a narrowed desk must ride along with
        // them instead of dropping the viewer back onto the whole book.
        $html = $this->actingAs($boss)->get('/transactions?country=CN')->getContent();
        $this->assertStringContainsString('?country=CN&amp;date_from='.today()->toDateString(), $html);
    }

    /**
     * The money-by-desk card on its own, so a figure is checked where it is summed rather than
     * somewhere else on the page that happens to print the same amount.
     */
    private function strip(string $html): string
    {
        $from = strpos($html, 'Money by desk');
        $this->assertNotFalse($from, 'The page has no money by desk card.');

        $to = strpos($html, '</table>', $from);
        $this->assertNotFalse($to, 'The money by desk card never closes.');

        return substr($html, $from, $to - $from);
    }

    public function test_the_money_strip_keeps_the_two_desks_apart_and_adds_back_up(): void
    {
        $boss = $this->user('superadmin', 'The Boss');
        $office = $this->user('admin', 'Desk Admin');
        $china = $this->user('agent', 'Mei Lin', 'CN');

        // Two transfers of the same size, one taken in at each desk.
        $this->book($office, ['agent_id' => 'counter']);
        $this->book($office, ['agent_id' => $china->id]);

        $strip = $this->strip($this->actingAs($boss)->get('/transactions')->getContent());

        $this->assertDeskPrinted($strip, 'Uganda', 'UG');
        $this->assertDeskPrinted($strip, 'China', 'CN');

        // 500,000 handed over and 20,000 of it kept, at each desk, and the line under them adds
        // the two back together rather than quietly showing one.
        $this->assertStringContainsString('UGX 500,000', $strip);
        $this->assertStringContainsString('UGX 20,000', $strip);
        $this->assertStringContainsString('UGX 1,000,000', $strip);
        $this->assertStringContainsString('UGX 40,000', $strip);
        $this->assertStringContainsString('Both desks', $strip);

        // The yuan that the two desks owe in China is its own column, so the shillings kept here
        // and the money owed there are never read as one figure.
        $this->assertStringContainsString('Yuan to China', $strip);
        $this->assertMatchesRegularExpression('/¥1,7\d\d\.\d/', $strip);

        // Narrowed to one desk, the strip shows that desk alone and drops the total line.
        $chinaOnly = $this->strip($this->actingAs($boss)->get('/transactions?country=CN')->getContent());
        $this->assertStringContainsString('UGX 500,000', $chinaOnly);
        $this->assertStringNotContainsString('UGX 1,000,000', $chinaOnly);
        $this->assertStringNotContainsString('Both desks', $chinaOnly);
        $this->assertStringNotContainsString('Uganda', $chinaOnly);
    }

    public function test_a_cancelled_booking_counts_as_a_booking_and_not_as_money(): void
    {
        $boss = $this->user('superadmin', 'The Boss');
        $office = $this->user('admin', 'Desk Admin');

        $cancelled = $this->book($office, ['agent_id' => 'counter']);
        $cancelled->update(['status' => 'cancelled']);

        $strip = $this->strip($this->actingAs($boss)->get('/transactions')->getContent());

        // One booking did happen, and no money is standing anywhere because it gave its cash back.
        $this->assertMatchesRegularExpression('/>Uganda<\/span>.*?<td[^>]*>1<\/td>\s*<td[^>]*>UGX 0<\/td>/s', $strip);
        $this->assertStringNotContainsString('UGX 500,000', $strip);
    }

    public function test_the_download_names_the_desk_and_obeys_the_desk_filter(): void
    {
        $boss = $this->user('superadmin', 'The Boss');
        $office = $this->user('admin', 'Desk Admin');
        $china = $this->user('agent', 'Mei Lin', 'CN');

        $atUganda = $this->book($office, ['agent_id' => 'counter']);
        $atChina = $this->book($office, ['agent_id' => $china->id]);

        $rows = $this->sheet($this->actingAs($boss)->get('/export/transactions')->streamedContent());
        $headers = $rows[5];

        $deskAt = array_search('Desk', $headers);
        $bookedAt = array_search('Booked By', $headers);
        $this->assertNotFalse($deskAt);
        $this->assertSame($bookedAt + 1, $deskAt, 'the desk is read beside the name it was booked into');

        $data = collect($rows)->slice(5)->reject(fn ($row) => trim((string) $row[0]) === '')->values();
        $byReceipt = $data->keyBy(fn ($row) => trim((string) $row[0]));

        $this->assertSame('Uganda', $byReceipt[$atUganda->receipt_number][$deskAt]);
        $this->assertSame('China', $byReceipt[$atChina->receipt_number][$deskAt]);

        // The name column carries the name and nothing else: where the money came in is the desk
        // column's job, and it must not be re-derived from wherever that person sits today.
        $this->assertSame('Desk Admin', trim((string) $byReceipt[$atUganda->receipt_number][$bookedAt]));
        $this->assertSame('Mei Lin', trim((string) $byReceipt[$atChina->receipt_number][$bookedAt]));

        $filtered = $this->sheet($this->actingAs($boss)->get('/export/transactions?country=CN')->streamedContent());
        $kept = collect($filtered)->slice(5)->reject(fn ($row) => trim((string) $row[0]) === '');

        $this->assertCount(1, $kept);
        $this->assertSame($atChina->receipt_number, trim((string) $kept->first()[0]));
        $this->assertSame('China', $kept->first()[$deskAt]);
    }

    public function test_the_desk_is_said_on_the_transfer_and_on_the_receipt_the_customer_keeps(): void
    {
        $office = $this->user('admin', 'Desk Admin');
        $china = $this->user('agent', 'Mei Lin', 'CN');

        $transfer = $this->book($office, ['agent_id' => $china->id]);

        $this->actingAs($office)->get("/transactions/{$transfer->id}")->assertOk()
            ->assertSee('Origin desk:')
            ->assertSee('China (CN)', false)
            ->assertSee('(China desk)', false)
            // The corridor block below says where the money has reached, so it must not also claim
            // a country the desk line has just contradicted.
            ->assertDontSee('Collected in Uganda', false)
            ->assertDontSee('Still held in Uganda', false);

        $receipt = $this->actingAs($office)->get("/receipts/{$transfer->receipt_number}")->assertOk()->getContent();
        $this->assertMatchesRegularExpression(
            '/Money taken by <span[^>]*>Mei Lin<\/span> at the <span[^>]*>China<\/span> desk/',
            $receipt,
            'the receipt names the hand and the desk it held the money at'
        );

        // The print copy is the same paper slip, so it carries the same line rather than a second
        // desk field the screen never shows.
        $this->assertMatchesRegularExpression(
            '/Money taken by <span[^>]*>Mei Lin<\/span> at the <span[^>]*>China<\/span> desk/',
            $this->actingAs($office)->get("/receipts/{$transfer->receipt_number}/print")->assertOk()->getContent(),
            'the printed slip names the same hand and desk as the screen copy'
        );
    }

    public function test_the_booking_book_prints_the_desk_the_money_came_in_at(): void
    {
        $office = $this->user('admin', 'Desk Admin');
        $china = $this->user('agent', 'Mei Lin', 'CN');

        $transfer = $this->book($office, ['agent_id' => $china->id]);

        Artisan::call('book:show', ['receipt' => $transfer->receipt_number]);

        // The buffer empties when it is read, so the dump is taken once.
        $book = Artisan::output();

        $this->assertStringContainsString('Origin desk', $book);
        $this->assertStringContainsString('China (CN)', $book);
    }

    public function test_older_bookings_are_read_back_off_the_hand_that_held_the_money(): void
    {
        $office = $this->user('admin', 'Desk Admin');
        $china = $this->user('agent', 'Mei Lin', 'CN');
        $uganda = $this->user('agent', 'Tom Kato');

        $heldByChina = $this->book($office, ['agent_id' => $china->id]);
        $heldByUganda = $this->book($office, ['agent_id' => $uganda->id]);

        // Rewind both rows to what the migration found: every older booking carrying the home desk,
        // because the column opened with Uganda and only the backfill knows better.
        DB::table('transactions')->update(['country' => 'UG']);

        Transaction::stampMissingDesks();

        $this->assertSame('CN', $heldByChina->fresh()->country, 'read back off the hand it was booked into');
        $this->assertSame('UG', $heldByUganda->fresh()->country, 'a holder on the home desk stays there');
    }

    /**
     * The download as the office reads it: row 5 is the header line and row 6 the first booking.
     *
     * @return array<int, array<int, string>>
     */
    private function sheet(string $bytes): array
    {
        $path = storage_path('app/desk-sheet-check.xlsx');
        file_put_contents($path, $bytes);
        $sheet = IOFactory::load($path)->getActiveSheet();
        unlink($path);

        $rows = [];
        foreach (range(1, 8) as $row) {
            foreach (range('A', 'Z') as $column) {
                $rows[$row][] = (string) $sheet->getCell($column.$row)->getValue();
            }
        }

        return $rows;
    }
}
