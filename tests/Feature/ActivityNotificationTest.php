<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\ActivityLog;
use App\Models\ExchangeRate;
use App\Models\Setting;
use App\Models\Transaction;
use App\Models\User;
use App\Services\AccountLedger;
use App\Support\ActivityNews;
use App\Support\PermissionCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * The book keeps itself busy all day and nobody stands at the desk to say so. These tests check
 * that an entry one person makes arrives as word for the people who can already read that part of
 * the book, that it counts on the sidebar and the board until they have seen it, and that it links
 * through to the record it is about rather than to a description of it.
 *
 * The people who did the thing are left out of their own news, and a desk that reads only its own
 * rows gets no inbox at all: an agent booking transfers all day would otherwise be told about
 * themselves by themselves.
 */
class ActivityNotificationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (PermissionCatalog::names() as $name) {
            Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
        }

        foreach (array_keys(PermissionCatalog::defaults()) as $role) {
            Role::firstOrCreate(['name' => $role, 'guard_name' => 'web'])
                ->syncPermissions(PermissionCatalog::defaults()[$role]);
        }

        ExchangeRate::setPair('USD', 'UGX', 3760, 3750, 3760);
        ExchangeRate::setPair('USD', 'RMB', 7.24, 7.20, 7.24);
        Setting::set('default_charge_ugx', '20000', 'float', 'transaction');
    }

    private function user(string $role, string $name): User
    {
        $user = User::create([
            'name' => $name,
            'email' => strtolower(str_replace(' ', '.', $role)).uniqid().'@plussave.test',
            'password' => 'Password123',
            'is_active' => true,
        ]);
        $user->assignRole($role);

        return $user;
    }

    private function html(User $user, string $path = '/dashboard'): string
    {
        $this->flushSession();

        return $this->actingAs($user)->get($path)->assertOk()->getContent();
    }

    /** UGX 3,780,000 handed over, so exactly $1,000 travels. */
    private function book(User $by): Transaction
    {
        $this->actingAs($by)->get('/transactions/create');
        $this->actingAs($by)->post('/transactions', [
            'form_id' => session('transaction_form_id'),
            'agent_id' => 'counter',
            'sender_name' => 'Sender One',
            'receiver_name' => 'Receiver One',
            'currency' => 'UGX',
            'amount' => '3780000',
            'charge_ugx' => '20000',
        ])->assertSessionHasNoErrors();

        return Transaction::latest('id')->firstOrFail();
    }

    private function spend(User $by): void
    {
        $till = Account::byCode(Account::UGX_UGANDA);
        AccountLedger::record($till, 'opening_balance', '5000000.00', 'Office float');

        $this->actingAs($by)->post('/expenses', [
            'category_id' => \App\Models\ExpenseCategory::where('name', 'Transport')->value('id'),
            'description' => 'Boda to the customer',
            'amount' => '45000.00',
            'account_id' => $till->id,
            'expense_date' => now()->toDateString(),
            'paid_via' => 'Mobile money',
        ])->assertSessionHasNoErrors();
    }

    public function test_a_booked_transfer_is_worded_to_every_reader_of_the_book_but_the_one_who_booked_it(): void
    {
        $office = $this->user('admin', 'Grace Naigaga');
        $owner = $this->user('superadmin', 'Gorret Golden');
        $bookkeeper = $this->user('accountant', 'Patience Okoyi');
        $teller = $this->user('staff', 'Doreen Namuli');
        $agent = $this->user('agent', 'Amina Nabukenya');

        $this->book($office);

        $this->assertSame(1, $owner->notifications()->count(), 'The owner is told.');
        $this->assertSame(1, $bookkeeper->notifications()->count(), 'The bookkeeper is told.');
        $this->assertSame(0, $office->notifications()->count(), 'Nobody is told about their own hand.');
        $this->assertSame(0, $teller->notifications()->count(), 'A desk that reads only its own rows gets no news.');
        $this->assertSame(0, $agent->notifications()->count());
    }

    public function test_the_notice_carries_its_own_words_and_links_to_the_record_it_is_about(): void
    {
        $office = $this->user('admin', 'Grace Naigaga');
        $owner = $this->user('superadmin', 'Gorret Golden');
        $transfer = $this->book($office);

        $data = $owner->notifications()->first()->data;

        $this->assertSame('A new transfer was booked', $data['headline']);
        $this->assertSame('transfers', $data['section']);
        $this->assertSame('/transactions/'.$transfer->id, $data['path'], 'It opens the transfer, not a list.');
        $this->assertSame('Open the transfer', $data['link_label']);
        $this->assertSame('Grace Naigaga', $data['actor']);
        $this->assertSame('Admin', $data['actor_role']);

        // The audit trail only ever writes "Transaction created", which tells a reader nothing
        // they can act on, so the notice names the three figures the booking is made of.
        $this->assertStringContainsString($transfer->receipt_number, $data['body']);
        $this->assertStringContainsString('UGX 3,760,000 in for $1,000.00', $data['body']);
        $this->assertStringContainsString('UGX 20,000 charge', $data['body']);
        $this->assertStringContainsString('Receiver One', $data['body']);
    }

    public function test_an_expense_entered_at_a_desk_arrives_with_the_figure_in_the_words(): void
    {
        $office = $this->user('admin', 'Grace Naigaga');
        $bookkeeper = $this->user('accountant', 'Patience Okoyi');

        $this->spend($office);

        $data = $bookkeeper->notifications()->first()->data;

        $this->assertSame('expenses', $data['section']);
        $this->assertSame('A new expense was entered', $data['headline']);
        $this->assertStringContainsString('UGX 45,000', $data['body']);
        $this->assertStringContainsString('Boda to the customer', $data['body']);
        $this->assertMatchesRegularExpression('#^/expenses/\d+$#', $data['path']);
    }

    public function test_the_book_of_record_is_not_read_as_news(): void
    {
        $office = $this->user('admin', 'Grace Naigaga');
        $owner = $this->user('superadmin', 'Gorret Golden');

        // Reading the book is not an event in it, so a slip printed and a sheet exported stay
        // out of the notices even though they are in the audit trail. Written by somebody else,
        // so the only thing keeping them away is the rule and not the reader being their own
        // actor.
        foreach (['receipt_printed', 'exported', 'login_failed', 'bulk_imported', 'permissions_seeded'] as $action) {
            ActivityLog::create(['user_id' => $office->id, 'action' => $action, 'description' => $action.' happened']);
        }

        $this->assertSame(0, $owner->notifications()->count());
    }

    public function test_the_sidebar_counts_what_has_not_been_seen_yet(): void
    {
        $office = $this->user('admin', 'Grace Naigaga');
        $owner = $this->user('superadmin', 'Gorret Golden');

        $this->book($office);

        $html = $this->html($owner);

        // The inbox entry and the desk it belongs to both show the one figure, so the count is
        // where the news is as well as that there is news.
        $this->assertSame(2, substr_count($html, 'rounded-full px-2 py-0.5 tabular-nums">1<'));
        $this->assertStringContainsString('text-sm font-semibold text-green-800 group-hover:text-green-900 group-hover:underline">A new transfer was booked', $html);
        $this->assertStringContainsString('See all 1', $html);
    }

    public function test_marking_everything_read_clears_every_badge_at_once(): void
    {
        $office = $this->user('admin', 'Grace Naigaga');
        $owner = $this->user('superadmin', 'Gorret Golden');

        $this->book($office);
        $this->spend($office);

        $counts = ActivityNews::badgeCounts($owner);
        $this->assertSame(2, $counts['total'], 'The inbox total counts both kinds of news.');
        $this->assertSame(1, $counts['transactions']);
        $this->assertSame(1, $counts['expenses']);

        $this->flushSession();
        $this->actingAs($owner)->patch('/notifications/read-all')->assertRedirect();

        $this->assertSame(0, ActivityNews::badgeCounts($owner)['total']);
        $this->assertStringNotContainsString('bg-yellow-300', $this->html($owner));
        $this->assertStringNotContainsString('What Happened</h2>', $this->html($owner), 'A board with nothing to catch up on prints no card.');
    }

    public function test_the_inbox_lists_the_word_and_a_notice_only_marks_for_its_own_reader(): void
    {
        $office = $this->user('admin', 'Grace Naigaga');
        $owner = $this->user('superadmin', 'Gorret Golden');
        $other = $this->user('superadmin', 'Second Owner');

        $transfer = $this->book($office);
        $mine = $owner->notifications()->first();
        $theirs = $other->notifications()->first();

        $html = $this->html($owner, '/notifications');

        $this->assertStringContainsString('A new transfer was booked', $html);
        $this->assertStringContainsString('href="/transactions/'.$transfer->id.'"', $html);
        $this->assertStringContainsString('Grace Naigaga', $html);

        $this->flushSession();
        $this->actingAs($owner)->patch('/notifications/'.$theirs->id.'/read')->assertForbidden();
        $this->assertNull($theirs->fresh()->read_at, 'Somebody else\'s notice is not yours to clear.');

        $this->flushSession();
        $this->actingAs($owner)->patch('/notifications/'.$mine->id.'/read')->assertRedirect();
        $this->assertNotNull($mine->fresh()->read_at);
        $this->assertNull($theirs->fresh()->read_at);
    }

    public function test_a_desk_with_no_news_to_read_has_no_inbox_at_all(): void
    {
        $teller = $this->user('staff', 'Doreen Namuli');

        $this->flushSession();
        $this->actingAs($teller)->get('/notifications')->assertForbidden();

        $html = $this->html($teller);

        $this->assertStringNotContainsString('What Happened', $html, 'No link in the sidebar and no card on the board.');
    }

    public function test_a_notice_keeps_its_words_after_the_trail_it_came_from_is_gone(): void
    {
        $office = $this->user('admin', 'Grace Naigaga');
        $owner = $this->user('superadmin', 'Gorret Golden');

        $this->book($office);
        ActivityLog::query()->delete();

        $this->assertStringContainsString('A new transfer was booked', $this->html($owner));
        $this->assertStringContainsString('A new transfer was booked', $this->html($owner, '/notifications'));
    }
}
