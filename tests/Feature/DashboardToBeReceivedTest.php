<?php

namespace Tests\Feature;

use App\Models\ExchangeRate;
use App\Models\Transaction;
use App\Models\User;
use App\Support\PermissionCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * The Overview figure for the money still to settle: pending bookings summed, stamped with the age
 * of the newest one. It sits beside Amount sent so the two never disagree, and it appears in all
 * three windows. It used to be called "To Be Received" under the line "money to be sent", which
 * named the opposite direction from the figure beside it.
 */
class DashboardToBeReceivedTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (PermissionCatalog::names() as $name) {
            Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
        }

        foreach (array_keys(PermissionCatalog::defaults()) as $role) {
            Role::firstOrCreate(['name' => $role, 'guard_name' => 'web'])
                ->syncPermissions(PermissionCatalog::defaults()[$role]);
        }

        ExchangeRate::setPair('USD', 'UGX', 3760, 3750, 3760);
        ExchangeRate::setPair('USD', 'RMB', 7.24, 7.20, 7.24);
    }

    private function user(string $role): User
    {
        $user = User::create([
            'name' => ucfirst($role).' Viewer',
            'email' => strtolower($role).uniqid().'@plussave.test',
            'password' => 'Password123',
            'is_active' => true,
        ]);
        $user->assignRole($role);

        return $user;
    }

    private function pendingBooking(User $creator, string $receipt, array $over): Transaction
    {
        return Transaction::create(array_merge([
            'receipt_number' => $receipt,
            'sender_name' => 'Sender '.$receipt,
            'sender_contact' => '0700000001',
            'receiver_name' => 'Receiver '.$receipt,
            'receiver_contact' => '8613800000001',
            'currency' => 'UGX',
            'amount' => '300000',
            'exchange_rate' => 6.75,
            'rmb_amount' => '2025000',
            'charge_rate' => 0.02,
            'charge_amount' => 2,
            'charge_ugx' => '10000',
            'status' => 'pending',
            'created_by' => $creator->id,
        ], $over));
    }

    private function dashboardHtml(User $viewer): string
    {
        $this->flushSession();

        return $this->actingAs($viewer)->get('/dashboard')->assertOk()->getContent();
    }

    public function test_pending_bookings_sum_onto_the_settling_figure_in_every_window(): void
    {
        $agent = $this->user('agent');
        $admin = $this->user('admin');

        // 300,000 shillings plus a 10,000 charge handed over but not yet sent, plus a dollar
        // booking priced at 3760 shillings with no charge of its own, so the two currencies
        // read off one UGX figure on the card. Principal and charge cross together.
        $this->pendingBooking($agent, 'PS-WAIT-1', []);
        $this->pendingBooking($agent, 'PS-WAIT-2', [
            'currency' => 'USD',
            'amount' => '100',
            'total_usd_equivalent' => '100',
            'rate_sell_ugx' => '3760',
            'rmb_amount' => '724',
            'charge_rate' => 0,
            'charge_amount' => 0,
            'charge_ugx' => '0',
        ]);

        // A completed booking never leaks into the waiting pile.
        $this->pendingBooking($agent, 'PS-WAIT-3', ['status' => 'completed']);

        $html = $this->dashboardHtml($admin);

        $this->assertSame(3, substr_count($html, 'Money still to settle'), 'the figure sits in all three windows.');
        $this->assertSame(3, substr_count($html, '>2 bookings open<'), 'the note counts the bookings still open.');
        $this->assertSame(3, substr_count($html, 'UGX 686,000'), 'principal and charge from both currencies, completed rows left out.');
        // Each window's card carries the age of its newest waiting booking.
        $this->assertSame(
            3,
            preg_match_all('/bookings open<\/div>\s*<p class="[^"]*">\s*<time [^>]*>Updated \d+ (second|minute|hour|day)s? ago/', $html),
            'a stamp sits inside the card in every window.'
        );
    }

    public function test_an_empty_pile_reads_zero_and_gets_no_stamp(): void
    {
        $admin = $this->user('admin');

        $html = $this->dashboardHtml($admin);

        $this->assertSame(3, substr_count($html, 'Money still to settle'));
        $this->assertSame(
            0,
            preg_match_all('/nothing open<\/div>\s*<p class="[^"]*">\s*<time /', $html),
            'a zero pile has no movement to stamp.'
        );

        preg_match_all('/Money still to settle<\/div>\s*<div class="stat-value[^"]*"[^>]*>([^<]+)<\/div>/', $html, $values);
        $this->assertSame(3, count($values[1]));
        foreach ($values[1] as $value) {
            $this->assertSame('UGX 0', $value);
        }
    }
}
