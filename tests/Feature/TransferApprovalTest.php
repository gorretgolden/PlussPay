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
 * The office gaps found on the UGX 10,000 test transfer: nothing completes without an
 * approval, nothing cancels without a reason, and the figures on screen match the books.
 */
class TransferApprovalTest extends TestCase
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

    private function book(User $booker, string $amount = '10000'): Transaction
    {
        $this->actingAs($booker)->get('/transactions/create');
        $this->actingAs($booker)->post('/transactions', [
            'form_id' => session('transaction_form_id'),
            'sender_name' => 'Approval Sender',
            'receiver_name' => 'Approval Receiver',
            'currency' => 'UGX',
            'amount' => $amount,
        ])->assertSessionHasNoErrors();

        return Transaction::latest('id')->firstOrFail();
    }

    public function test_a_transfer_booked_by_an_admin_waits_for_approval_and_moves_no_cash(): void
    {
        $admin = $this->user('admin');
        $transaction = $this->book($admin);

        $this->assertSame('pending', $transaction->status);
        $this->assertSame('0.00', Account::byCode(Account::UGX_UGANDA)->balance());
        $this->assertSame(0, AccountEntry::count());
    }

    public function test_approving_posts_the_principal_and_the_charge_to_the_till(): void
    {
        $admin = $this->user('admin');
        $transaction = $this->book($admin);

        $this->actingAs($admin)->patch("/transactions/{$transaction->id}/status", ['status' => 'completed'])
            ->assertSessionHasNoErrors();

        // UGX 10,000 handed over plus the UGX 50 charge on the $2.50 it converts to.
        $this->assertSame('10050.00', Account::byCode(Account::UGX_UGANDA)->balance());
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

        $this->assertSame('pending', $transaction->fresh()->status);
    }

    public function test_cancelling_a_completed_transfer_returns_the_money_it_took_in(): void
    {
        $admin = $this->user('admin');
        $transaction = $this->book($admin);
        $this->actingAs($admin)->patch("/transactions/{$transaction->id}/status", ['status' => 'completed']);

        $this->actingAs($admin)->patch("/transactions/{$transaction->id}/status", [
            'status' => 'cancelled',
            'reason' => 'Customer changed their mind',
        ])->assertSessionHasNoErrors();

        $this->assertSame('0.00', Account::byCode(Account::UGX_UGANDA)->balance());
        $this->assertSame(1, AccountEntry::where('transaction_id', $transaction->id)->where('type', 'reversal')->count());
    }

    public function test_the_approvals_desk_signs_off_several_transfers_at_once(): void
    {
        $admin = $this->user('admin');
        $ids = collect([$this->book($admin), $this->book($admin, '20000'), $this->book($admin, '30000')])->pluck('id');

        $this->actingAs($admin)->get('/approvals')
            ->assertOk()
            ->assertSee('Customer Pays');

        $this->actingAs($admin)->post('/approvals/bulk', ['ids' => $ids->all()])
            ->assertSessionHasNoErrors();

        $this->assertSame(0, Transaction::where('status', 'pending')->count());

        // 10,000 + 20,000 + 30,000 of principal plus 50 + 100 + 150 of charge.
        $this->assertSame('60300.00', Account::byCode(Account::UGX_UGANDA)->balance());
        $this->assertSame(3, AccountEntry::where('type', 'money_in')->count());
    }

    public function test_only_admins_reach_the_approvals_desk(): void
    {
        $agent = $this->user('agent');
        $transaction = $this->book($agent);

        $this->actingAs($agent)->get('/approvals')->assertForbidden();
        $this->actingAs($agent)->post('/approvals/bulk', ['ids' => [$transaction->id]])->assertForbidden();

        $this->assertSame('pending', $transaction->fresh()->status);
    }

    public function test_the_unpriced_warning_counts_transfers_recorded_with_a_zero_buying_rate(): void
    {
        $admin = $this->user('admin');

        // One booked before the buying rate columns existed, one booked while only the
        // selling rate was set, which saves the buying rate as zero rather than blank.
        $older = $this->book($admin);
        $older->update(['status' => 'completed', 'rate_buy_ugx' => null]);

        $newer = $this->book($admin, '20000');
        $newer->update(['status' => 'completed', 'rate_buy_ugx' => 0, 'fx_profit_ugx' => 0, 'fx_profit_usd' => 0]);

        $this->actingAs($admin)
            ->get('/reports?type=profit')
            ->assertOk()
            ->assertSee('2 completed transaction(s)')
            ->assertSee($newer->receipt_number);
    }

    public function test_the_completed_today_figure_ignores_transfers_that_were_cancelled(): void
    {
        $admin = $this->user('admin');

        $kept = $this->book($admin);
        $this->actingAs($admin)->patch("/transactions/{$kept->id}/status", ['status' => 'completed']);

        $dropped = $this->book($admin, '20000');
        $this->actingAs($admin)->patch("/transactions/{$dropped->id}/status", ['status' => 'completed']);
        $this->actingAs($admin)->patch("/transactions/{$dropped->id}/status", [
            'status' => 'cancelled',
            'reason' => 'Booked by mistake',
        ]);

        $this->actingAs($admin)->get('/dashboard')
            ->assertOk()
            ->assertSee('Completed Today')
            ->assertDontSee('Transactions Today')
            ->assertViewHas('stats', fn (array $stats) => (int) $stats['today_transactions'] === 1
                && (int) $stats['cancelled_transactions'] === 1);
    }
}
