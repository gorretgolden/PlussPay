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
        $this->assertStringContainsString('<span class="hidden sm:inline">Reload</span>', $html);
        $this->assertStringContainsString('function reloadDashboard(button)', $html);
        $this->assertStringContainsString('window.location.reload();', $html);
        // The click is never silent: the button disables and names itself as working.
        $this->assertStringContainsString("button.disabled = true;", $html);
        $this->assertStringContainsString("'Reloading...'", $html);
    }

    public function test_both_controls_are_left_out_of_a_printed_board(): void
    {
        $html = $this->board();

        preg_match('#<div class="flex items-center gap-2 ml-auto sm:ml-0 no-print">(.*?)</div>\s*</div>#s', $html, $group);
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

    public function test_the_header_names_the_person_signed_in_once_in_the_greeting(): void
    {
        $html = $this->board('agent', 'Amina Nabukenya');

        // Several desks share the role, so the board has to say whose hand the balances are in.
        // It is said once, in the greeting, and the chips are left for the role and the desk.
        $this->assertMatchesRegularExpression('#<p class="text-gray-600 mt-1">\w[^<]*, Amina Nabukenya\.</p>#', $html);
        $this->assertStringNotContainsString('max-w-[12rem]', $html, 'A name chip beside a role chip says the same thing twice.');
    }

    public function test_the_clock_is_stamped_to_the_minute_with_no_running_second(): void
    {
        $this->travelTo(now()->setTime(19, 12, 45));

        $html = $this->board();

        preg_match('#id="liveClock">(.*?)</span>#', $html, $stamp);
        $this->assertMatchesRegularExpression(
            '/^(Sun|Mon|Tue|Wed|Thu|Fri|Sat), \d{1,2} (Jan|Feb|Mar|Apr|May|Jun|Jul|Aug|Sep|Oct|Nov|Dec) \d{4} · \d{1,2}:\d{2} (AM|PM)$/',
            $stamp[1] ?? '',
            'The stamp reads Fri, 16 Oct 2026 · 7:12 PM: the hour and minute, never the second.'
        );

        // Nothing on the page redraws it every second, so the figure cannot twitch while it is read.
        $this->assertStringNotContainsString('getSeconds()', $html);
        $this->assertStringContainsString('setInterval(updateClock, 10000)', $html);
    }

    public function test_on_a_phone_the_two_controls_are_40_pixels_wide_and_still_named(): void
    {
        $html = $this->board('agent');

        // The words are dropped below the small breakpoint, so each button keeps its accessible
        // name and a tap target a thumb can hit without catching its neighbour.
        $this->assertSame(2, substr_count($html, 'w-10 h-10 sm:w-auto sm:h-auto'), 'Both buttons grow to 40px on a phone.');
        $this->assertSame(2, substr_count($html, '<span class="hidden sm:inline">'), 'Both labels are the ones hidden on a phone.');
        $this->assertStringContainsString('aria-label="Reload this board"', $html);
        $this->assertStringContainsString('aria-label="Log out"', $html);
    }

    public function test_the_sign_out_is_the_quieter_of_the_two_until_it_is_aimed_at(): void
    {
        $html = $this->board();

        // It ends the session, so it must not read as the primary action or match the reload beside
        // it. It colours up only once a pointer is actually on it.
        preg_match('#aria-label="Log out"\s+class="([^"]+)"#', $html, $signOut);
        $this->assertStringContainsString('text-gray-500', $signOut[1] ?? '');
        $this->assertStringContainsString('hover:text-red-700', $signOut[1] ?? '');
        $this->assertStringNotContainsString('text-gray-800', $signOut[1] ?? '', 'It is lighter than the reload button.');
    }

    public function test_the_hour_mark_is_dark_enough_to_read_against_its_circle(): void
    {
        $evening = $this->board();
        $this->travelTo(now()->setTime(13, 5));
        $this->flushSession();
        $midday = $this->actingAs($this->desk())->get('/dashboard')->getContent();

        // The mark used to sit on a pale circle in a paler glyph. Both bands now carry a tinted
        // circle and the -600 shade of the same colour, so the shape reads rather than the blob.
        $this->assertStringContainsString('bg-indigo-50 text-indigo-600', $evening);
        $this->assertStringContainsString('bg-amber-50 text-amber-600', $midday);
    }
}
