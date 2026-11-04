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
 * The board's own list of the day's bookings is the first thing a desk reads when it wants to know
 * what happened to the cash it took in. She asked for it to stand in one column from the left like
 * the rest of the page, and for the stamp on a row to say where the money has got to rather than
 * that the booking was keyed in.
 */
class DashboardRecentTransfersTest extends TestCase
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

    private function agent(string $name = 'Sarah Nalin'): User
    {
        $user = User::create([
            'name' => $name,
            'email' => strtolower(str_replace(' ', '.', $name)).uniqid().'@plussave.test',
            'password' => 'Password123',
            'is_active' => true,
            'country' => 'UG',
        ]);
        $user->assignRole('agent');

        return $user;
    }

    private function booking(User $creator, string $receipt): Transaction
    {
        return Transaction::create([
            'receipt_number' => $receipt,
            'sender_name' => 'Sender '.$receipt,
            'sender_contact' => '0700000001',
            'receiver_name' => 'Receiver '.$receipt,
            'receiver_contact' => '8613800000001',
            'currency' => 'UGX',
            'amount' => '480000',
            'exchange_rate' => 7.24,
            'rmb_amount' => '3475200.00',
            'charge_rate' => 0.02,
            'charge_amount' => 2,
            'charge_ugx' => '20000',
            'status' => 'completed',
            'created_by' => $creator->id,
        ]);
    }

    private function board(User $as): string
    {
        $this->flushSession();

        return $this->actingAs($as)->get('/dashboard')->assertOk()->getContent();
    }

    /**
     * The board's own list, cut out of the page. The summary strip above it carries a Completed
     * count as its own business, so a question about the rows may not answer itself from the card.
     */
    private function rows(string $html): string
    {
        $from = strpos($html, '<table class="w-full min-w-[720px]');
        $to = strpos($html, '</table>', $from);

        return $from === false || $to === false ? '' : substr($html, $from, $to - $from);
    }

    public function test_the_money_on_the_board_lines_up_with_the_names_beside_it(): void
    {
        $agent = $this->agent();
        $this->booking($agent, 'PS-20261104-0301');

        $table = $this->rows($this->board($agent));

        $this->assertStringContainsString('Amount', $table, 'The list was cut out, not picked up from elsewhere.');
        $this->assertStringNotContainsString('text-right text-xs font-medium text-gray-500 uppercase tracking-wide whitespace-nowrap">Amount', $table);
        $this->assertStringNotContainsString('text-right text-xs font-medium text-gray-500 uppercase tracking-wide whitespace-nowrap">Paid out', $table);
        $this->assertStringNotContainsString('text-right', $table, 'Nothing in the list is pushed against its right edge.');
    }

    public function test_a_row_says_where_its_money_has_got_to_rather_than_that_it_was_booked(): void
    {
        $agent = $this->agent('Grace Okello');
        $booking = $this->booking($agent, 'PS-20261104-0302');

        // The counter has the cash and China has nothing yet, which is exactly the row she asked
        // about: Completed on a board where the receiver is still waiting reads as a wrong book.
        $this->assertStringContainsString('>Cash taken</span>', $this->rows($this->board($agent)));
        $this->assertStringNotContainsString('Completed', $this->rows($this->board($agent)));
        $this->assertSame(2, substr_count($this->board($agent), '>Cash taken</span>'), 'The row and its phone card carry the one stamp.');

        $booking->forceFill(['settled_at' => now()])->save();
        $this->assertStringContainsString('>Waiting in China</span>', $this->rows($this->board($agent)));

        $booking->forceFill(['delivered_at' => now()])->save();
        $this->assertStringContainsString('>Paid</span>', $this->rows($this->board($agent)));
    }
}
