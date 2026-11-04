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
     * A desk is printed as its code on a quiet chip and its name beside it. The same shape is
     * read by the card and the list column, so both are matched here.
     */
    private function assertDeskPrinted(string $html, string $desk, string $code): void
    {
        $this->assertMatchesRegularExpression(
            '/>'.$code.'<\/span>\s*<span[^>]*>'.$desk.'<\/span>/',
            $html,
            "Expected the {$desk} ({$code}) cell on the page."
        );
    }

    /**
     * The card's words without its tags, so a figure is checked as the reader sees it even though
     * the unit and the number are set apart for the eye.
     */
    private function cardText(string $html): string
    {
        return trim(preg_replace('/\s+/', ' ', strip_tags($html)));
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

        // 500,000 came over the counter and 20,000 of it was the charge, so 480,000 travelled at
        // each desk. The card shows the travelling money and the charge beside it, and the line
        // under the two desks adds each column up rather than quietly showing one.
        $text = $this->cardText($strip);
        $this->assertStringContainsString('UGX 480,000', $text);
        $this->assertStringContainsString('UGX 20,000', $text);
        $this->assertStringContainsString('UGX 960,000', $text);
        $this->assertStringContainsString('UGX 40,000', $text);
        $this->assertStringContainsString('Both desks', $text);

        // The yuan that the two desks owe in China is its own column, so the shillings kept here
        // and the money owed there are never read as one figure.
        $this->assertStringContainsString('Yuan to China', $strip);
        $this->assertMatchesRegularExpression('/¥1,7\d\d\.\d/', $text);

        // Narrowed to one desk, the card shows that desk alone and drops the total line.
        $chinaOnly = $this->strip($this->actingAs($boss)->get('/transactions?country=CN')->getContent());
        $chinaText = $this->cardText($chinaOnly);
        $this->assertStringContainsString('UGX 480,000', $chinaText);
        $this->assertStringNotContainsString('UGX 960,000', $chinaText);
        $this->assertStringNotContainsString('Both desks', $chinaText);
        $this->assertStringNotContainsString('Uganda', $chinaText);
    }

    /**
     * A desk that handed over 500,000 notes and kept 20,000 of them sent 480,000 to its client.
     * The money column reads the 480,000, because the 20,000 already stands in the charge column
     * and a card that showed both figures as 500,000 would count the same notes twice.
     */
    public function test_the_desk_money_excludes_the_charge_that_is_already_counted_beside_it(): void
    {
        $boss = $this->user('superadmin', 'The Boss');
        $office = $this->user('admin', 'Desk Admin');

        $transfer = $this->book($office, ['agent_id' => 'counter']);

        $this->assertSame(480000.0, (float) $transfer->amount, 'The amount column is what travels.');
        $this->assertSame(500000.0, (float) $transfer->cashReceived(), 'The notes counted over the counter.');

        $text = $this->cardText($this->strip($this->actingAs($boss)->get('/transactions')->getContent()));
        $this->assertStringContainsString('UGX 480,000', $text);
        $this->assertStringNotContainsString('UGX 500,000', $text);
        $this->assertStringContainsString('Money that travels', $text);
    }

    /**
     * A transfer booked into a hand in China pays for itself in yuan, and the card has to say how
     * much yuan that hand put on the road. With only the shilling and dollar columns the desk's own
     * money read as nothing, which is the same fault as counting the charge twice: the card was not
     * reporting the money that travels.
     */
    public function test_the_desk_card_shows_the_yuan_a_china_booking_put_on_the_road(): void
    {
        $boss = $this->user('superadmin', 'The Boss');
        $office = $this->user('admin', 'Desk Admin');
        $china = $this->user('agent', 'Mei Lin', 'CN');

        $yuan = $this->book($office, [
            'agent_id' => $china->id,
            'destination' => 'UG',
            'currency' => 'RMB',
            'amount' => '6610',
            'charge_ugx' => '20000',
        ]);

        $this->assertSame(6610.0, (float) $yuan->amount);

        $text = $this->cardText($this->strip($this->actingAs($boss)->get('/transactions')->getContent()));
        $this->assertStringContainsString('Money that travels', $text);
        $this->assertStringContainsString('¥6,610.00', $text);
    }

    public function test_the_money_strip_reads_every_figure_from_the_left_edge(): void
    {
        $boss = $this->user('superadmin', 'The Boss');
        $office = $this->user('admin', 'Desk Admin');

        $this->book($office, ['agent_id' => 'counter']);

        $strip = $this->strip($this->actingAs($boss)->get('/transactions')->getContent());

        // The card reads from the left like the rest of the page, so no cell in it may sit
        // right-aligned against the table's far edge.
        $this->assertStringNotContainsString('text-right', $strip);
    }

    public function test_a_cancelled_booking_counts_as_a_booking_and_not_as_money(): void
    {
        $boss = $this->user('superadmin', 'The Boss');
        $office = $this->user('admin', 'Desk Admin');

        $cancelled = $this->book($office, ['agent_id' => 'counter']);
        $cancelled->update(['status' => 'cancelled']);

        $strip = $this->strip($this->actingAs($boss)->get('/transactions')->getContent());
        $text = $this->cardText($strip);

        // One booking did happen, and no money is standing anywhere because it gave its cash back.
        $this->assertMatchesRegularExpression('/Uganda\s+1\s+UGX 0/', $text);
        $this->assertStringNotContainsString('UGX 480,000', $text);
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

    public function test_the_route_is_read_on_the_list_the_transfer_and_the_download(): void
    {
        $boss = $this->user('superadmin', 'The Boss');
        $office = $this->user('admin', 'Desk Admin');
        $china = $this->user('agent', 'Mei Lin', 'CN');

        // The office books into a China hand. The money still starts in Uganda, which is a
        // different fact from the desk that holds it.
        $toChina = $this->book($office, ['agent_id' => $china->id]);
        $toUganda = $this->book($china, ['destination' => 'UG', 'currency' => 'RMB', 'amount' => '6610']);

        $list = $this->actingAs($boss)->get('/transactions')->assertOk()->getContent();

        $this->assertStringContainsString('>Route</th>', $list);
        $this->assertStringContainsString(
            '<span class="text-gray-900">Uganda</span><span class="px-1 text-gray-400">→</span><span class="text-gray-600">China</span>',
            $list,
            'the list says the money starts in Uganda and is sent to China'
        );
        $this->assertStringContainsString(
            '<span class="text-gray-900">China</span><span class="px-1 text-gray-400">→</span><span class="text-gray-600">Uganda</span>',
            $list,
            'the list says the reverse corridor the other way round'
        );

        // The single transfer names the route at the top, in the same two country names.
        $this->actingAs($boss)->get("/transactions/{$toChina->id}")->assertOk()
            ->assertSee('From <span class="font-semibold text-gray-900">Uganda</span> to <span class="font-semibold text-gray-900">China</span>', false);
        $this->actingAs($boss)->get("/transactions/{$toUganda->id}")->assertOk()
            ->assertSee('From <span class="font-semibold text-gray-900">China</span> to <span class="font-semibold text-gray-900">Uganda</span>', false);

        // The receipt the customer keeps carries the same route in its band.
        $this->assertStringContainsString(
            'aria-label="Money sent from Uganda to China"',
            $this->actingAs($boss)->get("/receipts/{$toChina->receipt_number}/print")->assertOk()->getContent()
        );
        $this->assertStringContainsString(
            'aria-label="Money sent from China to Uganda"',
            $this->actingAs($boss)->get("/receipts/{$toUganda->receipt_number}/print")->assertOk()->getContent()
        );

        // The download carries the route beside the desk, so a sheet read away from the screen
        // still says where the money came from.
        $rows = $this->sheet($this->actingAs($boss)->get('/export/transactions')->streamedContent());
        $headers = $rows[5];
        $routeAt = array_search('Route', $headers);
        $deskAt = array_search('Desk', $headers);
        $this->assertNotFalse($routeAt);
        $this->assertSame($deskAt + 1, $routeAt, 'the route sits beside the desk it belongs with');

        $data = collect($rows)->slice(5)
            ->reject(fn ($row) => trim((string) $row[0]) === '')
            ->keyBy(fn ($row) => trim((string) $row[0]));

        $this->assertSame('Uganda to China', $data[$toChina->receipt_number][$routeAt]);
        $this->assertSame('China to Uganda', $data[$toUganda->receipt_number][$routeAt]);
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
