<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\PermissionCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * The China Desk and Uganda Desk sidebar links hang off the abilities the payout pen needs,
 * which would hand every normal agent two sidebar doors they do not need. She ruled on
 * 2026-11-04 that normal agents reach the desks through the board tiles alone, while the
 * leads and the office keep the links.
 */
class DeskSidebarLinksTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $role, string $name, string $country = 'UG'): User
    {
        $model = Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        $model->syncPermissions(PermissionCatalog::defaults()[$role] ?? []);

        $user = User::create([
            'name' => $name,
            'email' => strtolower($role).uniqid().'@plussave.test',
            'password' => 'Password123',
            'is_active' => true,
            'country' => $country,
        ]);
        $user->assignRole($role);

        return $user;
    }

    private function html(User $user, string $path = '/dashboard'): string
    {
        $this->flushSession();

        return $this->actingAs($user)->get($path)->assertOk()->getContent();
    }

    public function test_normal_agents_have_no_desk_links_in_the_sidebar_but_the_lead_does(): void
    {
        // A normal agent still clears either payout queue from the board's Withdraw tile and the
        // desk page itself; the two sidebar doors are what go.
        $agent = $this->html($this->user('agent', 'Amina Nabukenya'));
        $this->assertStringNotContainsString('>China Desk</span>', $agent, 'A normal agent reaches the China queue from the board tile.');
        $this->assertStringNotContainsString('>Uganda Desk</span>', $agent, 'Nor does the sidebar hand him a Uganda Desk door.');

        $lead = $this->html($this->user('super-agent', 'Amina Lead'));
        $this->assertStringContainsString('>China Desk</span>', $lead);
        $this->assertStringContainsString('>Uganda Desk</span>', $lead);

        $office = $this->html($this->user('admin', 'Admin One'));
        $this->assertStringContainsString('>China Desk</span>', $office);
        $this->assertStringContainsString('>Uganda Desk</span>', $office);
    }

    public function test_an_agent_still_opens_both_desk_pages(): void
    {
        // Hiding the links must not close the desks themselves: the payout pen the tiles point at
        // still works on both pages for a normal agent.
        $agent = $this->user('agent', 'Amina Nabukenya');

        foreach (['/china', '/uganda'] as $path) {
            $this->flushSession();
            $this->actingAs($agent)->get($path)->assertOk();
        }
    }
}
