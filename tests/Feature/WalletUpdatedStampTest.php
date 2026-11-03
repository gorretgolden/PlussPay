<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\ExchangeRate;
use App\Models\User;
use App\Services\AccountLedger;
use App\Support\PermissionCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Finance reads the age of a balance before it trusts it. Every wallet card carries the moment
 * its newest ledger line was written, in relative words and in the exact stamp, and an untouched
 * wallet says so instead of borrowing a time from the wallet row that was only ever opened.
 */
class WalletUpdatedStampTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (PermissionCatalog::names() as $name) {
            Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
        }

        foreach (['agent', 'super-agent', 'staff'] as $role) {
            Role::firstOrCreate(['name' => $role, 'guard_name' => 'web'])
                ->syncPermissions(PermissionCatalog::defaults()[$role]);
        }

        ExchangeRate::setPair('USD', 'UGX', 4000, 3600, 4000);
        ExchangeRate::setPair('USD', 'RMB', 6.56, 6.56, 6.56);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function person(string $role, string $name, string $country = 'UG'): User
    {
        $user = User::create([
            'name' => $name,
            'email' => strtolower(str_replace(' ', '.', $name)).uniqid().'@plussave.test',
            'password' => 'Password123',
            'is_active' => true,
            'country' => $country,
        ]);
        $user->assignRole($role);

        return $user;
    }

    private function move(User $user, string $currency, string $amount): Account
    {
        $wallet = Account::walletFor($user, $currency);
        AccountLedger::record($wallet, 'adjustment_in', $amount, 'Opening float');

        return $wallet;
    }

    public function test_every_wallet_card_shows_when_the_money_last_moved(): void
    {
        $agent = $this->person('agent', 'Amina Nabukenya');

        Carbon::setTestNow('2026-10-21 09:40:00');
        $wallet = $this->move($agent, 'UGX', '50000');

        // The wallet is twenty minutes old by the time the second line lands. The card must age
        // from the money, not from the row that was created when the account was opened.
        Carbon::setTestNow('2026-10-21 09:58:00');
        AccountLedger::record($wallet, 'adjustment_in', '25000', 'Top up');

        Carbon::setTestNow('2026-10-21 10:00:00');

        foreach (['/dashboard', '/wallet', '/reports?type=wallet'] as $url) {
            $html = $this->actingAs($agent)->get($url)->assertOk()->getContent();

            $this->assertStringContainsString('Last activity 2 minutes ago', $html, "{$url} does not say when the wallet changed.");
            $this->assertStringContainsString('Oct 21, 2026, 9:58 AM', $html, "{$url} does not print the exact time.");
            $this->assertStringNotContainsString('Oct 21, 2026, 9:40 AM', $html, "{$url} is showing when the wallet was opened, not its last movement.");
            $this->assertStringContainsString('No movement yet', $html, "{$url} does not mark the untouched dollar wallet.");
        }
    }

    public function test_the_china_desk_stamps_both_of_its_wallets(): void
    {
        $agent = $this->person('agent', 'Li Fang', 'CN');

        // Both wallets exist before the card is read, because the card names a yuan balance and
        // the dollars beside it. Only the yuan one has moved.
        Account::walletsFor($agent);
        Carbon::setTestNow('2026-10-21 09:58:00');
        $this->move($agent, 'RMB', '675');

        Carbon::setTestNow('2026-10-21 10:00:00');

        $html = $this->actingAs($agent)->get('/china')->assertOk()->getContent();

        $this->assertStringContainsString('Yuan wallet updated 2 minutes ago', $html);
        $this->assertStringContainsString('Oct 21, 2026, 9:58 AM', $html);
        $this->assertStringContainsString('No movement yet', $html);
    }
}
