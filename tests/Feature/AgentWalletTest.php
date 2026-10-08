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
        $user = User::create([
            'name' => 'Test Agent',
            'email' => 'agent'.uniqid().'@plussave.test',
            'password' => 'Password123',
            'is_active' => true,
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

    public function test_wallets_for_creates_all_three_currencies(): void
    {
        $agent = $this->agent();

        $wallets = Account::walletsFor($agent);

        $this->assertCount(3, $wallets);
        $this->assertArrayHasKey('UGX', $wallets);
        $this->assertArrayHasKey('USD', $wallets);
        $this->assertArrayHasKey('RMB', $wallets);
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
    }

    public function test_dashboard_shows_wallet_balances(): void
    {
        $agent = $this->agent();

        $response = $this->actingAs($agent)->get(route('dashboard'));

        $response->assertOk();
        $response->assertSee('UGX Wallet');
        $response->assertSee('USD Wallet');
        $response->assertSee('RMB Wallet');
    }

    public function test_company_accounts_are_not_wallets(): void
    {
        $company = Account::byCode(Account::UGX_UGANDA);

        $this->assertFalse($company->isWallet());
        $this->assertNull($company->owner_id);
    }
}
