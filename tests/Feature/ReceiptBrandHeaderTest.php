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
 * and the company that signs it is named where it signs rather than across the header. The signing
 * line has to follow System Settings, because the same slip is printed by whichever office name is
 * saved there, and the second corner has to swap to the Plus Save artwork the moment that file is
 * dropped into public/.
 */
class ReceiptBrandHeaderTest extends TestCase
{
    use RefreshDatabase;

    private string $logoPath;

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

        $this->logoPath = public_path('plussave-logo.png');
    }

    protected function tearDown(): void
    {
        if (is_file($this->logoPath)) {
            unlink($this->logoPath);
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

    public function test_both_copies_carry_pluspay_and_the_saved_company_name(): void
    {
        foreach ([$this->screen(), $this->slip()] as $index => $html) {
            $this->assertStringContainsString('logo.webp', $html, 'copy ' . $index);
            $this->assertStringContainsString('alt="PlusPay"', $html, 'copy ' . $index);
            $this->assertStringContainsString('Plus Save Financial Solutions', $html, 'copy ' . $index);
        }
    }

    public function test_the_signing_line_follows_whatever_is_saved_in_settings(): void
    {
        Setting::set('company_name', 'Aponye Money Services', 'string', 'company');

        foreach ([$this->screen(), $this->slip()] as $html) {
            $this->assertStringContainsString('Signature and name, for Aponye Money Services', $html);
        }
    }

    public function test_neither_copy_puts_the_company_name_in_the_header(): void
    {
        foreach ([$this->screen(), $this->slip()] as $html) {
            $this->assertStringNotContainsString('class="company"', $html);
            $this->assertStringNotContainsString('Plus Save Financial Solutions', $this->headerOf($html));
        }
    }

    public function test_the_saved_company_name_is_used_until_the_artwork_arrives(): void
    {
        $this->assertFileDoesNotExist($this->logoPath);

        foreach ([$this->screen(), $this->slip()] as $html) {
            $this->assertStringNotContainsString('plussave-logo.png', $this->headerOf($html), 'the second corner stayed empty');
            $this->assertStringContainsString('Plus Save Financial Solutions', $html, 'the signing line lost its name');
        }
    }

    public function test_the_company_logo_replaces_the_empty_corner_in_both_copies(): void
    {
        copy(base_path('public/logo.webp'), $this->logoPath);

        foreach ([$this->screen(), $this->slip()] as $html) {
            $this->assertStringContainsString('plussave-logo.png', $this->headerOf($html));
            $this->assertStringContainsString('alt="Plus Save Financial Solutions"', $html);
            $this->assertStringContainsString('Signature and name, for Plus Save Financial Solutions', $html);
        }
    }

    /**
     * What the customer sees above the corridor title is the header, which is the part that carries
     * the brands. The page title is cut out of it because the browser tab names the company too.
     * A name anywhere below the header belongs to the signing line and does not count.
     */
    private function headerOf(string $html): string
    {
        $body = stripos($html, '<body');
        $this->assertNotFalse($body, 'no body to read the header from');

        $end = strpos($html, 'SEND MONEY TO CHINA', $body);
        $this->assertNotFalse($end, 'no corridor title to cut the header at');

        return substr($html, $body, $end - $body);
    }
}
