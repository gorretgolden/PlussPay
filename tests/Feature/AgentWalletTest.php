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

class AgentWalletTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['superadmin', 'admin', 'agent', 'staff'] as $role) {
            Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        }

        ExchangeRate::create(['from_currency' => 'USD', 'to_currency' => 'RMB', 'rate' => 6.75, 'buy_rate' => 6.75, 'sell_rate' => 6.75, 'is_active' => true]);
        ExchangeRate::create(['from_currency' => 'USD', 'to_currency' => 'UGX', 'rate' => 3760, 'buy_rate' => 3750, 'sell_rate' => 3760, 'is_active' => true]);

        Setting::set('service_fee_ugx', '2000', 'float', 'transaction');
        Setting::set('service_fee_usd', '100', 'float', 'transaction');
    }

    private function agent(): User
    {
        return $this->namedAgent('Test Agent');
    }

    private function namedAgent(string $name, string $country = 'UG'): User
    {
        $user = User::create([
            'name' => $name,
            'email' => 'agent'.uniqid().'@plussave.test',
            'password' => 'Password123',
            'is_active' => true,
            'country' => $country,
        ]);

        $user->assignRole('agent');

        return $user;
    }

    private function admin(): User
    {
        $user = User::create([
            'name' => 'Admin User',
            'email' => 'admin'.uniqid().'@plussave.test',
            'password' => 'Password123',
            'is_active' => true,
        ]);

        $user->assignRole('admin');

        return $user;
    }

    public function test_wallet_created_on_demand_for_agent(): void
    {
        $agent = $this->agent();

        $wallet = Account::walletFor($agent, 'UGX');

        $this->assertNotNull($wallet->owner_id);
        $this->assertEquals($agent->id, $wallet->owner_id);
        $this->assertEquals('UGX', $wallet->currency);
        $this->assertTrue($wallet->isWallet());
    }

    public function test_a_desk_in_uganda_holds_shillings_and_dollars_only(): void
    {
        $agent = $this->agent();

        $wallets = Account::walletsFor($agent);

        $this->assertSame(['UGX', 'USD'], array_keys($wallets));
        $this->assertSame('UG', $wallets['UGX']->country);
    }

    public function test_a_desk_in_china_holds_yuan_and_dollars_and_no_shilling_wallet(): void
    {
        $agent = $this->namedAgent('China Agent', 'CN');

        $wallets = Account::walletsFor($agent);

        $this->assertSame(['RMB', 'USD'], array_keys($wallets));
        $this->assertSame('CN', $wallets['RMB']->country);
    }

    public function test_completed_transfer_deposits_fee_into_agent_wallet(): void
    {
        $agent = $this->agent();

        $transaction = Transaction::create([
            'receipt_number' => 'WAL-001',
            'sender_name' => 'Sender One',
            'sender_contact' => '0700000001',
            'receiver_name' => 'Receiver One',
            'receiver_contact' => '8613800000001',
            'currency' => 'USD',
            'amount' => 100,
            'exchange_rate' => 6.75,
            'rmb_amount' => 675,
            'charge_rate' => 0.02,
            'charge_amount' => 2,
            'charge_ugx' => 7520,
            'status' => 'completed',
            'created_by' => $agent->id,
        ]);

        $wallet = Account::walletFor($agent, 'UGX');

        $feeEntry = AccountEntry::where('account_id', $wallet->id)
            ->where('type', 'agent_fee')
            ->where('transaction_id', $transaction->id)
            ->first();

        $this->assertNotNull($feeEntry);
        $this->assertEquals('7520.00', (string) $feeEntry->amount);
        $this->assertEquals('in', $feeEntry->direction);
    }

    public function test_cancelled_transfer_reverses_agent_fee(): void
    {
        $agent = $this->agent();

        $transaction = Transaction::create([
            'receipt_number' => 'WAL-002',
            'sender_name' => 'Sender Two',
            'sender_contact' => '0700000002',
            'receiver_name' => 'Receiver Two',
            'receiver_contact' => '8613800000002',
            'currency' => 'USD',
            'amount' => 200,
            'exchange_rate' => 6.75,
            'rmb_amount' => 1350,
            'charge_rate' => 0.02,
            'charge_amount' => 4,
            'charge_ugx' => 15040,
            'status' => 'completed',
            'created_by' => $agent->id,
        ]);

        $transaction->update(['status' => 'cancelled']);

        $wallet = Account::walletFor($agent, 'UGX');

        $reversal = AccountEntry::where('account_id', $wallet->id)
            ->where('type', 'agent_fee_reversal')
            ->where('transaction_id', $transaction->id)
            ->first();

        $this->assertNotNull($reversal);
        $this->assertEquals('15040.00', (string) $reversal->amount);
        $this->assertEquals('out', $reversal->direction);
    }

    public function test_agent_can_see_own_wallet_page(): void
    {
        $agent = $this->agent();

        $response = $this->actingAs($agent)->get(route('wallet.index'));

        $response->assertOk();
        $response->assertSee('My Wallet');
    }

    public function test_admin_sees_all_agent_wallets(): void
    {
        $agent = $this->agent();
        $admin = $this->admin();

        Account::walletsFor($agent);

        $response = $this->actingAs($admin)->get(route('wallet.index'));

        $response->assertOk();
        $response->assertSee('Agent Wallets');
        $response->assertSee($agent->name);
        // The country is on the row so an empty yuan column reads as "not their currency".
        $response->assertSee('Uganda');
        $response->assertSee('RMB in hand');
        // The yuan cell is a dash, not a zero balance, because this desk holds no yuan.
        $this->assertStringNotContainsString('&yen;', $response->getContent());
    }

    public function test_a_china_desk_shows_a_yuan_balance_and_no_shillings(): void
    {
        $agent = $this->namedAgent('Mei Lin', 'CN');
        $admin = $this->admin();

        $response = $this->actingAs($admin)->get(route('wallet.index'));

        $response->assertOk();
        $response->assertSee('China');
        // A yuan balance reaches the browser as a symbol, not as the letters that name it.
        $this->assertStringContainsString('&yen;0.00', $response->getContent());
        $this->assertStringNotContainsString('&amp;yen;', $response->getContent());
    }

    public function test_dashboard_shows_wallet_balances(): void
    {
        $agent = $this->agent();

        $response = $this->actingAs($agent)->get(route('dashboard'));

        $response->assertOk();
        $response->assertSee('UGX Wallet');
        $response->assertSee('USD Wallet');
        // An empty yuan account on a Uganda desk is noise, not a balance to check.
        $response->assertDontSee('RMB Wallet');
    }

    public function test_a_china_dashboard_shows_yuan_and_never_shillings(): void
    {
        $agent = $this->namedAgent('Mei Lin', 'CN');

        $response = $this->actingAs($agent)->get(route('dashboard'));

        $response->assertOk();
        $response->assertSee('RMB Wallet');
        $response->assertSee('USD Wallet');
        $response->assertDontSee('UGX Wallet');
    }

    public function test_company_accounts_are_not_wallets(): void
    {
        $company = Account::byCode(Account::UGX_UGANDA);

        $this->assertFalse($company->isWallet());
        $this->assertNull($company->owner_id);
    }

    private function booking(User $creator, string $receipt, string $fee): Transaction
    {
        return Transaction::create([
            'receipt_number' => $receipt,
            'sender_name' => 'Sender '.$receipt,
            'sender_contact' => '0700000009',
            'receiver_name' => 'Receiver '.$receipt,
            'receiver_contact' => '8613800000009',
            'currency' => 'USD',
            'amount' => 100,
            'exchange_rate' => 6.75,
            'rmb_amount' => 675,
            'charge_rate' => 0.02,
            'charge_amount' => 2,
            'charge_ugx' => $fee,
            'status' => 'completed',
            'created_by' => $creator->id,
        ]);
    }

    public function test_the_commission_report_lists_who_kept_a_commission(): void
    {
        $agent = $this->namedAgent('Sarah Nalin');
        $staff = User::create([
            'name' => 'Desk Staff',
            'email' => 'desk'.uniqid().'@plussave.test',
            'password' => 'Password123',
            'is_active' => true,
        ]);
        $staff->assignRole('staff');

        // The agent held that 7,520 in their own hand, so it is a commission line in a wallet and the
        // report reads it from the ledger. The charge on the desk booking went into the till with the
        // rest of the cash the customer handed over, so it is office revenue and nobody's commission.
        $this->booking($agent, 'COM-001', '7520');
        $this->booking($staff, 'COM-002', '15040');

        $page = $this->actingAs($this->admin())
            ->get('/reports?type=commission')
            ->assertOk()
            ->getContent();

        // Every section lives on the one page, so the proof has to read the commission table itself
        // rather than search the whole document for a figure another table also carries.
        $from = strpos($page, 'id="section-commission"');
        $to = strpos($page, 'id="section-', $from + 20);
        $commission = substr($page, $from, $to - $from);

        $this->assertStringContainsString('Commissions Earned', $commission);
        $this->assertStringContainsString('Given Back (UGX)', $commission);
        $this->assertStringContainsString('Sarah Nalin', $commission);
        $this->assertStringContainsString('7,520', $commission);
        $this->assertStringNotContainsString('Desk Staff', $commission);
        $this->assertStringNotContainsString('15,040', $commission);
    }

    public function test_an_agent_cannot_open_the_commission_report_but_sees_his_own_on_his_wallet(): void
    {
        $mine = $this->namedAgent('Sarah Nalin');
        $theirs = $this->namedAgent('Peter Okello');

        $this->booking($mine, 'COM-003', '7520');
        $this->booking($theirs, 'COM-004', '15040');

        // The commission table is an office view of every desk, so the page itself is shut rather
        // than opened and emptied. What the agent is owed is on his own wallet summary instead.
        $this->actingAs($mine)->get('/reports?type=commission')->assertForbidden();

        $this->actingAs($mine)
            ->get('/reports?type=wallet')
            ->assertOk()
            ->assertSee('My Wallet Summary')
            ->assertSee('UGX 7,520')
            ->assertDontSee('Peter Okello')
            ->assertDontSee('15,040');
    }

    public function test_a_cancelled_transfer_gives_its_commission_back_on_the_report(): void
    {
        $agent = $this->agent();

        $this->booking($agent, 'COM-005', '7520');
        $dropped = $this->booking($agent, 'COM-006', '15040');
        $dropped->update(['status' => 'cancelled']);

        // Both fees were earned, 15,040 of it handed back when the transfer was dropped. The office
        // report shows the earning and the giving back as two columns; the agent's own wallet only
        // ever carries what he is still holding, which is the net.
        $page = $this->actingAs($this->admin())
            ->get('/reports?type=commission')
            ->assertOk()
            ->getContent();

        $from = strpos($page, 'id="section-commission"');
        $to = strpos($page, 'id="section-', $from + 20);
        $commission = substr($page, $from, $to - $from);

        $this->assertStringContainsString('Test Agent', $commission);
        $this->assertStringContainsString('22,560', $commission);
        $this->assertStringContainsString('15,040', $commission);
        $this->assertStringContainsString('7,520', $commission);

        $this->actingAs($agent)
            ->get('/reports?type=wallet')
            ->assertOk()
            ->assertSee('UGX 7,520')
            ->assertDontSee('22,560');
    }

    public function test_the_wallet_card_on_sign_in_states_this_month_commission(): void
    {
        $agent = $this->agent();

        $this->booking($agent, 'COM-007', '7520');

        $html = $this->actingAs($agent)->get('/dashboard')->assertOk()->getContent();

        // The figure is not enough on its own: the card names the stretch of days it covers, so a
        // fee line beside a today figure cannot read as a contradiction.
        $this->assertSame(
            1,
            preg_match('#Fees this month \(([^)]+)\)</span>\s*<span[^>]*>UGX 7,520</span>#', $html, $m),
            'The wallet card prints the charge kept beside the period it was kept over.'
        );
        $this->assertMatchesRegularExpression('/^\d{1,2} \w{3} to \d{1,2} \w{3} \d{4}$/', $m[1]);
    }

    public function test_an_admin_can_credit_an_agent_wallet_and_the_balance_moves(): void
    {
        $agent = $this->agent();
        $admin = $this->admin();

        $wallet = Account::walletFor($agent, 'UGX');
        $this->assertSame('0.00', (string) $wallet->balance());

        $this->actingAs($admin)
            ->post(route('wallets.adjust'), [
                'agent_id' => $agent->id,
                'direction' => 'credit',
                'amount' => '50000',
                'description' => 'Advance payment for October',
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertSame('50000.00', (string) $wallet->fresh()->balance());

        $entry = AccountEntry::where('account_id', $wallet->id)->where('type', 'adjustment_in')->first();
        $this->assertNotNull($entry);
        $this->assertSame('50000.00', (string) $entry->amount);
        $this->assertSame('Advance payment for October', $entry->description);
    }

    public function test_an_admin_can_debit_an_agent_wallet_after_a_credit(): void
    {
        $agent = $this->agent();
        $admin = $this->admin();
        $wallet = Account::walletFor($agent, 'UGX');

        $this->actingAs($admin)->post(route('wallets.adjust'), [
            'agent_id' => $agent->id,
            'direction' => 'credit',
            'amount' => '80000',
            'description' => 'Initial balance',
        ]);

        $this->actingAs($admin)->post(route('wallets.adjust'), [
            'agent_id' => $agent->id,
            'direction' => 'debit',
            'amount' => '30000',
            'description' => 'Cash advance returned',
        ])->assertRedirect();

        $this->assertSame('50000.00', (string) $wallet->fresh()->balance());

        $entries = AccountEntry::where('account_id', $wallet->id)->orderBy('id')->get();
        $this->assertCount(2, $entries);
        $this->assertSame('adjustment_in', $entries[0]->type);
        $this->assertSame('adjustment_out', $entries[1]->type);
    }

    public function test_an_admin_can_credit_a_lead_agents_wallet_from_the_same_form(): void
    {
        Role::firstOrCreate(['name' => 'super-agent', 'guard_name' => 'web']);

        $lead = User::create([
            'name' => 'Lead Agent',
            'email' => 'lead'.uniqid().'@plussave.test',
            'password' => 'Password123',
            'is_active' => true,
            'country' => 'UG',
        ]);
        $lead->assignRole('super-agent');

        $admin = $this->admin();
        $wallet = Account::walletFor($lead, 'UGX');

        $this->actingAs($admin)
            ->get(route('wallet.index'))
            ->assertOk()
            ->assertSee('Lead Agent');

        $this->actingAs($admin)
            ->post(route('wallets.adjust'), [
                'agent_id' => $lead->id,
                'direction' => 'credit',
                'amount' => '50000',
                'description' => 'Advance payment for October',
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertSame('50000.00', (string) $wallet->fresh()->balance());
    }

    public function test_an_inactive_agent_cannot_be_adjusted_by_typing_their_id(): void
    {
        $agent = $this->agent();
        $agent->update(['is_active' => false]);

        $this->actingAs($this->admin())
            ->post(route('wallets.adjust'), [
                'agent_id' => $agent->id,
                'direction' => 'credit',
                'amount' => '50000',
                'description' => 'Typed id',
            ])
            ->assertSessionHasErrors('agent_id');

        $this->assertSame(0, Account::where('owner_id', $agent->id)->count());
    }

    public function test_an_agent_cannot_adjust_anyone_wallet(): void
    {
        $agent = $this->agent();
        $other = $this->namedAgent('Other Agent');

        $this->actingAs($agent)
            ->post(route('wallets.adjust'), [
                'agent_id' => $other->id,
                'direction' => 'credit',
                'amount' => '50000',
                'description' => 'Sneaky',
            ])
            ->assertForbidden();
    }

    public function test_the_adjustment_form_is_visible_to_admins_only(): void
    {
        $agent = $this->agent();
        $admin = $this->admin();

        Account::walletsFor($agent);

        $this->actingAs($admin)
            ->get(route('wallet.index'))
            ->assertSee('Adjust an Agent');

        $this->actingAs($agent)
            ->get(route('wallet.index'))
            ->assertDontSee('Adjust an Agent');
    }
}
