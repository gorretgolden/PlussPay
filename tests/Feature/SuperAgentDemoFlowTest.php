<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\CashHandover;
use App\Models\Setting;
use App\Models\User;
use App\Services\AccountLedger;
use Database\Seeders\RoleAndUserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Env;
use Tests\TestCase;

/**
 * The Super Agent button on the live sign-in page is the way the desk is checked. These tests walk
 * that exact route: the seeded account signs in with the shared demo password, the demo Uganda
 * agent hands cash to it, and it approves the receipt from its own dashboard.
 */
class SuperAgentDemoFlowTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'DeskDemo2026';

    protected function setUp(): void
    {
        parent::setUp();

        // The live site has no SEED_* values, so neither does this test.
        foreach ([
            'SEED_SUPERADMIN_PASSWORD',
            'SEED_ADMIN_PASSWORD',
            'SEED_AGENT_PASSWORD',
            'SEED_CHINA_AGENT_PASSWORD',
            'SEED_SUPER_AGENT_PASSWORD',
            'SEED_ACCOUNTANT_PASSWORD',
        ] as $key) {
            Env::getRepository()->clear($key);
            unset($_ENV[$key], $_SERVER[$key]);
        }

        Setting::set('demo_password', self::PASSWORD, 'string', 'display');
        $this->seed(RoleAndUserSeeder::class);
    }

    private function signIn(string $email): void
    {
        auth()->logout();
        $this->flushSession();

        $this->post('/admin/login', [
            'email' => $email,
            'password' => self::PASSWORD,
        ])->assertSessionHasNoErrors();

        $this->assertAuthenticatedAs(User::where('email', $email)->sole());
    }

    public function test_the_super_agent_demo_account_signs_in_to_its_own_desk(): void
    {
        auth()->logout();

        $html = $this->get('/admin/login')->assertOk()->getContent();

        $this->assertStringContainsString('data-demo-email="superagent@plussave.com"', $html);
        $this->assertStringContainsString(
            '> Super Agent </button>',
            preg_replace('/\s+/', ' ', $html)
        );

        $this->signIn('superagent@plussave.com');

        $this->get('/dashboard')->assertOk()
            ->assertSee('Cash Handed To You')
            ->assertSee('No cash has been handed to you yet.');
    }

    public function test_the_demo_agent_hands_cash_to_the_demo_super_agent_who_approves_it(): void
    {
        $agent = User::where('email', 'agent@plussave.com')->sole();
        $lead = User::where('email', 'superagent@plussave.com')->sole();

        AccountLedger::record(
            Account::walletFor($agent, 'UGX'),
            'adjustment_in',
            '500000',
            'Float from the office'
        );

        $this->signIn('agent@plussave.com');

        $this->get('/wallet')->assertOk()
            ->assertSee('Hand cash in to your super agent');

        $this->post('/cash-handovers', [
            'super_agent_id' => $lead->id,
            'currency' => 'UGX',
            'amount' => '120000',
            'note' => 'Morning collection',
        ])->assertSessionHasNoErrors();

        $handover = CashHandover::sole();

        $this->assertTrue($handover->isPending());
        $this->assertSame($lead->id, $handover->super_agent_id);

        $this->signIn('superagent@plussave.com');

        // The request is waiting on the super agent's first screen, not only on the wallet page.
        $this->get('/dashboard')->assertOk()
            ->assertSee('Cash Handed To You')
            ->assertSee($handover->reference)
            ->assertSee('Morning collection')
            ->assertSee('Approve receipt');

        $this->post(route('cash-handovers.approve', $handover))->assertSessionHasNoErrors();

        $handover->refresh();

        $this->assertSame(CashHandover::APPROVED, $handover->status);
        $this->assertSame($lead->id, $handover->super_agent_id);
        $this->assertSame('380000.00', Account::walletFor($agent, 'UGX')->balance());
        $this->assertSame('120000.00', Account::walletFor($lead, 'UGX')->balance());

        $this->get('/dashboard')->assertOk()
            ->assertSee('Received')
            ->assertSee('UGX 120,000')
            ->assertDontSee('No cash has been handed to you yet.');
    }
}
