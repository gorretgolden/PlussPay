<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\AccountEntry;
use App\Models\ExchangeRate;
use App\Models\Setting;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * The office gaps found on the UGX 10,000 test transfer: a booking is finished as it is
 * entered, nothing cancels without a reason, and the figures on screen match the books.
 */
class TransferFlowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['superadmin', 'admin', 'agent', 'staff'] as $role) {
            Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        }

        ExchangeRate::create(['from_currency' => 'USD', 'to_currency' => 'RMB', 'rate' => 6.56, 'is_active' => true]);
        ExchangeRate::create(['from_currency' => 'USD', 'to_currency' => 'UGX', 'rate' => 4000, 'buy_rate' => 3990, 'sell_rate' => 4000, 'is_active' => true]);

        Setting::set('service_fee_ugx', '2000', 'float', 'transaction');
        Setting::set('service_fee_usd', '100', 'float', 'transaction');
    }

    private function user(string $role): User
    {
        $user = User::create([
            'name' => ucfirst($role) . ' One',
            'email' => strtolower($role) . uniqid() . '@plussave.test',
            'password' => 'Password123',
            'is_active' => true,
        ]);

        $user->assignRole($role);

        return $user;
    }

    private function book(User $booker, string $amount = '10000', string $charge = '50'): Transaction
    {
        $this->actingAs($booker)->get('/transactions/create');
        $this->actingAs($booker)->post('/transactions', [
            'form_id' => session('transaction_form_id'),
            'sender_name' => 'Flow Sender',
            'receiver_name' => 'Flow Receiver',
            'currency' => 'UGX',
            'amount' => $amount,
            'charge_ugx' => $charge,
        ])->assertSessionHasNoErrors();

        return Transaction::latest('id')->firstOrFail();
    }

    public function test_a_booked_transfer_is_finished_at_once_and_puts_the_money_in_the_till(): void
    {
        $admin = $this->user('admin');
        $transaction = $this->book($admin);

        $this->assertSame('completed', $transaction->status);
        // The whole UGX 10,000 handed over reaches the till: UGX 50 of it is the charge and the
        // remaining UGX 9,950 is the money that travels.
        $this->assertSame('9950.00', $transaction->amount);
        $this->assertSame('10000.00', Account::byCode(Account::UGX_UGANDA)->balance());
        $this->assertSame(1, AccountEntry::where('transaction_id', $transaction->id)->where('type', 'money_in')->count());
    }

    public function test_a_transfer_left_over_from_the_old_pending_list_can_still_be_completed(): void
    {
        $admin = $this->user('admin');
        $transaction = $this->book($admin);
        $transaction->update(['status' => 'pending']);

        $this->actingAs($admin)->patch("/transactions/{$transaction->id}/status", ['status' => 'completed'])
            ->assertSessionHasNoErrors();

        $this->assertSame('completed', $transaction->fresh()->status);
        $this->assertDatabaseHas('activity_logs', [
            'transaction_id' => $transaction->id,
            'action' => 'approved',
        ]);
    }

    public function test_a_cancelled_transfer_is_logged_as_cancelled_with_its_reason(): void
    {
        $admin = $this->user('admin');
        $transaction = $this->book($admin);

        $this->actingAs($admin)->patch("/transactions/{$transaction->id}/status", [
            'status' => 'cancelled',
            'reason' => 'Cash was never handed over',
        ])->assertSessionHasNoErrors();

        $this->assertSame('cancelled', $transaction->fresh()->status);
        $this->assertDatabaseHas('activity_logs', [
            'transaction_id' => $transaction->id,
            'action' => 'cancelled',
        ]);
        $this->assertStringContainsString(
            'Cash was never handed over',
            $transaction->activityLogs()->where('action', 'cancelled')->value('description')
        );
        $this->assertDatabaseMissing('activity_logs', ['transaction_id' => $transaction->id, 'action' => 'rejected']);
    }

    public function test_cancelling_without_a_reason_is_refused_and_leaves_the_transfer_alone(): void
    {
        $admin = $this->user('admin');
        $transaction = $this->book($admin);

        $this->actingAs($admin)->patch("/transactions/{$transaction->id}/status", ['status' => 'cancelled'])
            ->assertSessionHasErrors('reason');

        $this->assertSame('completed', $transaction->fresh()->status);
        $this->assertSame('10000.00', Account::byCode(Account::UGX_UGANDA)->balance());
    }

    public function test_cancelling_a_completed_transfer_returns_the_money_it_took_in(): void
    {
        $admin = $this->user('admin');
        $transaction = $this->book($admin);

        $this->actingAs($admin)->patch("/transactions/{$transaction->id}/status", [
            'status' => 'cancelled',
            'reason' => 'Customer changed their mind',
        ])->assertSessionHasNoErrors();

        $this->assertSame('0.00', Account::byCode(Account::UGX_UGANDA)->balance());
        $this->assertSame(1, AccountEntry::where('transaction_id', $transaction->id)->where('type', 'reversal')->count());
    }

    public function test_the_unpriced_warning_counts_transfers_recorded_with_a_zero_buying_rate(): void
    {
        $admin = $this->user('admin');

        // One booked before the buying rate columns existed, one booked while only the
        // selling rate was set, which saves the buying rate as zero rather than blank.
        $older = $this->book($admin);
        $older->update(['rate_buy_ugx' => null]);

        $newer = $this->book($admin, '20000');
        $newer->update(['rate_buy_ugx' => 0, 'fx_profit_ugx' => 0, 'fx_profit_usd' => 0]);

        $this->actingAs($admin)
            ->get('/reports?type=profit')
            ->assertOk()
            ->assertSee('2 completed transfers were booked before their buying rates were stored')
            ->assertSee($newer->receipt_number);
    }

    public function test_the_completed_today_figure_ignores_transfers_that_were_cancelled(): void
    {
        $admin = $this->user('admin');

        $this->book($admin);
        $dropped = $this->book($admin, '20000');
        $this->actingAs($admin)->patch("/transactions/{$dropped->id}/status", [
            'status' => 'cancelled',
            'reason' => 'Booked by mistake',
        ]);

        $this->actingAs($admin)->get('/dashboard')
            ->assertOk()
            ->assertSee('Today')
            ->assertDontSee('Transactions Today')
            // The board now reads one window at a time, so the completed count lives on the
            // Today panel instead of under its own headline.
            ->assertViewHas('windows', fn (array $w) => $w['today']['completed'] === 1
                && $w['today']['cancelled'] === 1
                && $w['all']['cancelled'] === 1);
    }
}
