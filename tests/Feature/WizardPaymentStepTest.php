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
 * that matters at the counter: what the receiver gets.
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

        $this->assertStringContainsString('Enter an amount to see the total.', $step);
        $this->assertStringContainsString('id="calc_rows" class="hidden"', $step);
        $this->assertStringContainsString('RMB receiver gets', $step);
        $this->assertStringContainsString('Credited in USD', $step);

        foreach (['Money That Travels', 'Dollars Credited', 'What the receiver is credited at', 'RMB Receiver Gets', 'Amount converted'] as $gone) {
            $this->assertStringNotContainsString($gone, $step);
        }

        $this->assertStringContainsString('1 USD = 7.24 RMB', $step);
    }

    /**
     * The balance is the figure the whole calculation runs on, so it gets its own line below
     * the fields rather than living only inside the card. It appears once on the step.
     */
    public function test_the_balance_after_the_charge_is_shown_below_the_fields(): void
    {
        $step = $this->step3($this->page('agent'));

        $notes = strpos($step, 'id="notes"');
        $strip = strpos($step, 'id="balance_strip"');
        $card = strpos($step, 'Live Calculation');

        $this->assertIsInt($strip);
        $this->assertLessThan($strip, $notes, 'the balance must sit below the fields');
        $this->assertLessThan($card, $strip, 'the balance must sit above the calculation card');

        $this->assertStringContainsString('sm:p-5 hidden" id="balance_strip"', $step);
        $this->assertStringContainsString('Balance after the charge', $step);
        $this->assertStringContainsString('This is the money turned into dollars, then into yuan.', $step);
        $this->assertSame(1, substr_count($step, 'id="balance_amount"'));

        $html = $this->page('agent');
        $this->assertStringContainsString("getElementById('balance_amount').textContent = showMoney(f.currency, f.sent)", $html);
        $this->assertStringContainsString('Paid in dollars, so all of them travel. The charge is collected on top in shillings.', $html);
        $this->assertStringNotContainsString('preview_sent', $html);
    }

    public function test_the_rates_link_is_small_renamed_and_still_gated(): void
    {
        $this->assertStringNotContainsString('Update rates', $this->page('agent'));

        $html = $this->page('superadmin');

        $this->assertStringContainsString('Update rates', $html);
        $this->assertStringNotContainsString('Change the prices', $html);
        $this->assertMatchesRegularExpression('/<a href="[^"]*settings"[^>]*class="text-xs[^"]*">Update rates<\/a>/', $html);
    }

    public function test_the_note_sits_in_the_field_as_a_suggestion_only(): void
    {
        $html = $this->page('agent');

        $this->assertStringContainsString('placeholder="Sending money to China"', $html);
        $this->assertStringNotContainsString('value="Sending money to China"', $html);
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

        preg_match('/<div class="wizard-step[^"]*" id="step-3">(.*?)<div class="bg-green-50/s', $html, $match);

        $this->assertStringContainsString('grid grid-cols-1 md:grid-cols-2 gap-6', $match[0] ?? '');
        $this->assertStringContainsString('md:col-span-2', $match[0] ?? '');
        $this->assertStringNotContainsString('md:grid-cols-3', $match[0] ?? '');

        $this->assertStringContainsString('id="next-3"', $html);
        $this->assertStringContainsString("document.getElementById('next-3').disabled = hint !== '';", $html);
        $this->assertStringContainsString('← Back', $html);
        $this->assertStringContainsString('Next: Review →', $html);
    }
}
