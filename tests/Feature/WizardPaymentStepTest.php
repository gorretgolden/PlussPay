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

    private function page(string $role): string
    {
        $user = User::create([
            'name' => 'Desk '.ucfirst($role),
            'email' => strtolower($role).uniqid().'@plussave.test',
            'password' => 'Password123',
            'is_active' => true,
        ]);
        $user->assignRole($role);

        return $this->actingAs($user)->get('/transactions/create')->getContent();
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

        $this->assertStringContainsString('UGX: the customer pays shillings, the charge comes out of that money', $html);
        $this->assertStringContainsString('You agree this per customer, so nothing is filled in for you. Type what you kept, or 0 when nothing was taken.', $html);
        // The per-dollar fee rule is gone, so no sentence may point at it.
        $this->assertStringNotContainsString('Settings suggest', $html);
        $this->assertStringNotContainsString('Default from Settings', $html);
        $this->assertSame(3, substr_count($html, 'role="tooltip"'));
    }

    public function test_the_currency_options_are_bare_codes(): void
    {
        $html = $this->page('agent');

        preg_match('/<select name="currency".*?<\/select>/s', $html, $match);

        $this->assertStringContainsString('>UGX</option>', $match[0] ?? '');
        $this->assertStringContainsString('>USD</option>', $match[0] ?? '');
        $this->assertStringNotContainsString('Ugandan Shilling', $match[0] ?? '');
        $this->assertStringNotContainsString('US Dollar', $match[0] ?? '');
    }

    public function test_the_card_leads_with_the_yuan_and_holds_one_line_before_an_amount_exists(): void
    {
        $step = $this->step3($this->page('agent'));

        // Until an amount is typed the card is one quiet line, not a row of dashes or zeros.
        $this->assertStringContainsString('Enter an amount to see the calculation.', $step);
        $this->assertStringContainsString('class="hidden" data-c="body"', $step);
        $this->assertStringContainsString('Receiver gets', $step);

        // The working is three numbered rows the cashier can read top to bottom.
        foreach (['cash-label', 'charge-label', 'travels-label', 'usd-label', 'rmb-label', 'rates'] as $key) {
            $this->assertSame(1, substr_count($step, 'data-c="'.$key.'"'), $key.' must appear once on the step.');
        }

        foreach (['RMB receiver gets', 'Credited in USD', 'Money That Travels', 'Amount converted', 'Step 1:'] as $gone) {
            $this->assertStringNotContainsString($gone, $step);
        }

        // The rate line is written by the script from the live prices, shillings then yuan.
        $html = $this->page('agent');
        $this->assertStringContainsString("'1 USD = UGX ' + rateLabel(usdSellRate) + ' · ' : ''", $html);
        $this->assertStringContainsString("'1 USD = ' + rateLabel(rmbSellRate) + ' RMB'", $html);
    }

    /**
     * The balance left after the charge is the figure everything else is priced from, and the
     * card's first row already carries it, so it must not be repeated in its own box.
     */
    public function test_the_balance_that_travels_is_the_strong_row_of_the_card(): void
    {
        $step = $this->step3($this->page('agent'));
        $html = $this->page('agent');

        $this->assertStringContainsString('Amount that travels', $step);
        $this->assertSame(1, substr_count($step, 'data-c="travels"'));
        $this->assertStringContainsString("calcSet(panel, 'travels', showMoney(f.currency, f.sent));", $html);

        foreach (['balance_strip', 'Balance after the charge', 'preview_sent'] as $gone) {
            $this->assertStringNotContainsString($gone, $html);
        }
    }

    /**
     * Rounding the dollars before the yuan conversion priced the payout off a credit nobody was
     * given, so the script must divide at full precision and round only on the way out.
     */
    public function test_the_dollars_are_divided_at_full_precision_before_the_yuan(): void
    {
        $html = $this->page('agent');

        $this->assertStringContainsString('const exact = travels > 0 ? (paidInUgx && usdSellRate > 0 ? travels / usdSellRate : travels) : 0;', $html);
        $this->assertStringContainsString('payout: rmbSellRate > 0 ? Number((exact * rmbSellRate).toFixed(2)) : 0', $html);
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
        $this->assertStringContainsString('md:col-span-2', $match[0] ?? '');
        $this->assertStringNotContainsString('md:grid-cols-3', $match[0] ?? '');

        $this->assertStringContainsString('id="next-3"', $html);
        $this->assertStringContainsString("document.getElementById('next-3').disabled = hint !== '';", $html);
        $this->assertStringContainsString('← Back', $html);
        $this->assertStringContainsString('Next: Review →', $html);
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
        $this->assertStringContainsString("btn.textContent = 'Booking...';", $html);
        $this->assertStringNotContainsString('Book &amp; Generate Receipt', $html);
        $this->assertStringNotContainsString('Processing...', $html);

        // A contact nobody typed is left out instead of printed as missing.
        $this->assertStringNotContainsString('Not provided', $html);
        $this->assertStringContainsString("classList.toggle('hidden', !filled);", $html);
    }
}
