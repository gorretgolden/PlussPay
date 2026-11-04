<?php

namespace Tests\Feature;

use App\Models\ExchangeRate;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * The Payment step is typed at a counter with a customer waiting, so each field carries one
 * short line, the long explanations sit in tooltips, and the card answers the only question
 * that matters at the counter: what the receiver gets. The Review step is checked here as well
 * because it is the same card, and the two must never show different figures.
 */
class WizardPaymentStepTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['superadmin', 'agent'] as $role) {
            Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        }

        ExchangeRate::setPair('USD', 'UGX', 3760, 3750, 3760);
        ExchangeRate::setPair('USD', 'RMB', 7.24, 7.24, 7.24);
    }

    private function page(string $role, string $path = '/transactions/create', string $country = 'UG'): string
    {
        $user = User::create([
            'name' => 'Desk '.ucfirst($role),
            'email' => strtolower($role).uniqid().'@plussave.test',
            'password' => 'Password123',
            'is_active' => true,
            'country' => $country,
        ]);
        $user->assignRole($role);

        return $this->actingAs($user)->get($path)->getContent();
    }

    /**
     * Only the Payment step is in scope here. The review step keeps its own wording, so a
     * "this label is gone" rule has to be read against the step it belongs to.
     */
    private function step3(string $html): string
    {
        $from = strpos($html, 'id="step-3"');
        $to = strpos($html, 'id="step-4"');

        $this->assertIsInt($from);
        $this->assertIsInt($to);

        return substr($html, $from, $to - $from);
    }

    public function test_the_step_lets_the_stepper_do_the_talking(): void
    {
        $html = $this->page('agent');

        $this->assertStringNotContainsString('Complete each step to create a new transaction', $html);
        $this->assertStringContainsString('Send Money to China', $html);
        $this->assertStringContainsString('Payment Details', $html);
    }

    public function test_every_step_in_the_strip_is_a_button_that_knows_where_it_leads(): void
    {
        $html = $this->page('agent');

        foreach ([1 => 'Sender', 2 => 'Receiver', 3 => 'Payment', 4 => 'Confirm'] as $step => $label) {
            $this->assertMatchesRegularExpression(
                '/<button type="button" class="[^"]*step-indicator[^"]*" data-step="'.$step.'" onclick="goToStep\('.$step.'\)" aria-label="Go to step '.$step.': '.$label.'"/',
                $html
            );
        }

        // The current step is announced as such, and the script keeps that marker in step.
        $this->assertStringContainsString('aria-current="step"', $html);
        $this->assertStringContainsString("ind.setAttribute('aria-current', 'step');", $html);

        // Forward has to clear each step on the way; backward is free. The Payment step keeps the
        // disabled Next button's own rule, so a click cannot walk past a charge that leaves nothing
        // to send.
        $this->assertStringContainsString('function goToStep(target)', $html);
        $this->assertStringContainsString("if (step === 3 && step3Hint() !== '') return;", $html);
    }

    public function test_every_field_carries_one_short_line_and_the_detail_sits_in_a_tooltip(): void
    {
        $html = $this->page('agent');

        foreach ([
            'Choose what the customer pays in.',
            'Total received, charge included.',
            'Type what you kept from this customer.',
        ] as $line) {
            $this->assertStringContainsString($line, $html);
        }

        // Nothing the tooltips carry may spill back into the lines under the fields.
        foreach (['UGX if the customer pays in shillings', 'Start point from System Settings'] as $gone) {
            $this->assertStringNotContainsString($gone, $html);
        }

        $this->assertStringContainsString('shillings, where the charge comes out of that money', $html);
        $this->assertStringContainsString('You agree this per customer, so nothing is filled in for you. Type what you kept, or 0 when nothing was taken.', $html);
        // The per-dollar fee rule is gone, so no sentence may point at it.
        $this->assertStringNotContainsString('Settings suggest', $html);
        $this->assertStringNotContainsString('Default from Settings', $html);
        // One tooltip per field that needs more than its short line: the money handed over, the
        // charge, the money that travels, the payout and the money it is taken in. A hand with one
        // road has no route question to explain, and the office picker is not shown to an agent.
        $this->assertSame(5, substr_count($html, 'role="tooltip"'));
    }

    /**
     * The cash is always handed over where the desk stands, so a desk has one road and sees it as
     * a stated fact rather than a question. Only the office, which keys in for both desks, still
     * picks from two cards, and a query string cannot turn a hand around.
     */
    public function test_a_desk_is_pinned_to_the_road_that_starts_at_its_own_counter(): void
    {
        $uganda = $this->page('agent');
        $stubborn = $this->page('agent', '/transactions/create?destination=UG');
        $china = $this->page('agent', '/transactions/create', 'CN');

        // A Uganda hand sends to China, and the form says so instead of asking. Neither route card
        // is drawn, so nothing offers to pay somebody back in Uganda.
        $this->assertStringNotContainsString('Money to a receiver in China', $uganda);
        $this->assertStringNotContainsString('Money to a receiver in Uganda', $uganda);
        $this->assertStringNotContainsString('Which way is the money going?', $uganda);
        $this->assertSame(1, substr_count($uganda, 'name="destination"'), 'One hidden answer, no cards.');
        $this->assertMatchesRegularExpression('#<input type="hidden" name="destination" value="CN">#', $uganda);
        $this->assertStringContainsString('Cash is handed over here in Uganda, in shillings or dollars.', $uganda);
        $this->assertStringContainsString('<h1 class="text-2xl font-bold text-gray-900" id="page_title">Send Money to China</h1>', $uganda);

        // The shortcut used to carry the route in the address; an agent editing it by hand must
        // still land on their own road, never the far one.
        $this->assertMatchesRegularExpression('#<input type="hidden" name="destination" value="CN">#', $stubborn);
        $this->assertStringContainsString('id="page_title">Send Money to China</h1>', $stubborn);

        // The China desk is the mirror: cash over there, paid here in shillings.
        $this->assertStringNotContainsString('Money to a receiver in Uganda', $china);
        $this->assertStringNotContainsString('Money to a receiver in China', $china);
        $this->assertMatchesRegularExpression('#<input type="hidden" name="destination" value="UG">#', $china);
        $this->assertStringContainsString('Cash is handed over here in China, in yuan or dollars.', $china);
        $this->assertStringContainsString('<h1 class="text-2xl font-bold text-gray-900" id="page_title">Send Money to Uganda</h1>', $china);

        // The script reads the hidden answer as the route, or the China desk would calculate the
        // whole page as if the money were going to China.
        $this->assertStringContainsString("document.querySelector('input[type=hidden][name=destination]')", $uganda);
    }

    public function test_the_office_still_picks_between_the_two_roads(): void
    {
        $open = $this->page('superadmin');
        $asked = $this->page('superadmin', '/transactions/create?destination=UG');

        foreach (['Money to a receiver in China', 'Money to a receiver in Uganda', 'Which way is the money going?'] as $title) {
            $this->assertStringContainsString($title, $open);
        }

        $this->assertMatchesRegularExpression('#name="destination" value="CN"[^>]*checked#', $open);
        $this->assertMatchesRegularExpression('#name="destination" value="UG"[^>]*checked#', $asked);
        $this->assertDoesNotMatchRegularExpression('#name="destination" value="CN"[^>]*checked#', $asked);
        $this->assertSame(2, substr_count($asked, 'name="destination"'), 'Two cards, one question.');
        $this->assertStringContainsString('<h1 class="text-2xl font-bold text-gray-900" id="page_title">Send Money to Uganda</h1>', $asked);

        // Each card says which money it takes in, so nobody picks a route and then finds the boxes
        // do not hold the money the customer is standing there with.
        $this->assertStringContainsString('Cash is handed over here in Uganda, in shillings or dollars.', $open);
        $this->assertStringContainsString('Cash is handed over in China, in yuan or dollars.', $open);
    }

    public function test_the_payout_is_chosen_on_the_way_to_china_and_fixed_on_the_way_to_uganda(): void
    {
        $china = $this->page('agent');
        $uganda = $this->page('agent', '/transactions/create', 'CN');

        // A receiver in China is paid in either of two currencies, so the form asks. A receiver in
        // Uganda is only ever paid shillings, so the box is still posted for the server to check but
        // the route script hides the choice.
        $this->assertMatchesRegularExpression('#<select name="payout_currency"[^>]*required#', $china);
        $this->assertStringContainsString('Receiver gets', $china);
        $this->assertStringContainsString('id="payout_wrap"', $uganda, 'The same field is on the page so a draft can carry it.');
        $this->assertStringContainsString("document.getElementById('payout_wrap').classList.toggle('hidden', !r.payoutChoice);", $china);
        $this->assertStringContainsString('payoutChoice: false', $china, 'The Uganda route is the one with a single answer.');
        foreach (['value="RMB" data-routes="CN"', 'value="USD" data-routes="CN"', 'value="UGX" data-routes="UG"'] as $option) {
            $this->assertStringContainsString($option, $china, 'The payout box offers '.$option.'.');
        }

        // Both corridors' intake moneys are on the sheet and the script hides the ones the route
        // refuses: shillings only come in here, and yuan only comes in from China.
        foreach (['value="UGX" data-routes="CN"', 'value="USD" data-routes="CN,UG"', 'value="RMB" data-routes="UG"'] as $option) {
            $this->assertStringContainsString($option, $china, 'The intake box offers '.$option.'.');
        }
        $this->assertStringNotContainsString('>Ugandan Shilling<', $china);
        $this->assertStringNotContainsString('>US Dollar<', $china);
    }

    public function test_the_currency_options_are_bare_codes(): void
    {
        $html = $this->page('agent');

        preg_match('/<select name="currency".*?<\/select>/s', $html, $match);

        $this->assertStringContainsString('>UGX</option>', $match[0] ?? '');
        $this->assertStringContainsString('>USD</option>', $match[0] ?? '');
        $this->assertStringContainsString('>RMB</option>', $match[0] ?? '', 'The yuan a customer hands over in China is offered on the same box.');
        $this->assertStringNotContainsString('Ugandan Shilling', $match[0] ?? '');
        $this->assertStringNotContainsString('US Dollar', $match[0] ?? '');

        // The payout box is named the same way, so the two selects never spell the same money twice.
        preg_match('/<select name="payout_currency".*?<\/select>/s', $html, $payout);

        foreach (['>RMB</option>', '>USD</option>', '>UGX</option>'] as $option) {
            $this->assertStringContainsString($option, $payout[0] ?? '');
        }
        $this->assertStringNotContainsString('Chinese', $payout[0] ?? '');
    }

    public function test_the_card_leads_with_what_the_receiver_gets_and_holds_one_line_before_an_amount_exists(): void
    {
        $step = $this->step3($this->page('agent'));

        // Until an amount is typed the card is one quiet line, not a row of dashes or zeros.
        $this->assertStringContainsString('Enter an amount to see the calculation.', $step);
        $this->assertStringContainsString('class="hidden" data-c="body"', $step);
        $this->assertStringContainsString('Receiver gets', $step);

        // The working is three numbered rows the cashier can read top to bottom. The third row is
        // named for the payout rather than for yuan, because on two of the three routes the
        // receiver is not paid in yuan at all.
        foreach (['cash-label', 'charge-label', 'travels-label', 'usd-label', 'payout-label', 'payout-line', 'payout-hint', 'payout-glyph', 'payout-total', 'rates'] as $key) {
            $this->assertSame(1, substr_count($step, 'data-c="'.$key.'"'), $key.' must appear once on the step.');
        }

        foreach (['RMB receiver gets', 'Credited in USD', 'Money That Travels', 'Amount converted', 'Step 1:', 'data-c="rmb-label"'] as $gone) {
            $this->assertStringNotContainsString($gone, $step);
        }

        // The rate line is written by the script from the live prices, and names which side of the
        // spread each price is, because that is the difference the desk earns on.
        $html = $this->page('agent');
        $this->assertStringContainsString("'1 USD = UGX ' + rateLabel(usdSellRate)", $html);
        $this->assertStringContainsString("'1 USD = UGX ' + rateLabel(usdBuyRate)", $html);
        $this->assertStringContainsString("'1 USD = ' + rateLabel(rmbSellRate) + ' yuan'", $html);
        $this->assertStringContainsString("'1 USD = ' + rateLabel(rmbBuyRate) + ' yuan'", $html);
    }

    /**
     * The desk asked for the figure everything is priced from to sit beside the charge that
     * produces it rather than only further down the page, so the step carries it twice: as a
     * read-only box after the charge, and as the strong row of the card. The box carries no name
     * attribute, so a derived number can never be posted in place of a field the server checks.
     */
    public function test_the_amount_that_travels_is_read_back_beside_the_charge_that_produced_it(): void
    {
        $step = $this->step3($this->page('agent'));
        $html = $this->page('agent');

        $charge = strpos($step, 'name="charge_ugx"');
        $travels = strpos($step, 'id="travels_out"');
        $purpose = strpos($step, 'name="purpose"');

        $this->assertIsInt($charge);
        $this->assertIsInt($travels);
        $this->assertIsInt($purpose);
        $this->assertLessThan($travels, $charge, 'The box follows the charge.');
        $this->assertLessThan($purpose, $travels, 'and comes before the purpose.');

        $this->assertMatchesRegularExpression('/<input type="text" id="travels_out" readonly aria-readonly="true"/', $step);
        $this->assertStringNotContainsString('name="travels_out"', $step);
        $this->assertStringContainsString("travelsEl.value = f.cash > 0 ? showMoney(f.currency, f.travels) : '';", $html);

        // One name for the figure on this step, painted by the one renderer, and no second copy
        // of the strip that used to hold it.
        $this->assertStringContainsString('>Amount that travels</label>', $step);
        $this->assertStringContainsString("calcSet(panel, 'travels', showMoney(f.currency, f.travels));", $html);
        $this->assertSame(1, substr_count($step, 'data-c="travels"'));

        foreach (['balance_strip', 'Balance after the charge', 'preview_sent'] as $gone) {
            $this->assertStringNotContainsString($gone, $html);
        }
    }

    /**
     * Rounding the dollars before the yuan conversion priced the payout off a credit nobody was
     * given, so the script must divide at full precision and round only on the way out.
     */
    public function test_the_dollars_are_divided_at_full_precision_before_the_payout(): void
    {
        $html = $this->page('agent');

        // Shillings are divided by the selling price and yuan by the buying price, at full
        // precision, and the rounded dollars are only ever the figure shown or stored.
        $this->assertStringContainsString('const exact = travels > 0', $html);
        $this->assertStringContainsString('paidInUgx && usdSellRate > 0 ? travels / usdSellRate', $html);
        $this->assertStringContainsString('inYuan && rmbBuyRate > 0 ? travels / rmbBuyRate : travels', $html);
        $this->assertStringContainsString('const dollars = Number(exact.toFixed(2));', $html);

        // Every far-end figure is priced off the unrounded dollars, so rounding the dollars first
        // cannot leak into the payout on any of the three routes.
        $this->assertStringContainsString('(exact * rmbSellRate).toFixed(2)', $html);
        $this->assertStringContainsString('(exact * usdBuyRate).toFixed(2)', $html);
        $this->assertStringNotContainsString('(dollars * rmbSellRate)', $html);
        $this->assertStringNotContainsString('toFixed(1)', $html);

        // Both panels are painted by one renderer, so the review step cannot disagree.
        $this->assertSame(2, substr_count($html, 'data-calc-panel='));
        $this->assertStringNotContainsString('derivation(', $html);
    }

    public function test_the_rates_link_is_small_renamed_and_still_gated(): void
    {
        $this->assertStringNotContainsString('Update rates', $this->page('agent'));

        $html = $this->page('superadmin');

        $this->assertStringContainsString('Update rates', $html);
        $this->assertStringNotContainsString('Change the prices', $html);
        $this->assertMatchesRegularExpression('/<a href="[^"]*settings"[^>]*class="text-xs[^"]*">Update rates<\/a>/', $html);
    }

    public function test_the_note_opens_with_the_usual_note_already_in_the_box(): void
    {
        $html = $this->page('agent');

        $this->assertStringContainsString('value="Sending money to China"', $html);
        $this->assertStringNotContainsString('placeholder="Sending money to China"', $html);
        $this->assertStringContainsString('maxlength="500"', $html);
    }

    public function test_the_money_boxes_are_typed_with_separators_and_sent_as_plain_numbers(): void
    {
        $html = $this->page('agent');

        foreach (['amount', 'charge_ugx'] as $id) {
            $this->assertMatchesRegularExpression('/<input type="text"[^>]*inputmode="decimal"[^>]*data-money[^>]*name="'.$id.'"/', $html);
        }

        $this->assertStringContainsString('function formatMoney(el)', $html);
        $this->assertStringContainsString("el.value = String(parseMoney(el));", $html);

        // The charge opens empty every time: no script and no setting fills it in.
        $this->assertStringNotContainsString('suggestCharge', $html);
        $this->assertStringNotContainsString('serviceFee', $html);
        $this->assertMatchesRegularExpression('/<input[^>]*name="charge_ugx" id="charge_ugx" value=""/', $this->step3($html));
    }

    /**
     * The default charge is a starting point typed once in Settings, never a rule: the box
     * opens at it and the cashier still owns the figure for each customer.
     */
    public function test_the_charge_box_opens_at_the_default_charge_and_stays_editable(): void
    {
        \App\Models\Setting::set('default_charge_ugx', '2000', 'float');

        $step = $this->step3($this->page('agent'));

        $this->assertMatchesRegularExpression('/<input[^>]*name="charge_ugx" id="charge_ugx" value="2000"/', $step);
        $this->assertStringContainsString('Starts at the default charge from Settings.', $step);
        $this->assertStringContainsString('The box opens at the default charge from System Settings.', $step);
        $this->assertStringNotContainsString('disabled', preg_replace('/<button.*?<\/button>/s', '', $step));

        // The old rate card wording stays gone, on the step and in the tooltips.
        $this->assertStringNotContainsString('per 100 USD', $step);
        $this->assertStringNotContainsString('Default from Settings. Editable.', $step);
    }

    public function test_the_step_pairs_up_on_desktop_and_the_next_button_waits_for_valid_input(): void
    {
        $html = $this->page('agent');

        preg_match('/<div class="wizard-step[^"]*" id="step-3">(.*?)<div class="rounded-2xl/s', $html, $match);

        $this->assertStringContainsString('grid grid-cols-1 md:grid-cols-2 gap-6', $match[0] ?? '');
        // Six fields now, so they pair up three rows deep and nothing needs to span the row.
        $this->assertStringNotContainsString('md:col-span-2', $match[0] ?? '');
        $this->assertStringNotContainsString('md:grid-cols-3', $match[0] ?? '');

        $this->assertStringContainsString('id="next-3"', $html);
        $this->assertStringContainsString("document.getElementById('next-3').disabled = hint !== '' || warning !== '';", $html);
        $this->assertStringContainsString('← Back', $html);
        $this->assertStringContainsString('Next: Review →', $html);
    }

    public function test_the_two_money_routes_are_chosen_before_any_figure_is_typed(): void
    {
        $html = $this->page('agent');

        preg_match('/<div class="wizard-step[^"]*" id="step-3">(.*?)<div class="rounded-2xl/s', $html, $match);
        $step = $match[0] ?? '';

        // A two column grid pairs what it prints in source order, so the first four fields are the
        // two rows the desk reads top to bottom: what came in beside what goes out, then the cash
        // and the charge taken out of it.
        $currency = strpos($step, 'for="currency"');
        $payout = strpos($step, 'for="payout_currency"');
        $amount = strpos($step, 'for="amount"');
        $charge = strpos($step, 'for="charge_ugx"');

        $this->assertNotFalse($currency, 'The step carries the money in.');
        $this->assertNotFalse($payout, 'The step carries the money out.');
        $this->assertLessThan($payout, $currency, 'Handed over in leads its row.');
        $this->assertLessThan($amount, $payout, 'Receiver gets stands beside Handed over in, not two rows down.');
        $this->assertLessThan($charge, $amount, 'The cash and its charge stay on one row.');
    }

    public function test_a_money_figure_outside_the_bounds_warns_at_the_top_of_the_form(): void
    {
        $html = $this->page('agent');

        // The warning sits above step 1 so it is on screen while the money is typed on step 2,
        // not under the review button two steps away.
        $bannerAt = strpos($html, 'id="bounds_warning"');
        $step1At = strpos($html, 'id="step-1"');
        $this->assertNotFalse($bannerAt);
        $this->assertNotFalse($step1At);
        $this->assertLessThan($step1At, $bannerAt, 'The warning stands above every wizard step.');

        $this->assertStringContainsString('role="alert"', $html);
        $this->assertStringContainsString('function boundsMessage()', $html);
        $this->assertStringContainsString('The smallest booking is', $html);
        $this->assertStringContainsString('The largest booking is', $html);
    }

    /**
     * The box is a flex row, and a flex utility outranks the display rule behind the hidden
     * attribute, so putting it away with the attribute left an empty yellow strip on screen all
     * day. Only the hidden class actually hides it, and the script has to keep using that one.
     */
    public function test_an_unused_warning_is_put_away_with_the_class_that_beats_flex(): void
    {
        $html = $this->page('agent');

        preg_match('/<div id="bounds_warning"[^>]*>/', $html, $box);

        $this->assertMatchesRegularExpression('/class="[^"]*\bhidden\b[^"]*"/', $box[0] ?? '');
        // The attribute is the one that does nothing here, so it may not reappear.
        $this->assertDoesNotMatchRegularExpression('/\shidden(\s|=|>)/', $box[0] ?? '');

        $this->assertStringContainsString("document.getElementById('bounds_warning').classList.toggle('hidden', warning === '');", $html);
    }

    /**
     * A shilling charge is deducted from the money on the counter and a dollar charge is added on
     * top in shillings, so the line under the charge box cannot be one sentence for both. It is
     * written from the money the customer actually handed over.
     */
    public function test_the_charge_line_follows_the_money_handed_over(): void
    {
        $html = $this->page('agent');

        $this->assertStringContainsString('Taken out of the money handed over.', $html);
        $this->assertStringContainsString('Collected on top, in shillings.', $html);
        $this->assertStringContainsString('Taken out of the shillings paid to the receiver.', $html);
        $this->assertStringContainsString("document.getElementById('charge_help').textContent = chargeHelpBase + ' ' + chargeNote(f.route, f.currency);", $html);
    }

    /**
     * The card and the box beside it are read side by side, so the same figure cannot appear as
     * ¥656 in one place and ¥656.00 in the other. Shillings have no smaller note, so they carry
     * no decimals anywhere.
     */
    public function test_each_money_prints_the_same_way_everywhere_it_appears(): void
    {
        $html = $this->page('agent');

        $this->assertStringContainsString("'UGX ' + n.toLocaleString(undefined, {maximumFractionDigits: 0})", $html);

        preg_match('/function showMoney\(.*?\n\}/s', $html, $printer);
        $this->assertMatchesRegularExpression("/currency === 'RMB'.*minimumFractionDigits: 2, maximumFractionDigits: 2/s", $printer[0] ?? '');
        $this->assertMatchesRegularExpression("/return '\\\$'.*minimumFractionDigits: 2, maximumFractionDigits: 2/s", $printer[0] ?? '');
    }

    /**
     * The booking is already in the boxes when the bar appears, so the bar only says what happened
     * and offers the one action still open. Starting over wipes typed work, so it asks first.
     */
    public function test_the_restored_draft_is_announced_once_and_starting_over_is_confirmed(): void
    {
        $html = $this->page('agent');

        $this->assertStringContainsString('Restored your unfinished transaction for', $html);
        $this->assertStringContainsString('>Start over</button>', $html);
        $this->assertStringNotContainsString('Edit Draft', $html);
        $this->assertStringNotContainsString('Welcome back', $html);
        $this->assertStringNotContainsString('saved in this browser', $html);

        // Nothing may point at a page that no longer answers the question, and no key may be
        // left behind for a switch that has been taken out.
        $this->assertSame(0, substr_count($html, 'keep_draft'), 'The keep-draft switch is gone.');
        $this->assertStringNotContainsString('sessionStorage', $html);

        $this->assertStringContainsString('await askConfirm({', $html);
        $this->assertStringContainsString('title: \'Start this booking over?\'', $html);
    }

    /**
     * The review step is the same card with the customer-facing wording, painted by the one
     * renderer, so there is no second summary that can drift from the first.
     */
    public function test_the_review_step_reuses_the_card_and_names_the_saving_words(): void
    {
        $html = $this->page('agent');

        $this->assertStringContainsString('data-calc-panel="review"', $html);
        $this->assertStringContainsString('id="calc-body-review"', $html);

        foreach (['Money handed over', 'Charge taken out', 'Money that travels', 'Dollars credited', 'RMB to receiver'] as $label) {
            $this->assertStringContainsString($label, $html);
        }

        // The five column strip it replaced is gone, along with its own ids.
        foreach (['rev_amount', 'rev_charge', 'rev_sent', 'rev_usd', 'rev_rmb', 'rev_total_note'] as $gone) {
            $this->assertSame(0, substr_count($html, $gone), $gone.' should not survive the rebuild.');
        }
    }

    public function test_the_review_step_books_the_transfer_without_promising_a_receipt(): void
    {
        $html = $this->page('agent');

        $note = strpos($html, 'The transfer is finished as soon as you book it.');
        $book = strpos($html, 'id="submitBtn"');

        $this->assertIsInt($note);
        $this->assertIsInt($book);
        $this->assertLessThan($book, $note, 'the warning must sit above the button, not under it');

        $this->assertMatchesRegularExpression('/id="submitBtn"[^>]*>\s*Book transfer\s*<\/button>/s', $html);
        // The button and the fields freeze together once the booking is on its way.
        $this->assertMatchesRegularExpression('/<form[^>]*id="transactionForm"[^>]*data-lock[^>]*data-lock-label="Booking\.\.\."/s', $html);
        $this->assertStringNotContainsString('Book &amp; Generate Receipt', $html);
        $this->assertStringNotContainsString('Processing...', $html);

        // A contact nobody typed is left out instead of printed as missing.
        $this->assertStringNotContainsString('Not provided', $html);
        $this->assertStringContainsString("classList.toggle('hidden', !filled);", $html);
    }
}
