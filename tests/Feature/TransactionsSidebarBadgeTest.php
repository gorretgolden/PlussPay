<?php

namespace Tests\Feature;

use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * The Transactions link in the sidebar carries the number of bookings taken today, in green.
 * It replaced the yellow unread-notices badge there: the day's traffic is not news, and the
 * What Happened link still carries the unread total.
 */
class TransactionsSidebarBadgeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['superadmin', 'admin', 'agent'] as $role) {
            Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        }
    }

    private function desk(string $role, string $name = 'Teller'): User
    {
        $user = User::create([
            'name' => $name,
            'email' => strtolower($role).'.'.uniqid().'@plussave.test',
            'password' => 'Password123',
            'is_active' => true,
        ]);
        $user->assignRole($role);

        return $user;
    }

    private function booking(User $creator, int $n, ?string $createdAt = null): Transaction
    {
        return Transaction::create([
            'receipt_number' => 'PS-BADGE-'.uniqid(),
            'sender_name' => 'Sender '.$n,
            'sender_contact' => '070000000'.$n,
            'receiver_name' => 'Receiver '.$n,
            'receiver_contact' => '861380000000'.$n,
            'currency' => 'UGX',
            'amount' => '300000',
            'exchange_rate' => 6.75,
            'rmb_amount' => bcmul('300000', '6.75', 2),
            'charge_ugx' => '10000',
            'status' => 'completed',
            'created_by' => $creator->id,
            'created_at' => $createdAt,
        ]);
    }

    private function board(User $user): string
    {
        return $this->actingAs($user)->get('/dashboard')->getContent();
    }

    public function test_the_transactions_link_counts_the_bookings_taken_today(): void
    {
        $boss = $this->desk('superadmin');
        $agent = $this->desk('agent', 'Amina');

        $this->booking($agent, 1);
        $this->booking($agent, 2);

        $html = $this->board($boss);

        $this->assertSame(1, substr_count($html, 'title="2 bookings today"'));
        $this->assertStringContainsString('>2</span>', $html);
        $this->assertStringNotContainsString('bg-yellow-300', $html, 'The day count is green; the news yellow stays off this link.');
    }

    public function test_the_badge_counts_only_what_the_reader_may_see(): void
    {
        $boss = $this->desk('superadmin');
        $amina = $this->desk('agent', 'Amina');
        $betty = $this->desk('agent', 'Betty');

        $this->booking($amina, 1);
        $this->booking($betty, 1);
        $this->booking($betty, 2);

        $this->assertStringContainsString('title="3 bookings today"', $this->board($boss));
        $this->assertStringContainsString('title="1 booking today"', $this->board($amina));
        $this->assertStringContainsString('title="2 bookings today"', $this->board($betty));
    }

    public function test_no_badge_when_the_day_has_no_bookings(): void
    {
        $boss = $this->desk('superadmin');
        $agent = $this->desk('agent', 'Amina');

        $this->booking($agent, 1, now()->subDay()->toDateTimeString());

        $html = $this->board($boss);

        $this->assertStringNotContainsString('bookings today', $html, "Yesterday's booking is not the day's traffic.");
    }
}
