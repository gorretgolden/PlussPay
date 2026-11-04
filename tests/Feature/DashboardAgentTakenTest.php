<?php

namespace Tests\Feature;

use App\Models\ExchangeRate;
use App\Models\Transaction;
use App\Models\User;
use App\Support\PermissionCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * The Overview card only the desks the agents answer to get: the cash the agents' own hands
 * collected, principal plus charge. It is a slice of the window's Sent and Charge figures, so
 * these tests book a counter transfer beside the agents' ones and check the card leaves it out.
 */
class DashboardAgentTakenTest extends TestCase
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
    }

    private function user(string $role, string $name): User
    {
        $user = User::create([
            'name' => $name,
            'email' => strtolower(str_replace(' ', '.', $role)).uniqid().'@plussave.test',
            'password' => 'Password123',
            'is_active' => true,
        ]);
        $user->assignRole($role);

        return $user;
    }

    private function booking(User $creator, string $receipt, string $principal, string $charge): Transaction
    {
        return Transaction::create([
            'receipt_number' => $receipt,
            'sender_name' => 'Sender '.$receipt,
            'sender_contact' => '0700000001',
            'receiver_name' => 'Receiver '.$receipt,
            'receiver_contact' => '8613800000001',
            'currency' => 'UGX',
            'amount' => $principal,
            'exchange_rate' => 6.75,
            'rmb_amount' => bcmul($principal, '6.75', 2),
            'charge_rate' => 0.02,
            'charge_amount' => 2,
            'charge_ugx' => $charge,
            'status' => 'completed',
            'created_by' => $creator->id,
        ]);
    }

    private function dashboardHtml(User $viewer): string
    {
        $this->flushSession();

        return $this->actingAs($viewer)->get('/dashboard')->assertOk()->getContent();
    }

    public function test_the_overseers_get_the_agents_taken_card_that_leaves_the_counter_out(): void
    {
        $office = $this->user('admin', 'Desk Admin');
        $agent = $this->user('agent', 'Agent Betty');
        $lead = $this->user('super-agent', 'Lead Agent');
        $owner = $this->user('superadmin', 'The Boss');

        // 300,000 + 10,000 and 150,000 + 5,000 from the agent's hands, 90,000 + 6,000 from the
        // lead's own, and 200,000 + 8,000 taken at the office counter.
        $this->booking($agent, 'PS-TAKEN-1', '300000', '10000');
        $this->booking($agent, 'PS-TAKEN-2', '150000', '5000');
        $this->booking($lead, 'PS-TAKEN-3', '90000', '6000');
        $this->booking($office, 'PS-TAKEN-4', '200000', '8000');

        // One card per window, and every window carries the same sum here: 300,000 + 150,000 +
        // 90,000 principal plus 10,000 + 5,000 + 6,000 charge, with the counter booking left out.
        foreach ([$office, $lead, $owner] as $overseer) {
            $html = $this->dashboardHtml($overseer);

            $this->assertSame(3, substr_count($html, 'Taken By Agents'), $overseer->name.' gets the card in all three windows.');
            $this->assertSame(3, substr_count($html, 'UGX 561,000'), $overseer->name.' reads the agents\' principal and charge summed.');
            $this->assertStringContainsString('principal plus charge at the counter', $html);
        }
    }

    public function test_a_desk_without_agents_to_answer_to_gets_no_card(): void
    {
        $agent = $this->user('agent', 'Agent Betty');
        $teller = $this->user('staff', 'Doreen Namuli');

        $this->booking($agent, 'PS-TAKEN-5', '300000', '10000');

        foreach ([$agent, $teller] as $viewer) {
            $this->assertStringNotContainsString('Taken By Agents', $this->dashboardHtml($viewer));
        }
    }
}
