<?php

namespace Tests\Feature;

use App\Models\ExchangeRate;
use App\Models\Setting;
use App\Models\Transaction;
use App\Models\User;
use App\Support\PermissionCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Whose money a board is allowed to add up.
 *
 * The figures themselves are covered by DashboardMatchesReportsTest and DashboardProfitTest; this
 * is about reach. A desk reads its own book, the office reads every book, and the day the board
 * calls Today is the day the booking was typed in.
 */
class DashboardAgentReachTest extends TestCase
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
        Setting::set('default_charge_ugx', '20000', 'float', 'transaction');
        Setting::set('min_transaction_amount', '1000', 'float', 'transaction');
    }

    private function desk(string $role, string $name): User
    {
        $user = User::create([
            'name' => $name,
            'email' => strtolower(str_replace(' ', '.', $name)).'@plussave.test',
            'password' => 'Password123',
            'is_active' => true,
            'country' => 'UG',
        ]);
        $user->assignRole($role);

        return $user;
    }

    private function booked(User $creator, string $handedOver, string $charge): Transaction
    {
        $this->actingAs($creator)->get('/transactions/create');

        $this->actingAs($creator)->post('/transactions', [
            'form_id' => session('transaction_form_id'),
            'agent_id' => 'counter',
            'sender_name' => 'Sender '.$creator->id,
            'receiver_name' => 'Receiver '.$creator->id,
            'currency' => 'UGX',
            'amount' => $handedOver,
            'charge_ugx' => $charge,
            'payment_method' => 'Cash',
        ])->assertSessionHasNoErrors();

        return Transaction::latest('id')->firstOrFail();
    }

    private function todayCards(User $reader): string
    {
        $html = $this->actingAs($reader)->get('/dashboard')->assertOk()->getContent();
        $start = strpos($html, 'id="panel-today"');
        $end = strpos($html, 'id="panel-month"', $start);

        return preg_replace('/\s+/', ' ', strip_tags(substr($html, $start, $end - $start)));
    }

    public function test_a_booking_moves_the_board_that_booked_it_the_moment_it_is_saved(): void
    {
        $faith = $this->desk('agent', 'Faith Nansere');

        $first = $this->booked($faith, '480000', '20000');
        $cards = $this->todayCards($faith);

        $this->assertStringContainsString('Transfers completed 1', $cards);
        $this->assertStringContainsString('Amount sent UGX 460,000', $cards);
        $this->assertStringContainsString('Charge collected UGX 20,000', $cards);
        $this->assertStringContainsString('¥885.7', $cards, 'The yuan the row promised at its own rate.');
        $this->assertSame(460000.0, (float) $first->amount);

        $this->booked($faith, '190000', '10000');
        $cards = $this->todayCards($faith);

        $this->assertStringContainsString('Transfers completed 2', $cards);
        $this->assertStringContainsString('Amount sent UGX 640,000', $cards, '1,200,000 handed over less 30,000 of charges.');
        $this->assertStringContainsString('Charge collected UGX 30,000', $cards);
    }

    public function test_a_desk_board_never_carries_another_desks_money(): void
    {
        $faith = $this->desk('agent', 'Faith Nansere');
        $amina = $this->desk('agent', 'Amina Nabukenya');

        $this->booked($faith, '480000', '20000');
        $this->booked($amina, '760000', '40000');

        $this->assertStringContainsString('Amount sent UGX 460,000', $this->todayCards($faith));
        $this->assertStringContainsString('Amount sent UGX 720,000', $this->todayCards($amina));

        // The office keeps every book, so its board is the sum of both.
        $office = $this->desk('admin', 'Grace Naigaga');
        $this->assertStringContainsString('Transfers completed 2', $this->todayCards($office));
        $this->assertStringContainsString('Amount sent UGX 1,180,000', $this->todayCards($office));
    }

    public function test_a_board_only_cuts_a_booking_into_today_on_the_day_it_was_typed_in(): void
    {
        $faith = $this->desk('agent', 'Faith Nansere');
        $booking = $this->booked($faith, '480000', '20000');
        $booking->forceFill(['created_at' => now()->subDays(9)])->save();

        $cards = $this->todayCards($faith);

        // The panels print in tab order, so the old booking has to be missing from Today and
        // present further along the same text.
        $today = strpos($cards, 'Transfers completed 0 Amount sent UGX 0');
        $all = $this->allTimeCards($faith);
        $this->assertNotFalse($today, 'Nothing of theirs today.');
        $this->assertStringContainsString('Transfers completed 1', $all, 'Nine days ago still counts as all time.');

        // The booking keeps the prices it was sold at, so the yuan on the board is those prices.
        $this->assertStringContainsString('¥885.7', $all);
    }

    private function allTimeCards(User $reader): string
    {
        $html = $this->actingAs($reader)->get('/dashboard')->assertOk()->getContent();
        $start = strpos($html, 'id="panel-all"');

        return preg_replace('/\s+/', ' ', strip_tags(substr($html, $start, 6000)));
    }

    public function test_a_cancelled_booking_is_counted_by_status_and_left_out_of_the_money(): void
    {
        $faith = $this->desk('agent', 'Faith Nansere');
        $booking = $this->booked($faith, '480000', '20000');
        $this->booked($faith, '190000', '10000');

        $booking->update(['status' => 'cancelled']);

        $cards = $this->todayCards($faith);

        $this->assertStringContainsString('Transfers completed 1', $cards);
        $this->assertStringContainsString('Amount sent UGX 180,000', $cards);
        $this->assertStringNotContainsString('460,000', $cards, 'The cancelled money is off the board entirely.');
    }
}
