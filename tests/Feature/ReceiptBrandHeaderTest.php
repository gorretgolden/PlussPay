<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * The customer copy of a booking is led by the PlusPay mark, which is the name the customer knows,
 * and the company that signs it holds the right corner. The signed corner is drawn from the artwork
 * at public/plussave-logo.png, so the tests have to cover both states of that file: present, which
 * is how the repository ships it, and taken away, which is what a fresh checkout without the artwork
 * looks like. The name the desk saves in System Settings has to reach the signing line either way,
 * because the same slip is printed by whichever office is saved there.
 */
class ReceiptBrandHeaderTest extends TestCase
{
    use RefreshDatabase;

    private string $logoPath;
    private string $stash;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['superadmin', 'admin', 'agent', 'staff'] as $role) {
            Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        }

        $admin = User::create([
            'name' => 'Desk Admin',
            'email' => 'brand@plussave.test',
            'password' => 'Password123',
            'is_active' => true,
        ]);
        $admin->assignRole('admin');

        Setting::set('company_name', 'Plus Save Financial Solutions', 'string', 'company');

        Transaction::create([
            'receipt_number' => 'PS-BRAND-1',
            'sender_name' => 'Asuma Aggrey',
            'sender_contact' => '0700000001',
            'receiver_name' => 'Wei Ming',
            'receiver_contact' => '8613800000001',
            'currency' => 'USD',
            'amount' => 250.5,
            'exchange_rate' => 7.30,
            'rmb_amount' => 1850.5,
            'charge_ugx' => 20000,
            'status' => 'completed',
            'created_by' => $admin->id,
        ]);

        // The real artwork is put away for the run so no test can overwrite it, and every case
        // starts from the state it names rather than the state the last test left behind.
        $this->logoPath = public_path('plussave-logo.png');
        $this->stash = sys_get_temp_dir().'/plussave-logo-stash.png';

        if (is_file($this->logoPath)) {
            copy($this->logoPath, $this->stash);
            unlink($this->logoPath);
        }
    }

    protected function tearDown(): void
    {
        if (is_file($this->logoPath)) {
            unlink($this->logoPath);
        }

        if (is_file($this->stash)) {
            copy($this->stash, $this->logoPath);
            unlink($this->stash);
        }

        parent::tearDown();
    }

    private function screen(): string
    {
        return $this->actingAs(User::where('email', 'brand@plussave.test')->firstOrFail())
            ->get('/receipts/PS-BRAND-1')->assertOk()->getContent();
    }

    private function slip(): string
    {
        return $this->actingAs(User::where('email', 'brand@plussave.test')->firstOrFail())
            ->get('/receipts/PS-BRAND-1/print')->assertOk()->getContent();
    }

    /**
     * Put the shipped artwork back and hand round the copies that read it.
     */
    private function withArtwork(): array
    {
        $this->assertFileExists($this->stash, 'the Plus Save artwork is not in the repository');
        copy($this->stash, $this->logoPath);

        return [$this->screen(), $this->slip()];
    }

    public function test_the_shipped_artwork_is_a_picture_the_receipt_can_draw(): void
    {
        $this->assertFileExists($this->stash, 'public/plussave-logo.png is missing from the repository');
        $this->assertGreaterThan(400, imagesx(imagecreatefromstring(file_get_contents($this->stash))));
    }

    public function test_both_copies_lead_with_pluspay_and_hold_the_signed_corner_on_the_right(): void
    {
        foreach ($this->withArtwork() as $html) {
            $this->assertStringContainsString('alt="PlusPay"', $html);
            $this->assertStringContainsString('plussave-logo.png', $html);
            $this->assertStringContainsString('alt="Plus Save Financial Solutions"', $html);

            // The company mark is read after the PlusPay mark, which is the left corner.
            $this->assertLessThan(
                strpos($html, 'plussave-logo.png'),
                strpos($html, 'alt="PlusPay"'),
                'PlusPay does not lead the header'
            );
        }
    }

    public function test_the_signing_line_follows_whatever_is_saved_in_settings(): void
    {
        Setting::set('company_name', 'Aponye Money Services', 'string', 'company');

        foreach ([$this->screen(), $this->slip()] as $html) {
            $this->assertStringContainsString('Signature and name, for Aponye Money Services', $html);
        }
    }

    public function test_neither_copy_writes_the_company_name_across_the_header(): void
    {
        foreach ([$this->screen(), $this->slip()] as $html) {
            $this->assertStringNotContainsString('class="company"', $html);
            $this->assertStringNotContainsString('Plus Save Financial Solutions', $this->headerOf($html));
        }
    }

    public function test_the_signed_corner_stays_empty_while_the_artwork_is_absent(): void
    {
        $this->assertFileDoesNotExist($this->logoPath);

        foreach ([$this->screen(), $this->slip()] as $html) {
            $this->assertStringNotContainsString('plussave-logo.png', $html, 'a broken corner was drawn');
            $this->assertStringContainsString('Signature and name, for Plus Save Financial Solutions', $html);
        }
    }

    /**
     * What the customer sees above the money is the header, the part that carries the brands. The
     * browser tab names the company too, so the page head is cut out of it.
     */
    private function headerOf(string $html): string
    {
        $body = stripos($html, '<body');
        $this->assertNotFalse($body, 'no body to read the header from');

        $end = strpos($html, 'Money taken by', $body);
        $this->assertNotFalse($end, 'no money line to cut the header at');

        return substr($html, $body, $end - $body);
    }
}
