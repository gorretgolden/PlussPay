<?php

namespace Tests\Feature;

use App\Models\ExchangeRate;
use App\Models\Setting;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class SecurityHardeningTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['superadmin', 'admin', 'agent', 'staff'] as $role) {
            Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        }

        ExchangeRate::create(['from_currency' => 'USD', 'to_currency' => 'RMB', 'rate' => 7.24, 'is_active' => true]);
        ExchangeRate::create(['from_currency' => 'USD', 'to_currency' => 'UGX', 'rate' => 3700, 'is_active' => true]);
    }

    private function makeUser(string $role): User
    {
        $user = User::create([
            'name' => ucfirst($role).' Tester',
            'email' => $role.'-'.uniqid().'@plussave.test',
            'password' => 'Password123',
            'is_active' => true,
        ]);

        $user->assignRole($role);

        return $user;
    }

    private function makeTransaction(User $creator, string $status = 'completed'): Transaction
    {
        return Transaction::createWithReceiptNumber([
            'sender_name' => 'Alice Sender',
            'sender_contact' => '+256 700 111 222',
            'receiver_name' => 'Bob Receiver',
            'receiver_contact' => '+86 138 0000 0000',
            'currency' => 'USD',
            'amount' => 200,
            'exchange_rate' => 7.24,
            'rmb_amount' => 1448,
            'charge_rate' => 5,
            'charge_amount' => 10,
            'total_usd_equivalent' => 200,
            'status' => $status,
            'created_by' => $creator->id,
        ]);
    }

    public function test_receipts_are_not_reachable_without_login(): void
    {
        $agent = $this->makeUser('agent');
        $transaction = $this->makeTransaction($agent);

        $this->get('/receipts/'.$transaction->receipt_number.'/view')
            ->assertRedirect('/admin/login');

        $this->get('/receipts/'.$transaction->receipt_number)
            ->assertRedirect('/admin/login');
    }

    public function test_demo_passwords_are_not_rendered_on_the_login_page(): void
    {
        $this->get('/admin/login')
            ->assertOk()
            ->assertDontSee('superadmin123')
            ->assertDontSee('admin123');
    }

    public function test_agent_cannot_open_another_agents_receipt(): void
    {
        $other = $this->makeUser('agent');
        $mine = $this->makeUser('agent');

        $foreign = $this->makeTransaction($other);
        $own = $this->makeTransaction($mine);

        $this->actingAs($mine)->get('/receipts/'.$foreign->receipt_number)->assertForbidden();
        $this->actingAs($mine)->get('/receipts/'.$foreign->receipt_number.'/print')->assertForbidden();
        $this->actingAs($mine)->get('/receipts/'.$own->receipt_number)->assertOk();

        $this->actingAs($mine)->get('/transactions/'.$foreign->id)->assertForbidden();
    }

    public function test_admin_can_open_any_receipt(): void
    {
        $agent = $this->makeUser('agent');
        $transaction = $this->makeTransaction($agent);

        $this->actingAs($this->makeUser('admin'))
            ->get('/receipts/'.$transaction->receipt_number)
            ->assertOk();
    }

    public function test_staff_only_sees_their_own_records_in_listing(): void
    {
        $colleague = $this->makeUser('staff');
        $me = $this->makeUser('staff');

        $mine = $this->makeTransaction($me);
        $theirs = $this->makeTransaction($colleague);

        $this->actingAs($me)
            ->get('/receipts')
            ->assertOk()
            ->assertSee($mine->receipt_number)
            ->assertDontSee($theirs->receipt_number);
    }

    public function test_logout_requires_post(): void
    {
        $this->actingAs($this->makeUser('agent'))->get('/logout')->assertMethodNotAllowed();
    }

    public function test_security_headers_are_present(): void
    {
        $this->actingAs($this->makeUser('admin'))
            ->get('/dashboard')
            ->assertOk()
            ->assertHeader('X-Frame-Options', 'SAMEORIGIN')
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
    }

    public function test_non_superadmin_cannot_grant_the_superadmin_role(): void
    {
        $admin = $this->makeUser('admin');
        $target = $this->makeUser('agent');

        $this->actingAs($admin)->put('/users/'.$target->id, [
            'name' => $target->name,
            'email' => $target->email,
            'role' => 'superadmin',
            'is_active' => 1,
        ])->assertSessionHasErrors('role');

        $this->assertFalse($target->refresh()->hasRole('superadmin'));
    }

    public function test_user_cannot_change_their_own_role(): void
    {
        $admin = $this->makeUser('admin');

        $this->actingAs($admin)->put('/users/'.$admin->id, [
            'name' => $admin->name,
            'email' => $admin->email,
            'role' => 'superadmin',
            'is_active' => 1,
        ])->assertSessionHasErrors('role');

        $this->assertFalse($admin->refresh()->hasRole('superadmin'));
    }

    public function test_superadmin_can_grant_the_superadmin_role(): void
    {
        $super = $this->makeUser('superadmin');
        $second = $this->makeUser('superadmin');
        $target = $this->makeUser('agent');

        $this->actingAs($super)->put('/users/'.$target->id, [
            'name' => $target->name,
            'email' => $target->email,
            'role' => 'superadmin',
            'is_active' => 1,
        ])->assertRedirect('/users');

        $this->assertTrue($target->refresh()->hasRole('superadmin'));
    }

    public function test_account_with_transactions_cannot_be_deleted(): void
    {
        $super = $this->makeUser('superadmin');
        $agent = $this->makeUser('agent');
        $this->makeTransaction($agent);

        $this->actingAs($super)->delete('/users/'.$agent->id)->assertSessionHasErrors('error');

        $this->assertNotNull(User::find($agent->id));
        $this->assertNotNull(Transaction::firstWhere('created_by', $agent->id));
    }

    public function test_last_superadmin_cannot_be_deactivated(): void
    {
        $super = $this->makeUser('superadmin');

        $this->actingAs($super)->patch('/users/'.$super->id.'/toggle-active')
            ->assertSessionHasErrors('error');

        $this->assertTrue($super->refresh()->is_active);
    }

    public function test_status_transitions_are_whitelisted(): void
    {
        $admin = $this->makeUser('admin');

        $completed = $this->makeTransaction($admin, 'completed');
        $this->actingAs($admin)->patch('/transactions/'.$completed->id.'/status', ['status' => 'pending'])
            ->assertSessionHasErrors('status');
        $this->assertSame('completed', $completed->refresh()->status);

        $this->actingAs($admin)->patch('/transactions/'.$completed->id.'/status', ['status' => 'cancelled'])
            ->assertSessionHasNoErrors();
        $this->assertSame('cancelled', $completed->refresh()->status);

        $pending = $this->makeTransaction($admin, 'pending');
        $this->actingAs($admin)->patch('/transactions/'.$pending->id.'/status', ['status' => 'completed'])
            ->assertSessionHasNoErrors();
        $this->assertSame('completed', $pending->refresh()->status);
    }

    public function test_deactivated_user_is_signed_out_on_the_next_request(): void
    {
        $agent = $this->makeUser('agent');

        $this->actingAs($agent)->get('/dashboard')->assertOk();

        $agent->update(['is_active' => false]);

        $this->actingAs($agent)->get('/dashboard')->assertRedirect('/admin/login');
        $this->assertGuest();
    }

    public function test_locked_user_is_signed_out_on_the_next_request(): void
    {
        $agent = $this->makeUser('agent');

        $this->actingAs($agent)->get('/dashboard')->assertOk();

        $agent->update(['is_locked' => true, 'locked_until' => now()->addMinutes(15)]);

        $this->actingAs($agent)->get('/dashboard')->assertRedirect('/admin/login');
        $this->assertGuest();
    }

    public function test_submitting_the_transaction_form_twice_creates_one_record(): void
    {
        $agent = $this->makeUser('agent');
        $this->actingAs($agent);

        $formId = $this->get('/transactions/create')->viewData('formId');

        $payload = [
            'form_id' => $formId,
            'sender_name' => 'Alice Sender',
            'sender_contact' => '+256 700 111 222',
            'receiver_name' => 'Bob Receiver',
            'receiver_contact' => '+86 138 0000 0000',
            'currency' => 'USD',
            'amount' => 200,
            'charge_rate' => 5,
            'notes' => 'Sending money to China',
        ];

        $this->post('/transactions', $payload)->assertSessionHasNoErrors();
        $this->post('/transactions', $payload)->assertSessionHasErrors('form_id');

        $this->assertSame(1, Transaction::count());
    }

    public function test_transaction_limits_come_from_system_settings(): void
    {
        Setting::set('min_transaction_amount', '1000', 'float', 'transaction');

        $agent = $this->makeUser('agent');
        $this->actingAs($agent);

        $formId = $this->get('/transactions/create')->viewData('formId');

        $this->post('/transactions', [
            'form_id' => $formId,
            'sender_name' => 'Alice Sender',
            'sender_contact' => '+256 700 111 222',
            'receiver_name' => 'Bob Receiver',
            'receiver_contact' => '+86 138 0000 0000',
            'currency' => 'USD',
            'amount' => 200,
            'charge_rate' => 5,
        ])->assertSessionHasErrors('amount');

        $this->assertSame(0, Transaction::count());
    }

    public function test_receipt_numbers_stay_unique_and_sequential(): void
    {
        $admin = $this->makeUser('admin');

        $numbers = collect(range(1, 3))->map(fn() => $this->makeTransaction($admin)->receipt_number);

        $this->assertSame(3, $numbers->unique()->count());
        $this->assertSame(
            [$numbers[0], $numbers[1], $numbers[2]],
            [
                'PS-'.now()->format('Ymd').'-0001',
                'PS-'.now()->format('Ymd').'-0002',
                'PS-'.now()->format('Ymd').'-0003',
            ]
        );
    }

    public function test_client_cannot_for_its_own_rate_or_charge(): void
    {
        $agent = $this->makeUser('agent');
        $this->actingAs($agent);

        $formId = $this->get('/transactions/create')->viewData('formId');

        $this->post('/transactions', [
            'form_id' => $formId,
            'sender_name' => 'Alice Sender',
            'sender_contact' => '+256 700 111 222',
            'receiver_name' => 'Bob Receiver',
            'receiver_contact' => '+86 138 0000 0000',
            'currency' => 'USD',
            'amount' => 1000,
            'charge_rate' => 0,
            'exchange_rate' => 1,
            'total_usd_equivalent' => 1,
            'rmb_amount' => 1,
            'status' => 'completed',
        ])->assertSessionHasNoErrors();

        $transaction = Transaction::first();

        $this->assertSame('1000.0000', (string) $transaction->charge_rate);
        $this->assertSame('50.00', (string) $transaction->charge_amount);
        $this->assertSame('7.240000', (string) $transaction->exchange_rate);
        $this->assertSame('7240.00', (string) $transaction->rmb_amount);
        $this->assertSame('pending', $transaction->status);
    }
}
