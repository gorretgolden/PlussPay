<?php

namespace Tests\Feature;

use App\Models\ExchangeRate;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * On a phone the sidebar is a closed drawer, so the one control every shift ends with was hidden.
 * The sign-out and a way to pull fresh figures now sit in the header card as well.
 */
class DashboardHeaderControlsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['superadmin', 'agent'] as $role) {
            Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        }

        ExchangeRate::setPair('USD', 'UGX', 4000, 3600, 4000);
        ExchangeRate::setPair('USD', 'RMB', 6.56, 6.56, 6.56);
    }

    private function desk(string $role = 'superadmin', string $name = 'Super Admin'): User
    {
        $user = User::create([
            'name' => $name,
            'email' => 'desk.'.uniqid().'@plussave.test',
            'password' => 'Password123',
            'is_active' => true,
        ]);
        $user->assignRole($role);

        return $user;
    }

    private function board(string $role = 'superadmin', string $name = 'Super Admin'): string
    {
        return $this->actingAs($this->desk($role, $name))->get('/dashboard')->getContent();
    }

    /** Counts real form tags only, so the idle script that names the same URL is not one. */
    private function logoutForms(string $html): int
    {
        preg_match_all('#<form[^>]*action="'.preg_quote(route('logout', absolute: true), '#').'"#', $html, $m);

        return count($m[0]);
    }

    public function test_the_header_card_repeats_the_sign_out_and_points_at_the_real_route(): void
    {
        $html = $this->board();

        $this->assertSame(2, $this->logoutForms($html),
            'The sidebar keeps its sign-out and the header card adds one.');

        preg_match_all('#<form[^>]*action="'.preg_quote(route('logout', absolute: true), '#').'"[^>]*>(.*?)</form>#s', $html, $forms);
        $this->assertCount(2, $forms[1]);
        foreach ($forms[1] as $body) {
            $this->assertStringContainsString('name="_token"', $body, 'A sign-out without the token only 419s.');
        }
    }

    public function test_the_idle_sign_out_is_bound_to_the_sidebar_form_not_the_new_one(): void
    {
        // Two sign-out forms now exist, so the auto sign-out names its form outright instead of
        // taking whichever appears first in the markup.
        $html = $this->board();

        $this->assertSame(1, substr_count($html, 'id="sidebarLogoutForm"'));
        $this->assertStringContainsString("getElementById('sidebarLogoutForm')", $html);
        $this->assertStringNotContainsString("querySelector('form[action=", $html);
    }

    public function test_the_header_card_carries_a_reload_button_that_reloads_instead_of_submitting(): void
    {
        $html = $this->board();

        $this->assertMatchesRegularExpression('#<button type="button" onclick="reloadDashboard\(this\)"#', $html);
        $this->assertStringContainsString('<span>Reload</span>', $html);
        $this->assertStringContainsString('function reloadDashboard(button)', $html);
        $this->assertStringContainsString('window.location.reload();', $html);
        // The click is never silent: the button disables and names itself as working.
        $this->assertStringContainsString("button.disabled = true;", $html);
        $this->assertStringContainsString("'Reloading...'", $html);
    }

    public function test_both_controls_are_left_out_of_a_printed_board(): void
    {
        $html = $this->board();

        preg_match('#<div class="flex items-center gap-2 no-print">(.*?)</div>\s*</div>#s', $html, $group);
        $this->assertNotSame([], $group, 'The two buttons share one no-print group.');
        $this->assertStringContainsString('Reload', $group[1]);
        $this->assertStringContainsString('Log out', $group[1]);
    }

    public function test_an_agent_sees_the_same_two_controls(): void
    {
        $html = $this->board('agent');

        $this->assertSame(2, $this->logoutForms($html));
        $this->assertStringContainsString('reloadDashboard(this)', $html);
    }

    public function test_signing_out_from_the_header_ends_the_session(): void
    {
        $this->actingAs($this->desk())->post('/logout')
            ->assertRedirect(route('login'))
            ->assertSessionHas('success', 'You have been logged out successfully.');

        $this->assertGuest();
    }

    public function test_the_header_names_the_person_signed_in_before_the_role_they_hold(): void
    {
        $html = $this->board('agent', 'Amina Nabukenya');

        // Several desks share the role, so the chip that only says "agent" cannot tell her whose
        // hand the balances below are in. The name gets its own chip, in front of the role.
        preg_match('#<span class="truncate max-w-\[12rem\]">(.*?)</span>.*?capitalize">(.*?)</span>#s', $html, $chips);

        $this->assertNotSame([], $chips, 'The header card carries a name chip and then a role chip.');
        $this->assertSame('Amina Nabukenya', $chips[1]);
        $this->assertStringContainsString('agent', strtolower($chips[2]), 'The role is still stated beside the name.');
    }
}
