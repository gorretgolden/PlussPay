<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\AccountEntry;
use App\Models\ActivityLog;
use App\Models\ExchangeRate;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * The office often books what an agent collected, because the agent is out in the field and the
 * receipt is needed now. Choosing that agent on the form is what puts the transfer, the cash in
 * hand and the commission in the right name, while the trail still says who typed it up.
 */
class OfficeBookedForAgentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['superadmin', 'admin', 'agent', 'accountant'] as $role) {
            Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        }

        ExchangeRate::create(['from_currency' => 'USD', 'to_currency' => 'RMB', 'rate' => 6.75, 'buy_rate' => 6.75, 'sell_rate' => 6.75, 'is_active' => true]);
        ExchangeRate::create(['from_currency' => 'USD', 'to_currency' => 'UGX', 'rate' => 3760, 'buy_rate' => 3750, 'sell_rate' => 3760, 'is_active' => true]);
    }

    private function user(string $role, string $name, string $country = 'UG', bool $active = true): User
    {
        $user = User::create([
            'name' => $name,
            'email' => strtolower(str_replace(' ', '', $name)).'@plussave.test',
            'password' => 'Password123',
            'is_active' => $active,
            'country' => $country,
        ]);

        $user->assignRole($role);

        return $user;
    }

    /**
     * What the wizard posts: 500,000 handed over, 20,000 kept as the charge, 480,000 travelling.
     *
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function payload(array $extra = []): array
    {
        return array_merge([
            'form_id' => session('transaction_form_id'),
            'sender_name' => 'Grace Auma',
            'receiver_name' => 'Zhang Wei',
            'currency' => 'UGX',
            'amount' => '500000',
            'charge_ugx' => '20000',
            'purpose' => 'Family Support',
        ], $extra);
    }

    private function book(User $booker, array $extra = []): Transaction
    {
        $this->actingAs($booker)->get('/transactions/create');

        $this->actingAs($booker)->post('/transactions', $this->payload($extra))
            ->assertSessionHasNoErrors();

        return Transaction::latest('id')->firstOrFail();
    }

    /**
     * @return array<string, string> entry type:account code => amount
     */
    private function lines(int $transactionId): array
    {
        return AccountEntry::where('transaction_id', $transactionId)
            ->with('account')
            ->get()
            ->mapWithKeys(fn (AccountEntry $entry) => [$entry->type.':'.$entry->account->code => (string) $entry->amount])
            ->all();
    }

    public function test_an_office_booking_can_be_put_into_an_agent_s_name(): void
    {
        $admin = $this->user('admin', 'Desk Admin');
        $agent = $this->user('agent', 'Sarah Nalin');

        $transaction = $this->book($admin, ['agent_id' => $agent->id]);

        $this->assertSame($agent->id, $transaction->created_by);
        $this->assertSame([
            'money_in:W-UGX-'.$agent->id => '480000.00',
            'agent_fee:W-UGX-'.$agent->id => '20000.00',
        ], $this->lines($transaction->id));
        $this->assertSame('500000.00', Account::walletFor($agent, 'UGX')->balance());
        $this->assertSame('0.00', Account::byCode(Account::UGX_UGANDA)->balance());

        $this->assertStringContainsString('Booked in the name of Sarah Nalin.', session('success'));
    }

    public function test_the_trail_keeps_both_the_agent_who_holds_it_and_the_person_who_typed_it(): void
    {
        $admin = $this->user('admin', 'Desk Admin');
        $agent = $this->user('agent', 'Sarah Nalin');

        $transaction = $this->book($admin, ['agent_id' => $agent->id]);

        $log = ActivityLog::where('transaction_id', $transaction->id)->where('action', 'created')->firstOrFail();

        $this->assertSame($admin->id, $log->user_id);
        $this->assertStringContainsString('for Sarah Nalin', $log->description);
        $this->assertStringContainsString('booked by Desk Admin', $log->description);
    }

    public function test_a_booking_left_on_the_office_desk_still_lands_in_the_till(): void
    {
        $admin = $this->user('admin', 'Desk Admin');

        $transaction = $this->book($admin, ['agent_id' => '']);

        $this->assertSame($admin->id, $transaction->created_by);
        $this->assertSame(['money_in:UGX-UG' => '500000.00'], $this->lines($transaction->id));
        $this->assertStringNotContainsString('Booked in the name of', (string) session('success'));
    }

    public function test_the_picker_offers_only_open_agents_on_the_uganda_desk(): void
    {
        $admin = $this->user('admin', 'Desk Admin');
        $uganda = $this->user('agent', 'Sarah Nalin');
        $china = $this->user('agent', 'Mei Lin', 'CN');
        $closed = $this->user('agent', 'Tom Kato', 'UG', false);
        $bookkeeper = $this->user('accountant', 'Jane Books');

        $html = $this->actingAs($admin)->get('/transactions/create')->assertOk()->getContent();

        $this->assertStringContainsString('Which agent took this money?', $html);
        $this->assertStringContainsString('<option value="'.$uganda->id.'"', $html);
        $this->assertStringNotContainsString('<option value="'.$china->id.'"', $html);
        $this->assertStringNotContainsString('<option value="'.$closed->id.'"', $html);
        $this->assertStringNotContainsString('<option value="'.$bookkeeper->id.'"', $html);
    }

    public function test_a_super_admin_sees_the_same_picker_because_the_book_is_open_to_them(): void
    {
        $boss = $this->user('superadmin', 'The Boss');
        $agent = $this->user('agent', 'Sarah Nalin');

        $transaction = $this->book($boss, ['agent_id' => $agent->id]);

        $this->assertSame($agent->id, $transaction->created_by);
    }

    public function test_an_agent_is_never_offered_the_picker_and_cannot_book_into_another_name(): void
    {
        $agent = $this->user('agent', 'Sarah Nalin');
        $other = $this->user('agent', 'Tom Kato');

        $html = $this->actingAs($agent)->get('/transactions/create')->assertOk()->getContent();
        $this->assertStringNotContainsString('name="agent_id"', $html);

        $this->actingAs($agent)->post('/transactions', $this->payload(['agent_id' => $other->id]))
            ->assertSessionHasErrors('agent_id');

        $this->assertSame(0, Transaction::count());
    }

    public function test_a_name_that_is_not_an_open_uganda_agent_is_refused(): void
    {
        $admin = $this->user('admin', 'Desk Admin');
        $refused = collect([
            $this->user('agent', 'Mei Lin', 'CN')->id,
            $this->user('agent', 'Tom Kato', 'UG', false)->id,
            $this->user('accountant', 'Jane Books')->id,
            9999,
        ]);

        $this->actingAs($admin)->get('/transactions/create');

        foreach ($refused as $id) {
            $this->actingAs($admin)->post('/transactions', $this->payload(['agent_id' => $id]))
                ->assertSessionHasErrors('agent_id');
        }

        $this->assertSame(0, Transaction::count());
        $this->assertSame(0, AccountEntry::count());
    }

    public function test_the_agent_finds_a_booking_the_office_made_in_their_own_records(): void
    {
        $admin = $this->user('admin', 'Desk Admin');
        $agent = $this->user('agent', 'Sarah Nalin');
        $colleague = $this->user('agent', 'Tom Kato');

        $transaction = $this->book($admin, ['agent_id' => $agent->id]);

        $this->actingAs($agent)->get('/transactions')->assertOk()->assertSee($transaction->receipt_number);
        $this->actingAs($agent)->get("/transactions/{$transaction->id}")->assertOk();

        // The colleague holds nothing of it, so the booking stays out of their list entirely.
        $this->actingAs($colleague)->get('/transactions')->assertOk()->assertDontSee($transaction->receipt_number);
    }
}
