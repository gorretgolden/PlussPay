<?php

namespace Tests\Feature;

use App\Models\ExchangeRate;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * The header card carries the one control that belongs beside the clock: a way to pull fresh figures.
 * The sign-out stays in the sidebar alone, because a control that lives in two places on one screen is
 * two places to keep working.
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

    public function test_the_board_offers_one_sign_out_and_it_posts_to_the_real_route(): void
    {
        $html = $this->board();

        $this->assertSame(1, $this->logoutForms($html),
            'The sidebar holds the only sign-out; the header card does not repeat it.');

        preg_match_all('#<form[^>]*action="'.preg_quote(route('logout', absolute: true), '#').'"[^>]*>(.*?)</form>#s', $html, $forms);
        $this->assertCount(1, $forms[1]);
        foreach ($forms[1] as $body) {
            $this->assertStringContainsString('name="_token"', $body, 'A sign-out without the token only 419s.');
        }
    }

    public function test_the_idle_sign_out_is_bound_to_the_sidebar_form(): void
    {
        $html = $this->board();

        $this->assertSame(1, substr_count($html, 'id="sidebarLogoutForm"'));
        $this->assertStringContainsString("getElementById('sidebarLogoutForm')", $html);
        $this->assertStringNotContainsString("querySelector('form[action=", $html);
    }

    public function test_the_header_card_carries_a_reload_button_that_reloads_instead_of_submitting(): void
    {
        $html = $this->board();

        $this->assertMatchesRegularExpression('#<button type="button" onclick="reloadDashboard\(this\)"#', $html);
        $this->assertStringContainsString('<span class="hidden md:inline">Reload</span>', $html);
        $this->assertStringContainsString('function reloadDashboard(button)', $html);
        $this->assertStringContainsString('window.location.reload();', $html);
        // The click is never silent: the button disables and names itself as working.
        $this->assertStringContainsString("button.disabled = true;", $html);
        $this->assertStringContainsString("'Reloading...'", $html);
    }

    public function test_the_reload_is_left_out_of_a_printed_board(): void
    {
        $html = $this->board();

        preg_match('#<div class="flex shrink-0 items-center gap-2 no-print xl:order-3">(.*?)</div>#s', $html, $group);
        $this->assertNotSame([], $group, 'The reload sits in a no-print group.');
        $this->assertStringContainsString('Reload', $group[1]);
        $this->assertStringNotContainsString('Log out', $group[1], 'The sign-out is not the header card\'s to carry.');
    }

    public function test_an_agent_gets_the_reload_without_losing_the_sign_out(): void
    {
        $html = $this->board('agent');

        $this->assertSame(1, $this->logoutForms($html));
        $this->assertStringContainsString('reloadDashboard(this)', $html);
    }

    public function test_signing_out_ends_the_session(): void
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
        $this->assertMatchesRegularExpression('#<p class="[^"]*text-gray-900">\w[^<]*, Amina Nabukenya\.</p>#', $html);
        $this->assertStringNotContainsString('max-w-[12rem]', $html, 'A name chip beside a role chip says the same thing twice.');
    }

    public function test_an_account_named_only_by_its_role_is_never_greeted_by_a_truncated_word(): void
    {
        // A name cut to its first word reads like a placeholder somebody never filled in. Several
        // desks share one role, so the board must use the whole name, and where the name is nothing
        // but the role it must say the role's own display name, not the leading word of it.
        $this->travelTo(now()->setTime(13, 5));

        $super = $this->board('superadmin', 'Super Admin');
        $this->assertMatchesRegularExpression('#<p class="[^"]*text-gray-900">Good afternoon, Super Admin\.</p>#', $super);
        $this->assertStringNotContainsString('Good afternoon, Super.', $super, 'A role name is never cut to its first word.');

        $this->flushSession();
        $agent = $this->board('agent', 'Agent');
        $this->assertMatchesRegularExpression('#<p class="[^"]*text-gray-900">Good afternoon, Agent\.</p>#', $agent);

        $this->flushSession();
        $named = $this->board('agent', 'Charles Kato Mulondo');
        $this->assertMatchesRegularExpression('#<p class="[^"]*text-gray-900">Good afternoon, Charles Kato Mulondo\.</p>#', $named);
    }

    public function test_the_clock_is_stamped_to_the_minute_with_no_running_second(): void
    {
        $this->travelTo(now()->setTime(19, 12, 45));

        $html = $this->board();

        // The clock element carries a data-zone-offset for the script that keeps it ticking, so the
        // capture skips the attributes and reads only what sits between the tags.
        preg_match('#id="liveClock"[^>]*>(.*?)</span>#', $html, $stamp);
        $this->assertMatchesRegularExpression(
            '/^(Sun|Mon|Tue|Wed|Thu|Fri|Sat), \d{1,2} (Jan|Feb|Mar|Apr|May|Jun|Jul|Aug|Sep|Oct|Nov|Dec) \d{4} · \d{1,2}:\d{2} (AM|PM) EAT$/',
            $stamp[1] ?? '',
            'The stamp reads Fri, 16 Oct 2026 · 7:12 PM EAT: the hour and minute and the zone the desk keeps, never the second.'
        );

        // Nothing on the page redraws it every second, so the figure cannot twitch while it is read.
        $this->assertStringNotContainsString('getSeconds()', $html);
        $this->assertStringContainsString('setInterval(updateClock, 10000)', $html);
    }

    public function test_on_a_phone_the_reload_is_a_40_pixel_square_that_keeps_its_name(): void
    {
        $html = $this->board('agent');

        // The word is dropped below the tablet breakpoint, so the button keeps its accessible name
        // and a tap target a thumb can hit without catching its neighbour.
        preg_match('#aria-label="Reload this board"[^>]*class="([^"]+)"#', $html, $reload);
        $this->assertStringContainsString('w-10 h-10', $reload[1] ?? '', 'The reload is a 40px square until its word fits.');
        $this->assertStringContainsString('<span class="hidden md:inline">Reload</span>', $html, 'The label is the one hidden on a phone.');
        $this->assertStringContainsString('aria-label="Reload this board"', $html);
    }

    public function test_the_hour_mark_is_dark_enough_to_read_against_its_circle(): void
    {
        $today = now()->startOfDay();

        // Both bands are read at a fixed hour. Read at whatever hour the suite happens to run
        // in, the evening board was the morning one and this failed on a midday run.
        $this->travelTo($today->copy()->setTime(20, 10));
        $evening = $this->board();

        $this->travelTo($today->copy()->setTime(13, 5));
        $this->flushSession();
        $midday = $this->actingAs($this->desk())->get('/dashboard')->getContent();

        // The mark used to sit on a frosted circle in a paler glyph on the green hero. The header
        // is now plain white, so each band carries a pastel circle with the -700 shade of the same
        // colour, and the shape still reads against the band rather than the blob.
        $this->assertStringContainsString('bg-indigo-100 text-indigo-700', $evening);
        $this->assertStringContainsString('bg-amber-100 text-amber-700', $midday);
    }
}
