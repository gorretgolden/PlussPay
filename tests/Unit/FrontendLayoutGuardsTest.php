<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class FrontendLayoutGuardsTest extends TestCase
{
    private function read(string $path): string
    {
        $full = dirname(__DIR__, 2).DIRECTORY_SEPARATOR.$path;
        $this->assertFileExists($full);

        return file_get_contents($full);
    }

    public function test_the_users_table_pins_every_column_and_stops_headers_breaking_mid_word(): void
    {
        $css = $this->read('public'.DIRECTORY_SEPARATOR.'user-management.css');
        $this->assertMatchesRegularExpression('/\.user-table\{[^}]*min-width:900px/', $css, 'The table must scroll rather than crush a column.');
        $this->assertMatchesRegularExpression('/\.user-table th\{[^}]*overflow-wrap:normal/', $css, 'Anywhere wrapping stacks a header one letter per line.');

        $table = $this->read('resources'.DIRECTORY_SEPARATOR.'views'.DIRECTORY_SEPARATOR.'users'.DIRECTORY_SEPARATOR.'partials'.DIRECTORY_SEPARATOR.'table.blade.php');
        $this->assertMatchesRegularExpression('/<colgroup>(<col style="width:[^"]+">){9}<\/colgroup>/', $table, 'Every column needs an explicit width.');
    }

    /**
     * The row menu was one fixed 200px card, so on a machine that renders type larger than the
     * stylesheet asked for, "Email password link" ran straight out past its own border. The card
     * now takes the width its longest label needs, and the script measures that width instead of
     * assuming 200 when it works out where to put the card on screen.
     *
     * It also has to sit outside the table. Left in the actions cell, the sticky cells of later
     * rows paint white over the open card and cut the end off exactly the longest labels.
     */
    public function test_the_row_menu_widens_for_its_words_instead_of_losing_them(): void
    {
        $css = $this->read('public'.DIRECTORY_SEPARATOR.'user-management.css');
        $js = $this->read('public'.DIRECTORY_SEPARATOR.'user-management.js');
        $table = $this->read('resources'.DIRECTORY_SEPARATOR.'views'.DIRECTORY_SEPARATOR.'users'.DIRECTORY_SEPARATOR.'partials'.DIRECTORY_SEPARATOR.'table.blade.php');

        $this->assertMatchesRegularExpression('/\.user-menu\{[^}]*width:max-content;min-width:200px;max-width:min\(320px,calc\(100vw - 16px\)\)/', $css, 'The card must grow with its labels and stay inside the window.');
        $this->assertMatchesRegularExpression('/\.user-menu a,\.user-menu button\{[^}]*overflow-wrap:break-word/', $css, 'A label gives up at a space, not mid-word.');

        $this->assertStringNotContainsString('innerWidth - 208', $js, 'Positioning must not assume a 200px card.');
        $this->assertStringContainsString('const menuWidth = menu.offsetWidth;', $js);

        $this->assertLessThan(strpos($table, 'id="user-menu-'), strpos($table, '</table>'), 'The row card must render outside the table, where later sticky cells cannot paint over it.');
    }

    /**
     * The sign-out notice carries display:flex, and an author display rule beats the hidden
     * attribute, so the card covered every admin screen until the attribute was made to win.
     */
    public function test_the_sign_out_notice_stays_off_screen_until_a_block_is_answered(): void
    {
        $layout = $this->read('resources'.DIRECTORY_SEPARATOR.'views'.DIRECTORY_SEPARATOR.'layouts'.DIRECTORY_SEPARATOR.'app.blade.php');

        $this->assertMatchesRegularExpression('/#signed-out-notice\[hidden\]\s*\{\s*display:\s*none\s*!important/', $layout);
        $this->assertStringContainsString('<div id="signed-out-notice"', $layout);
        $this->assertStringContainsString('notice.hidden = false;', $layout);
    }

    public function test_validation_banners_keep_their_slot_so_fields_do_not_move_under_a_click(): void
    {
        $forms = $this->read('public'.DIRECTORY_SEPARATOR.'js'.DIRECTORY_SEPARATOR.'admin-forms.js');
        $this->assertStringNotContainsString('banner.hidden = active.length === 0;', $forms);
        $this->assertStringContainsString("banner.style.visibility = active.length ? 'visible' : 'hidden';", $forms);

        $users = $this->read('public'.DIRECTORY_SEPARATOR.'user-management.js');
        $this->assertStringNotContainsString('summary.hidden = !count;', $users);
        $this->assertStringContainsString("summary.className = 'user-summary';", $users);
        $this->assertMatchesRegularExpression('/\.user-summary\{[^}]*min-height:\d+px/', $this->read('public'.DIRECTORY_SEPARATOR.'user-management.css'));
    }

    /**
     * Every select in the app is rebuilt as a searchable listbox, so a mouse click that does not
     * reach the native select breaks the whole admin area, not one form.
     */
    public function test_a_mouse_click_on_a_dropdown_option_reaches_the_select_it_belongs_to(): void
    {
        $layout = $this->read('resources'.DIRECTORY_SEPARATOR.'views'.DIRECTORY_SEPARATOR.'layouts'.DIRECTORY_SEPARATOR.'app.blade.php');

        // Without this the panel closes between mousedown and mouseup and the click is lost.
        $this->assertStringContainsString("list.addEventListener('mousedown', (event) => event.preventDefault());", $layout);
        $this->assertStringContainsString('li.tabIndex = -1;', $layout);
        $this->assertStringContainsString('if (wrap.contains(event.relatedTarget)) return;', $layout);

        // Picking a value has to take the field out of the invalid state as well as set it.
        $this->assertMatchesRegularExpression('/function pick\([\s\S]*?aria-invalid.\) === .true.[\s\S]*?render\(\);/', $layout);
    }

    public function test_typing_a_role_name_in_a_dropdown_cannot_put_a_privileged_role_first(): void
    {
        $layout = $this->read('resources'.DIRECTORY_SEPARATOR.'views'.DIRECTORY_SEPARATOR.'layouts'.DIRECTORY_SEPARATOR.'app.blade.php');

        // One haystack of label plus value ranked every match the same way, so "admin" showed
        // Super Admin on top because its value is "superadmin".
        $this->assertStringNotContainsString('li.dataset.search', $layout);
        $this->assertStringContainsString("li.dataset.label = opt.textContent.trim().toLowerCase();", $layout);
        $this->assertStringContainsString('if (label === q) return 0;', $layout);
        $this->assertStringContainsString('if (label.startsWith(q)) return 1;', $layout);
        $this->assertStringContainsString('if (value === q) return 3;', $layout);

        // The keyboard follows the ranked order, so Enter picks what the reader sees first.
        $this->assertStringContainsString("const visible = () => Array.from(list.children)", $layout);
    }

    /**
     * A failed field has to announce itself without a person having to read the colour, so the
     * border thickens as well as turning red and the message carries its own mark. What it must
     * not do is shout: no tinted fill, no glow, no heavy type, because a form that comes back
     * looking like an alarm is harder to read than one that points at the box that needs work.
     */
    public function test_a_field_that_failed_validation_is_marked_by_more_than_its_border(): void
    {
        $layout = $this->read('resources'.DIRECTORY_SEPARATOR.'views'.DIRECTORY_SEPARATOR.'layouts'.DIRECTORY_SEPARATOR.'app.blade.php');
        $css = $this->read('public'.DIRECTORY_SEPARATOR.'user-management.css');

        // Width plus colour, so the state survives colour blindness and a grey-scale print.
        $this->assertMatchesRegularExpression('/\.field-invalid:not\(\[type=checkbox\]\)[^{]*\{[^}]*border-width: 2px/', $layout);
        $this->assertMatchesRegularExpression('/\.user-field \[aria-invalid=true\]\{[^}]*border:2px solid var\(--user-error\)/', $css);

        // The box keeps its white fill and normal ink: typed values stay the easiest thing to read.
        $this->assertMatchesRegularExpression('/\.user-field \[aria-invalid=true\]\{[^}]*background-color:#fff/', $css);
        $this->assertMatchesRegularExpression('/\.field-invalid:not\(\[type=checkbox\]\)[^{]*\{[^}]*background-color: #fff/', $layout);
        $this->assertStringNotContainsString('0 0 0 6px', $css);
        $this->assertStringNotContainsString('0 0 0 6px', $layout);

        // The message reads as a note beside the field, lighter than the label above it.
        $this->assertMatchesRegularExpression('/\.user-error\{[^}]*font-weight:400/', $css);
        $this->assertMatchesRegularExpression('/\.user-error:not\(:empty\):before\{[^}]*content:/', $css);
    }

    /**
     * "Please fix 5 errors below" counts boxes she cannot see and reads like a rap, so the banner
     * names each field and links to it instead.
     */
    public function test_the_error_banner_names_each_bad_field_and_points_at_it(): void
    {
        $forms = $this->read('public'.DIRECTORY_SEPARATOR.'js'.DIRECTORY_SEPARATOR.'admin-forms.js');

        $this->assertStringNotContainsString('errors below.', $forms);
        $this->assertStringContainsString('named here.', $forms);
        $this->assertStringContainsString("link.dataset.jumpTo = node.dataset.fieldError;", $forms);
        $this->assertStringContainsString('focusField(link.field);', $forms);

        // A required box is named with its own label, so the line is never the same sentence twice.
        $this->assertStringNotContainsString("message = 'This field is required.'", $forms);
        $this->assertStringContainsString('message = requiredMessage(field);', $forms);
        $this->assertStringContainsString("field.tagName === 'SELECT' ? 'Choose'", $forms);

        // The searchable selects hide their native element, so focus has to go to its button.
        $this->assertStringContainsString("querySelector('button[role=combobox]')", $forms);
    }

    public function test_a_details_element_used_as_a_menu_button_hides_the_disclosure_triangle(): void
    {
        $roles = $this->read('resources'.DIRECTORY_SEPARATOR.'views'.DIRECTORY_SEPARATOR.'admin'.DIRECTORY_SEPARATOR.'roles.blade.php');
        $this->assertMatchesRegularExpression('/<summary[^>]*list-none[^>]*>[^<]*⋮[^<]*<\/summary>/', $roles);
        $this->assertStringContainsString('[&::-webkit-details-marker]:hidden', $roles);
    }

    /**
     * The dropdown script rebuilds every select as a button, so a rule written for inputs and
     * selects alone leaves Role and Country as loose text with a caret next to it. One shared
     * block now sizes all four controls, and the stylesheet is cache-busted because it is served
     * straight from disk with no build step to rename it.
     */
    public function test_every_field_on_the_user_form_is_one_shared_box(): void
    {
        $css = $this->read('public'.DIRECTORY_SEPARATOR.'user-management.css');
        $controls = preg_quote('.user-control,.user-field input:not([type=checkbox]),.user-field select,.user-field [role=combobox]', '/');

        $this->assertMatchesRegularExpression('/'.$controls.'\{[^}]*min-height:46px/', $css, 'A field has to be tall enough to tap.');
        $this->assertMatchesRegularExpression('/'.$controls.'\{[^}]*border-radius:8px;padding:10px 16px/', $css, 'One radius and one inset for every field.');
        $this->assertMatchesRegularExpression('/'.$controls.'\{[^}]*background:var\(--user-fill\)/', $css, 'One fill, so a dropdown cannot read as plain text.');
        $this->assertMatchesRegularExpression('/\.user-control:hover[^{]*\{[^}]*border-color:var\(--user-border-hover\)/', $css);
        $this->assertMatchesRegularExpression('/\.user-control:focus[^{]*\{[^}]*border-color:var\(--user-green\);outline:3px solid var\(--user-ring\)/', $css, 'Focus must be a green edge and a soft ring, not a bare outline.');
        $this->assertMatchesRegularExpression('/\.user-control:disabled[^{]*\{[^}]*cursor:not-allowed/', $css);

        // The caret lives inside the box, so the label gives up pixels before it overlaps.
        $this->assertMatchesRegularExpression('/'.preg_quote('.user-field [role=combobox]>.ss-label', '/').'\{[^}]*min-width:0/', $css);
        $this->assertMatchesRegularExpression('/\.user-value-box\{[^}]*display:flex[^}]*justify-content:space-between/', $css);

        $form = $this->read('resources'.DIRECTORY_SEPARATOR.'views'.DIRECTORY_SEPARATOR.'users'.DIRECTORY_SEPARATOR.'partials'.DIRECTORY_SEPARATOR.'form.blade.php');
        $country = $this->read('resources'.DIRECTORY_SEPARATOR.'views'.DIRECTORY_SEPARATOR.'users'.DIRECTORY_SEPARATOR.'partials'.DIRECTORY_SEPARATOR.'country-fields.blade.php');
        $password = $this->read('resources'.DIRECTORY_SEPARATOR.'views'.DIRECTORY_SEPARATOR.'users'.DIRECTORY_SEPARATOR.'partials'.DIRECTORY_SEPARATOR.'password-fields.blade.php');
        $access = $this->read('resources'.DIRECTORY_SEPARATOR.'views'.DIRECTORY_SEPARATOR.'users'.DIRECTORY_SEPARATOR.'partials'.DIRECTORY_SEPARATOR.'access-fields.blade.php');
        $fields = $form.$country.$password.$access;

        foreach (['name', 'email', 'phone', 'role', 'country', 'timezone', 'password', 'password_confirmation', 'allowed_from', 'allowed_to'] as $id) {
            $this->assertMatchesRegularExpression('/<(?:input|select)[^>]*id="'.$id.'"[^>]*class="user-control"/', $fields, $id.' must carry the shared field style.');
        }

        // The clock used to be a bare sentence; the value and its Change link now share the box.
        $this->assertMatchesRegularExpression('/<div class="user-control user-value-box[^"]*"><span id="timezone-label">/', $country);
        $this->assertMatchesRegularExpression('/<button type="button" id="change-timezone"[^>]*class="user-link">Change<\/button><\/div>/', $country);

        $this->assertStringContainsString('Privileged access: review carefully before saving.', $form);

        foreach (['create', 'edit', 'index', 'password-change', 'password-enroll'] as $page) {
            $view = $this->read('resources'.DIRECTORY_SEPARATOR.'views'.DIRECTORY_SEPARATOR.'users'.DIRECTORY_SEPARATOR.$page.'.blade.php');
            $this->assertStringContainsString("filemtime(public_path('user-management.css'))", $view, $page.' must not be shown a cached stylesheet.');
        }
    }

    /**
     * The desk's country decides which wallets and which money a person is shown, so it
     * has to be readable on the page they land on, including when it was never filled in.
     */
    public function test_the_dashboard_names_the_desk_country_and_says_so_when_it_is_missing(): void
    {
        $dashboard = $this->read('resources'.DIRECTORY_SEPARATOR.'views'.DIRECTORY_SEPARATOR.'dashboard.blade.php');
        $layout = $this->read('resources'.DIRECTORY_SEPARATOR.'views'.DIRECTORY_SEPARATOR.'layouts'.DIRECTORY_SEPARATOR.'app.blade.php');

        $this->assertStringContainsString('$me->countryLabel()', $dashboard);
        $this->assertStringContainsString("'Desk country not set'", $dashboard);
        $this->assertStringContainsString("'Desk country not set'", $layout);

        // Two wallets in a three column grid left a hole at the right of the page.
        $this->assertStringContainsString('grid grid-cols-1 sm:grid-cols-2 gap-4 mb-6', $dashboard);
        $this->assertStringNotContainsString('grid-cols-1 md:grid-cols-3 gap-4 mb-6', $dashboard);

        $this->assertStringNotContainsString('Welcome, {{ auth()->user()->name }}', $dashboard);
        $this->assertStringContainsString('$greeting', $dashboard);
    }

    /**
     * The same money used to be summed into three blocks under three labels, so a reader had to
     * work out which of the four "Money Sent" figures to trust. One window at a time now, and a
     * number that will not fit its card gives up pixels instead of spilling over the border.
     */
    public function test_the_dashboard_prints_each_figure_once_and_keeps_a_long_one_inside_its_card(): void
    {
        $dashboard = $this->read('resources'.DIRECTORY_SEPARATOR.'views'.DIRECTORY_SEPARATOR.'dashboard.blade.php');

        foreach ([
            "Today's Summary", 'Total Transactions', 'Charge (All Time)', 'Total RMB Sent',
            'Still Pending', 'Active Exchange Rates', 'Completed Today', 'Money Sent (',
            'Exchange Profit', 'Avg Transaction',
        ] as $gone) {
            $this->assertStringNotContainsString($gone, $dashboard, $gone.' is the same figure under a second label.');
        }

        $this->assertSame(1, substr_count($dashboard, 'role="tablist"'), 'One window picker, not three blocks.');
        $this->assertSame(1, substr_count($dashboard, 'role="tab" '), 'One tab template, repeated per window.');
        $this->assertSame(1, substr_count($dashboard, 'role="tabpanel"'));
        $this->assertStringContainsString("['today' => 'Today', 'month' => 'This Month', 'all' => 'All Time']", $dashboard);

        $this->assertStringContainsString('.stat-card { container-type: inline-size; }', $dashboard);
        $this->assertMatchesRegularExpression('/\.stat-value \{[^}]*clamp\([^}]*100cqi[^}]*var\(--len\)/', $dashboard);
        $this->assertStringContainsString('style="--len: {{ strlen($cardValue) }}"', $dashboard);
        $this->assertStringContainsString('whitespace-nowrap', $dashboard);

        // The paragraph that explained the profit figures is a tooltip on the heading now.
        $this->assertStringNotContainsString('never guessed', $dashboard);
        $this->assertSame(1, substr_count($dashboard, 'role="tooltip"'));
    }

    /**
     * Eight columns of a receipt table crush into one letter per line on a phone unless the
     * table is allowed to scroll inside its own card. Below the tablet width the table is
     * replaced by a card list, because eight columns never fit a phone without sideways scroll.
     */
    public function test_the_recent_transactions_table_scrolls_instead_of_shrinking_its_headers(): void
    {
        $dashboard = $this->read('resources'.DIRECTORY_SEPARATOR.'views'.DIRECTORY_SEPARATOR.'dashboard.blade.php');

        $this->assertMatchesRegularExpression('/<div class="[^"]*overflow-auto[^"]*">\s*<table class="w-full min-w-\[\d+px\]/', $dashboard);
        $this->assertStringContainsString('<thead class="bg-gray-50 sticky top-0', $dashboard, 'The header row stays in sight while the list scrolls.');
        $this->assertSame(8, substr_count($dashboard, 'tracking-wide whitespace-nowrap">'), 'Every header cell stays on one line.');
        $this->assertMatchesRegularExpression('/<ul class="md:hidden[^"]*">/', $dashboard, 'A phone gets cards instead of the table.');
    }
}
