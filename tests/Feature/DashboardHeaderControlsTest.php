<?php

namespace Tests\Feature;

use App\Models\ExchangeRate;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * The header card is the whole top of the board in one row on a wide screen: the greeting, the role,
 * the hour, the box that searches the desk and the three controls a shift is ended with. The search
 * used to spend a band of vertical space in a card of its own underneath, so it belongs in here and
 * no second search card is left on the page.
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

    /** The header's own control group: the row of buttons that closes the card. */
    private function headerGroup(string $html): string
    {
        preg_match('#<div class="flex shrink-0 items-center gap-2 no-print xl:order-3">(.*?)</div>#s', $html, $group);
        $this->assertNotSame([], $group, 'The header card carries a control group.');

        return $group[1];
    }

    public function test_the_board_offers_two_sign_outs_and_both_post_to_the_real_route(): void
    {
        $html = $this->board();

        // The sidebar is a closed drawer on a phone, so the sign-out that ends a shift has to be
        // where the eyes already are as well as in the menu.
        $this->assertSame(2, $this->logoutForms($html),
            'The header card and the sidebar each carry a sign-out.');

        preg_match_all('#<form[^>]*action="'.preg_quote(route('logout', absolute: true), '#').'"[^>]*>(.*?)</form>#s', $html, $forms);
        $this->assertCount(2, $forms[1]);
        foreach ($forms[1] as $body) {
            $this->assertStringContainsString('name="_token"', $body, 'A sign-out without the token only 419s.');
        }
    }

    public function test_the_search_box_shares_the_header_card_and_no_second_card_is_left(): void
    {
        $html = $this->board();

        // The attribute is named twice more in the script that wires the box, so the opening tag is
        // what says whether a second card is still on the page.
        $this->assertSame(1, substr_count($html, ' data-dashboard-search>'));
        $this->assertStringNotContainsString('<div class="relative mb-6" data-dashboard-search>', $html,
            'The search no longer sits under the header in a card of its own.');

        $card = $this->headerCard($html);
        $greeting = strpos($card, 'leading-snug text-gray-900');
        $controls = strpos($card, 'no-print xl:order-3');
        $box = strpos($card, ' data-dashboard-search>');

        // The box is the row's last child on purpose: greeting and controls share the first line on a
        // phone and the full width box drops to the second without a second rule. On a wide board
        // xl:order puts it back between them, so the markup order is not the reading order.
        $this->assertNotSame(false, $greeting, 'The card opens with the greeting.');
        $this->assertGreaterThan($greeting, $controls, 'The controls follow the greeting in the markup.');
        $this->assertGreaterThan($controls, $box, 'and the box is the last child.');
        $this->assertStringContainsString('id="dashboardSearch"', $card);
    }

    /** The one card at the top of the board, up to the Overview card under it. */
    private function headerCard(string $html): string
    {
        $start = strpos($html, '<div class="mb-4 rounded-2xl border border-gray-200 bg-white shadow-sm p-4 sm:p-5 xl:p-6">');
        $this->assertNotSame(false, $start, 'The board opens with one rounded card.');

        $end = strpos($html, 'Overview</h2>', $start);
        $this->assertNotSame(false, $end, 'and the Overview card is the next one down.');

        return substr($html, $start, $end - $start);
    }

    public function test_the_search_grows_into_the_row_on_a_wide_board_and_capped_at_512px(): void
    {
        $html = $this->board();

        preg_match('#<div class="([^"]*)"[^>]*data-dashboard-search>#', $html, $box);
        $classes = $box[1] ?? '';

        $this->assertStringContainsString('w-full', $classes, 'Below xl the box is a row of its own.');
        $this->assertStringContainsString('xl:order-2', $classes, 'On a wide board it sits between the greeting and the controls.');
        $this->assertStringContainsString('xl:flex-1', $classes, 'It takes the space the greeting leaves.');
        $this->assertStringContainsString('xl:max-w-[32rem]', $classes, 'and gives back anything past 512px.');
        $this->assertStringNotContainsString('xl:w-[20rem]', $classes, 'A fixed 320px bar is narrow on a 1440px board.');

        // 44px tall with the icon on the left and the key hint on the right.
        $this->assertStringContainsString('<div class="flex h-11 items-center gap-2 rounded-xl border border-gray-200 bg-gray-50 px-3', $html);
        $this->assertStringContainsString('<kbd class="hidden md:inline-block', $html, 'The slash hint is dropped where no keyboard is in reach.');
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

    public function test_the_header_controls_are_left_out_of_a_printed_board(): void
    {
        $html = $this->board();

        $group = $this->headerGroup($html);
        foreach (['Reload', 'Log out', 'What happened', 'no-print'] as $carried) {
            if ($carried === 'no-print') {
                continue;
            }
            $this->assertStringContainsString($carried, $group, 'The header card carries '.$carried.' for the desk that prints the board without it.');
        }
    }

    public function test_the_three_controls_are_one_row_of_40_pixel_targets(): void
    {
        $html = $this->board();

        $this->assertSame(1, substr_count($html, '<div class="flex shrink-0 items-center gap-2 no-print xl:order-3">'),
            'One row with a consistent 8px gap between the controls.');

        $group = $this->headerGroup($html);

        preg_match_all('#(?:<a|<button)[^>]*class="([^"]*(?:rounded-xl|rounded-full)[^"]*)"#', $group, $boxes);
        $this->assertGreaterThanOrEqual(3, count($boxes[1]), 'The bell, the reload and the sign-out.');

        foreach ($boxes[1] as $classes) {
            $this->assertStringContainsString('w-10 h-10', $classes, 'Every control is a 40px square until its word fits.');
            $this->assertStringContainsString('rounded-xl', $classes, 'and the same corner as the box it sits beside.');
            $this->assertStringContainsString('duration-150', $classes, 'with the hover and press states eased, not snapped.');
        }

        // Only the reload is filled: the bell and the sign-out are the quiet ghosts beside it.
        $this->assertMatchesRegularExpression('#class="[^"]*bg-green-800 text-\[13px\] font-semibold text-white[^"]*"#', $group);
        $this->assertSame(2, substr_count($group, 'border border-gray-200 bg-white'), 'Two outlined controls, one solid.');
    }

    public function test_an_agent_gets_the_reload_and_sign_out_without_a_bell(): void
    {
        $html = $this->board('agent');

        $this->assertSame(2, $this->logoutForms($html));
        $this->assertStringContainsString('reloadDashboard(this)', $html);
        $this->assertStringNotContainsString('aria-label="What happened', $html, 'A desk on the receiving end of no news is not handed a dead bell.');
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
        // colour, and the shape still reads against the band rather than the blob. The night band is
        // blue and not indigo because the layout's dark theme dims the blue pair and never learned
        // the indigo one: on a dark desk an indigo circle is a pale hole in the card.
        $this->assertStringContainsString('bg-blue-100 text-blue-700', $evening);
        $this->assertStringNotContainsString('bg-indigo-100', $evening, 'Nothing in the header is outside the dark theme map.');
        $this->assertStringContainsString('bg-amber-100 text-amber-700', $midday);
    }
}
