<?php

namespace Tests\Feature;

use App\Models\ExchangeRate;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AgentPerformanceByCreatorTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['superadmin', 'admin', 'agent', 'staff'] as $role) {
            Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        }
    }

    private function user(string $role, string $name): User
    {
        $user = User::create([
            'name' => $name,
            'email' => strtolower(str_replace(' ', '.', $name)) . uniqid() . '@plussave.test',
            'password' => 'Password123',
            'is_active' => true,
        ]);

        $user->assignRole($role);

        return $user;
    }

    private function transfer(User $by, array $overrides = []): Transaction
    {
        return Transaction::create(array_merge([
            'receipt_number' => 'PS-' . uniqid(),
            'sender_name' => 'Sender One',
            'sender_contact' => '0700000001',
            'receiver_name' => 'Receiver One',
            'receiver_contact' => '8613800000001',
            'currency' => 'UGX',
            'amount' => 1000000,
            'exchange_rate' => 7.2,
            'rmb_amount' => 1764.71,
            'charge_rate' => 0.02,
            'charge_amount' => 2.5,
            'charge_ugx' => 20000,
            'total_usd_equivalent' => 250,
            'status' => 'completed',
            'created_by' => $by->id,
        ], $overrides));
    }

    public function test_work_done_by_an_admin_is_counted(): void
    {
        $viewer = $this->user('superadmin', 'Log Viewer');
        $admin = $this->user('admin', 'Gorret Admin');

        ExchangeRate::setPair('USD', 'UGX', 4000, 3600, 4000);
        $this->transfer($admin);

        $response = $this->actingAs($viewer)->get('/agents/performance')->assertOk();

        $rows = $response->viewData('agents')->values();

        $this->assertSame(1, $rows->count());
        $this->assertSame('Gorret Admin', $rows[0]['agent']->name);
        $this->assertSame('admin', $rows[0]['role']);
        $this->assertEqualsWithDelta(1000000, (float) $rows[0]['sent_ugx'], 0.01);
        $this->assertEqualsWithDelta(20000, (float) $rows[0]['revenue_ugx'], 0.01);

        $response->assertSee('Gorret Admin')->assertSee('Sent (UGX)');
    }

    public function test_an_agent_who_did_nothing_still_appears(): void
    {
        $viewer = $this->user('superadmin', 'Log Viewer');
        $agent = $this->user('agent', 'Idle Agent');
        $admin = $this->user('admin', 'Busy Admin');

        ExchangeRate::setPair('USD', 'UGX', 4000, 3600, 4000);
        $this->transfer($admin);

        $rows = $this->actingAs($viewer)->get('/agents/performance')->assertOk()
            ->viewData('agents')->values();

        $this->assertSame(2, $rows->count(), 'The busy person comes first, the idle agent still shows.');
        $this->assertSame('Busy Admin', $rows[0]['agent']->name);
        $this->assertSame('Idle Agent', $rows[1]['agent']->name);
        $this->assertSame('agent', $rows[1]['role']);
        $this->assertSame(0, (int) $rows[1]['total_transactions']);
    }

    public function test_the_money_is_shown_in_ugx_not_dollars(): void
    {
        $viewer = $this->user('superadmin', 'Log Viewer');
        $agent = $this->user('agent', 'Working Agent');

        ExchangeRate::setPair('USD', 'UGX', 4000, 3600, 4000);
        $this->transfer($agent);
        ExchangeRate::setPair('USD', 'UGX', 4200, 3800, 4200);

        $this->actingAs($viewer)->get('/agents/performance')
            ->assertOk()
            ->assertSee('1,000,000')
            ->assertSee('20,000')
            ->assertDontSee('Total Amount (USD)');
    }

    public function test_the_export_carries_the_role_and_the_ugx_figures(): void
    {
        $viewer = $this->user('superadmin', 'Log Viewer');
        $agent = $this->user('agent', 'Working Agent');

        ExchangeRate::setPair('USD', 'UGX', 4000, 3600, 4000);
        $this->transfer($agent);

        $response = $this->actingAs($viewer)->get('/export/agent-performance');
        $response->assertOk();

        $path = tempnam(sys_get_temp_dir(), 'pluspay-') . '.xlsx';
        file_put_contents($path, $response->streamedContent());
        $sheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($path)->getActiveSheet();
        @unlink($path);

        $this->assertSame('Role', $sheet->getCell('C5')->getValue());
        $this->assertSame('agent', $sheet->getCell('C6')->getValue());
        $this->assertSame('Sent (UGX)', $sheet->getCell('H5')->getValue());
        $this->assertSame('Revenue (UGX)', $sheet->getCell('I5')->getValue());
    }

    public function test_a_dollar_funded_transfer_is_counted_at_the_ugx_it_was_sold_for(): void
    {
        $viewer = $this->user('superadmin', 'Log Viewer');
        $agent = $this->user('agent', 'Working Agent');

        ExchangeRate::setPair('USD', 'UGX', 4000, 3600, 4000);
        $this->transfer($agent, [
            'currency' => 'USD',
            'amount' => 250,
            'rate_sell_ugx' => 3900,
            'charge_ugx' => 0,
            'charge_amount' => 5,
        ]);
        ExchangeRate::setPair('USD', 'UGX', 4200, 3800, 4200);

        $rows = $this->actingAs($viewer)->get('/agents/performance')->assertOk()->viewData('agents')->values();

        // 250 dollars sold at 3,900 when it was booked, so neither the RMB rate stored in
        // exchange_rate nor today's 4,200 belongs in this figure.
        $this->assertEqualsWithDelta(975000, (float) $rows[0]['sent_ugx'], 0.01);
        $this->assertEqualsWithDelta(19500, (float) $rows[0]['revenue_ugx'], 0.01);
    }
}
