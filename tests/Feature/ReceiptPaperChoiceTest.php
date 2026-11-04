<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * A desk with a receipt machine at the counter needs the slip to come out of that machine, not only
 * off A5 paper, so the print page holds a paper choice with two sheets. Each sheet carries its own
 * page size: a printer that is not told the width prints whatever it was last left at, which is how
 * an 80mm roll comes back with half the slip cut off.
 */
class ReceiptPaperChoiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['superadmin', 'admin', 'agent', 'staff'] as $role) {
            Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        }

        $admin = User::create([
            'name' => 'Paper Desk',
            'email' => 'paper@plussave.test',
            'password' => 'Password123',
            'is_active' => true,
        ]);
        $admin->assignRole('admin');

        Setting::set('company_name', 'Plus Save Financial Solutions', 'string', 'company');

        Transaction::create([
            'receipt_number' => 'PS-PAPER-1',
            'sender_name' => 'Asuma Aggrey',
            'sender_contact' => '0700000001',
            'receiver_name' => 'Wei Ming',
            'receiver_contact' => '8613800000001',
            'currency' => 'UGX',
            'amount' => 480000,
            'exchange_rate' => 7.24,
            'rmb_amount' => 3475200,
            'charge_ugx' => 20000,
            'status' => 'completed',
            'created_by' => $admin->id,
        ]);
    }

    private function printPage(): string
    {
        return $this->actingAs(User::where('email', 'paper@plussave.test')->firstOrFail())
            ->get('/receipts/PS-PAPER-1/print')->assertOk()->getContent();
    }

    public function test_the_print_menu_offers_the_two_papers_a_desk_keeps_at_the_counter(): void
    {
        $html = $this->printPage();

        $this->assertStringContainsString('aria-label="Choose the paper the receipt prints on"', $html);
        $this->assertStringContainsString('data-paper="a5"', $html);
        $this->assertStringContainsString('>POS machine</button>', $html);
        $this->assertStringContainsString('A5 portrait, print at 100%.', $html, 'The slip opens on the paper it has always printed on.');
        $this->assertStringContainsString('80mm roll, print at 100%.', $html, 'The roll is named by the width a printer understands.');
    }

    public function test_each_paper_carries_its_own_page_size_and_only_the_chosen_one_is_switched_on(): void
    {
        $html = $this->printPage();

        $this->assertSame(2, substr_count($html, '@page {'), 'One page size per paper and no other claim on the printer.');
        $this->assertMatchesRegularExpression('/<style id="page-a5" media="print">\s*\n?\s*@page \{ size: A5 portrait; margin: 6mm; \}/', $html);
        $this->assertMatchesRegularExpression('/<style id="page-pos" media="pluspay-off">\s*\n?\s*@page \{ size: 80mm auto; margin: 3mm; \}/', $html);

        $this->assertStringContainsString("document.body.dataset.paper = pos ? 'pos' : 'a5';", $html);
        $this->assertStringContainsString("pagePos.media = pos ? 'print' : 'pluspay-off';", $html);
        $this->assertStringContainsString("pageA5.media = pos ? 'pluspay-off' : 'print';", $html);
    }

    public function test_the_till_keeps_the_paper_it_was_last_set_to(): void
    {
        $html = $this->printPage();

        $this->assertStringContainsString("localStorage.setItem('pluspay-receipt-paper', button.dataset.paper);", $html);
        $this->assertStringContainsString("usePaper(localStorage.getItem('pluspay-receipt-paper') === 'pos' ? 'pos' : 'a5');", $html);
    }

    /**
     * The narrow set used to hang off the width of the window alone, so a till on a wide screen
     * chose the roll, saw the wide slip, and got a particulars table running past the paper. The
     * print page names its own sheet box now, so the paper the desk picked is what narrows the
     * slip. Both guards pull the same partial, because a counter phone and an 80mm roll should not
     * be holding two copies of the same spacing that can drift apart.
     */
    public function test_the_paper_chosen_narrows_the_slip_and_not_the_window(): void
    {
        $html = $this->printPage();

        $this->assertStringContainsString('container-name: slip-sheet;', $html);
        $this->assertStringContainsString('body[data-paper="pos"] .slip-sheet { max-width: 74mm; }', $html);
        $this->assertStringContainsString('@container slip-sheet (max-width: 480px)', $html);
        $this->assertStringContainsString('@container slip-sheet (max-width: 380px)', $html);

        $this->assertSame(2, substr_count($html, '.slip-table tbody td { font-size: 10px; }'), 'One narrow set, switched on twice.');
        $this->assertSame(2, substr_count($html, '.slip-route-name { font-size: 14px; letter-spacing: .03em; }'));

        // The A5 print spacing sits ahead of the roll rules. Were it the other way the roll would
        // print with the wider table padding and the table would fall off the paper again.
        $a5PrintSpacing = strpos($html, '.slip-table td { padding: 6px 8px; }');
        $rollRules = strpos($html, '@container slip-sheet (max-width: 480px)');
        $this->assertLessThan($rollRules, $a5PrintSpacing, 'The roll spacing outranks the A5 print spacing.');
    }
}
