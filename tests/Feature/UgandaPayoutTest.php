<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\AccountEntry;
use App\Models\Transaction;
use App\Models\User;
use App\Services\AccountLedger;
use Database\Seeders\PermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The far end of the corridor that starts in China: a desk there books a transfer that promises
 * shillings in Uganda, and those shillings are handed over here. A booking into Uganda carries no
 * crossing stamp, so every completed booking that direction waits in this queue from the day it is
 * taken until the payout pen marks its receiver paid, and the money leaves the office shilling book.
 */
class UgandaPayoutTest extends TestCase
{
    use RefreshDatabase;

    private User $office;
    private User $agent;
    private User $china;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PermissionsSeeder::class);

        $this->office = $this->person('Office Admin', 'admin', 'UG');
        $this->agent = $this->person('Kampala Agent', 'agent', 'UG');
        $this->china = $this->person('China Desk', 'agent', 'CN');
    }

    private function person(string $name, string $role, string $country): User
    {
        $user = User::create([
            'name' => $name,
            'email' => strtolower(str_replace(' ', '.', $name)).'@plussave.test',
            'password' => 'Password123',
            'is_active' => true,
            'country' => $country,
            'timezone' => User::timezoneForCountry($country),
        ]);

        $user->assignRole($role);

        return $user;
    }

    /**
     * A booking taken in China that promises shillings to a receiver in Uganda: yuan handed over
     * there, and a shilling figure waiting to be paid out here.
     */
    private function bookedToUganda(string $receipt, User $creator, float $ugx = 3760000.0): Transaction
    {
        return Transaction::withoutEvents(fn () => Transaction::create([
            'receipt_number' => $receipt,
            'sender_name' => 'Sender '.$receipt,
            'sender_contact' => '',
            'receiver_name' => 'Receiver '.$receipt,
            'receiver_contact' => '',
            'destination' => 'UG',
            'payout_currency' => 'UGX',
            'currency' => 'RMB',
            'amount' => 1000,
            'exchange_rate' => 0,
            'rmb_amount' => 0,
            'total_usd_equivalent' => 0,
            'ugx_amount' => $ugx,
            'charge_rate' => 0,
            'charge_amount' => 0,
            'charge_ugx' => 0,
            'status' => 'completed',
            'fx_profit_ugx' => 0,
            'fx_profit_usd' => 0,
            'usd_rmb_profit_ugx' => 0,
            'usd_rmb_profit_cny' => 0,
            'rate_buy_ugx' => 0,
            'created_by' => $creator->id,
        ]));
    }

    private function shillings(float $amount): void
    {
        AccountLedger::record(
            Account::byCode(Account::UGX_UGANDA),
            'opening_balance',
            number_format($amount, 2, '.', ''),
            'Float for the desk',
        );
    }

    public function test_a_booking_into_uganda_waits_in_the_uganda_queue_until_it_is_paid(): void
    {
        $waiting = $this->bookedToUganda('PS-CN-0001', $this->china);

        $this->assertSame(1, Transaction::awaitingPayoutInUganda()->count());
        $this->assertSame(0, Transaction::awaitingPayoutInChina()->count());

        // A booking the other way never joins this queue, however far it has got, and this queue's
        // rows cannot be cleared through the China payout screen.
        $this->actingAs($this->office)->post('/china/payout', ['transaction_ids' => [$waiting->fresh()->id]])
            ->assertSessionHasErrors('transaction_ids');

        $this->shillings(3760000);

        AccountLedger::payOutReceiver($waiting->fresh(), Account::byCode(Account::UGX_UGANDA));

        $this->assertSame(0, Transaction::awaitingPayoutInUganda()->count());
    }

    public function test_the_uganda_desk_lists_the_queue_and_pays_the_receivers(): void
    {
        $waiting = $this->bookedToUganda('PS-CN-0002', $this->china);
        $this->shillings(3760000);

        $this->actingAs($this->agent)->get('/uganda')
            ->assertOk()
            ->assertSee('Pay receivers in Uganda')
            ->assertSee('PS-CN-0002')
            ->assertSee('UGX 3,760,000');

        $this->actingAs($this->agent)->post('/uganda/payout', [
            'transaction_ids' => [$waiting->fresh()->id],
        ])->assertRedirect(route('uganda.index'))->assertSessionHasNoErrors();

        $paid = $waiting->fresh();

        $this->assertNotNull($paid->delivered_at);
        $this->assertSame('paid', $paid->stage()['key']);

        $entry = AccountEntry::where('transaction_id', $paid->id)->where('type', 'money_out')->first();

        $this->assertNotNull($entry);
        $this->assertSame(Account::byCode(Account::UGX_UGANDA)->id, $entry->account_id);
        $this->assertSame('3760000.00', $entry->amount);
    }

    public function test_the_till_must_hold_the_shillings_before_a_receiver_can_be_marked_paid(): void
    {
        $waiting = $this->bookedToUganda('PS-CN-0003', $this->china);

        $this->actingAs($this->agent)->post('/uganda/payout', [
            'transaction_ids' => [$waiting->fresh()->id],
        ])->assertSessionHasErrors('transaction_ids');

        $this->assertNull($waiting->fresh()->delivered_at);
    }

    public function test_only_a_hand_with_the_payout_pen_opens_or_clears_the_queue(): void
    {
        $waiting = $this->bookedToUganda('PS-CN-0004', $this->china);

        $accountant = $this->person('Grace Naigaga', 'accountant', 'UG');
        $staff = $this->person('Peter Mugisha', 'staff', 'UG');

        $this->actingAs($accountant)->get('/uganda')->assertForbidden();
        $this->actingAs($staff)->get('/uganda')->assertForbidden();

        $this->actingAs($accountant)->post('/uganda/payout', [
            'transaction_ids' => [$waiting->fresh()->id],
        ])->assertForbidden();

        $this->assertNull($waiting->fresh()->delivered_at);
    }

    public function test_the_dashboard_offers_each_desk_its_own_withdrawal_queue(): void
    {
        $this->bookedToUganda('PS-CN-0005', $this->china);

        // A desk in Uganda sees the queue at its own counter and not the China one.
        $this->actingAs($this->agent)->get('/dashboard')
            ->assertOk()
            ->assertSee('Withdraw Money in Uganda')
            ->assertSee('1 receiver waiting to be paid')
            ->assertDontSee('Withdraw Money in China');

        // The China desk keeps its own queue, and the Uganda one is not its tile.
        $this->actingAs($this->china)->get('/dashboard')
            ->assertOk()
            ->assertSee('Withdraw Money in China')
            ->assertDontSee('Withdraw Money in Uganda');

        // Whoever sees the whole book is offered both queues.
        $this->actingAs($this->office)->get('/dashboard')
            ->assertOk()
            ->assertSee('Withdraw Money in China')
            ->assertSee('Withdraw Money in Uganda');
    }
}
