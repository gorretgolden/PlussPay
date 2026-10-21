<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\AccountEntry;
use App\Models\CashHandover;
use App\Models\User;
use App\Services\AccountLedger;
use App\Support\PermissionCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * The hand-in desk page. What matters here is who can read which rows: a desk reads its own
 * requests and the ones addressed to it, the office reads everything, and no words typed into the
 * address bar move a person past that. The filters are tested beside it because a filter that
 * quietly ignores the scope would be the easiest leak in the book to ship.
 */
class CashHandoverDeskTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (PermissionCatalog::names() as $name) {
            Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
        }

        foreach (['agent', 'super-agent', 'admin'] as $name) {
            Role::firstOrCreate(['name' => $name, 'guard_name' => 'web'])
                ->syncPermissions(PermissionCatalog::defaults()[$name]);
        }
    }

    private function person(string $role, string $name, string $currency = 'UGX', ?string $float = null): User
    {
        $user = User::create([
            'name' => $name,
            'email' => strtolower(str_replace(' ', '', $name)).'@plussave.test',
            'password' => 'Password123',
            'is_active' => true,
            'country' => 'UG',
        ]);

        $user->assignRole($role);

        if ($float !== null) {
            AccountLedger::record(Account::walletFor($user, $currency), 'adjustment_in', $float, 'Float from the office');
        }

        return $user;
    }

    private function handover(
        User $agent,
        User $lead,
        string $status,
        string $currency,
        string $amount,
        ?\DateTimeInterface $when = null,
        ?string $note = null,
        ?string $decisionNote = null,
    ): CashHandover {
        $handover = CashHandover::create([
            'reference' => 'DESK-'.strtoupper(substr(uniqid(), -7)),
            'agent_id' => $agent->id,
            'super_agent_id' => $lead->id,
            'currency' => $currency,
            'amount' => $amount,
            'note' => $note,
            'status' => $status,
            'decision_note' => $status === CashHandover::PENDING ? null : $decisionNote,
            'decided_at' => $status === CashHandover::PENDING ? null : now(),
        ]);

        if ($when) {
            $handover->created_at = $when;
            $handover->save();
        }

        return $handover;
    }

    private function desk(User $viewer, array $query = []): \Illuminate\Testing\TestResponse
    {
        $this->flushSession();

        $uri = '/cash-handovers'.($query === [] ? '' : '?'.http_build_query($query));

        return $this->actingAs($viewer)->get($uri);
    }

    public function test_a_desk_reads_its_own_requests_and_never_another_desks(): void
    {
        $mine = $this->person('agent', 'Sarah Nalin');
        $theirs = $this->person('agent', 'Peter Okello');
        $lead = $this->person('super-agent', 'Amina Lead');

        $hers = $this->handover($mine, $lead, CashHandover::PENDING, 'UGX', '480000');
        $hidden = $this->handover($theirs, $lead, CashHandover::PENDING, 'UGX', '880000');

        $page = $this->desk($mine)->assertOk()->getContent();

        $this->assertStringContainsString('Hand In Cash Requests', $page);
        $this->assertStringContainsString($hers->reference, $page);
        $this->assertStringContainsString('UGX 480,000', $page);
        $this->assertStringNotContainsString($hidden->reference, $page, "Another desk's request leaked onto this page.");
        $this->assertStringNotContainsString('880,000', $page);

        // The same rule holds once a filter is on the page.
        $this->desk($mine, ['status' => 'pending', 'currency' => 'UGX'])->assertOk()
            ->assertSee($hers->reference)
            ->assertDontSee($hidden->reference);
    }

    public function test_an_agent_id_in_the_address_bar_never_widens_what_a_desk_can_read(): void
    {
        $mine = $this->person('agent', 'Sarah Nalin');
        $theirs = $this->person('agent', 'Peter Okello');
        $lead = $this->person('super-agent', 'Amina Lead');

        $hers = $this->handover($mine, $lead, CashHandover::PENDING, 'UGX', '150000');
        $hidden = $this->handover($theirs, $lead, CashHandover::PENDING, 'UGX', '999000');

        // The office narrowing pointed at the other desk must do nothing for a plain agent, and a
        // request addressed to somebody else stays invisible too.
        $this->desk($mine, ['agent_id' => $theirs->id])->assertOk()
            ->assertSee($hers->reference)
            ->assertDontSee($hidden->reference)
            ->assertDontSee('999,000');

        $this->desk($mine, ['agent_id' => $mine->id])->assertOk()
            ->assertSee($hers->reference);
    }

    public function test_a_super_agent_reads_and_approaches_the_ones_sent_to_him(): void
    {
        $agent = $this->person('agent', 'Sarah Nalin', 'UGX', '500000');
        $lead = $this->person('super-agent', 'Amina Lead');
        $other = $this->person('super-agent', 'Peter Lead');
        $handover = $this->handover($agent, $lead, CashHandover::PENDING, 'UGX', '480000', null, 'Carried in the evening');
        $elsewhere = $this->handover($agent, $other, CashHandover::PENDING, 'UGX', '120000');

        $page = $this->desk($lead)->assertOk()->getContent();

        // He holds approve-cash-handover but not view-all-wallets, and still reads the queue sent
        // to him: the scope is his own rows plus the rows naming him.
        $this->assertStringContainsString($handover->reference, $page);
        $this->assertStringContainsString('Sarah Nalin', $page);
        $this->assertStringContainsString('Carried in the evening', $page);
        $this->assertStringContainsString('Approve receipt', $page);
        $this->assertStringNotContainsString($elsewhere->reference, $page);

        $this->flushSession();
        $this->actingAs($lead)->post(route('cash-handovers.approve', $handover), [
            'decision_note' => 'Counted and in hand',
        ])->assertRedirect()->assertSessionHas('success');

        $this->assertSame(CashHandover::APPROVED, $handover->fresh()->status);
        $this->assertSame('20000.00', Account::walletFor($agent, 'UGX')->fresh()->balance());
        $this->assertSame('480000.00', Account::walletFor($lead, 'UGX')->fresh()->balance());
        $this->assertSame(1, AccountEntry::where('type', 'handover_out')->count());
        $this->assertSame(1, AccountEntry::where('type', 'handover_in')->count());
    }

    public function test_the_office_reads_every_desk_and_narrows_to_one(): void
    {
        $office = $this->person('admin', 'Grace Naigaga');
        $first = $this->person('agent', 'Sarah Nalin');
        $second = $this->person('agent', 'Peter Okello');
        $lead = $this->person('super-agent', 'Amina Lead');

        $hers = $this->handover($first, $lead, CashHandover::PENDING, 'USD', '250.50');
        $theirs = $this->handover($second, $lead, CashHandover::APPROVED, 'UGX', '660000', null, null, 'Counted');

        $page = $this->desk($office)->assertOk()->getContent();
        $this->assertStringContainsString($hers->reference, $page);
        $this->assertStringContainsString($theirs->reference, $page);
        $this->assertStringContainsString('$250.50', $page);
        // The office is not the super agent named on a row, so it is shown no decision buttons.
        $this->assertStringNotContainsString('Approve receipt', $page);

        $this->desk($office, ['agent_id' => $first->id])->assertOk()
            ->assertSee($hers->reference)
            ->assertDontSee($theirs->reference);
    }

    public function test_a_person_without_the_wallet_ability_is_refused_the_page(): void
    {
        Role::firstOrCreate(['name' => 'watcher', 'guard_name' => 'web'])->syncPermissions(['view-dashboard']);
        $outsider = $this->person('watcher', 'Fred Kato');

        $this->desk($outsider)->assertForbidden();
    }

    public function test_only_the_super_agent_named_on_a_request_can_decide_it(): void
    {
        $agent = $this->person('agent', 'Sarah Nalin', 'UGX', '500000');
        $lead = $this->person('super-agent', 'Amina Lead');
        $notNamed = $this->person('super-agent', 'Peter Lead');
        $noPen = $this->person('agent', 'Fred Kato');
        $handover = $this->handover($agent, $lead, CashHandover::PENDING, 'UGX', '480000');

        $this->flushSession();
        $this->actingAs($noPen)->post(route('cash-handovers.approve', $handover))->assertForbidden();
        $this->assertSame(CashHandover::PENDING, $handover->fresh()->status);

        $this->flushSession();
        $this->actingAs($notNamed)->post(route('cash-handovers.approve', $handover))->assertForbidden();
        $this->assertSame(CashHandover::PENDING, $handover->fresh()->status);

        // The desk that has no reach over the row is handed no buttons either.
        $page = $this->desk($notNamed)->assertOk()->getContent();
        $this->assertStringNotContainsString('Approve receipt', $page);
        $this->assertStringNotContainsString($handover->reference, $page);
    }

    public function test_the_filters_filter(): void
    {
        $office = $this->person('admin', 'Grace Naigaga');
        $agent = $this->person('agent', 'Sarah Nalin');
        $lead = $this->person('super-agent', 'Amina Lead');

        $pending = $this->handover($agent, $lead, CashHandover::PENDING, 'UGX', '100000');
        $approved = $this->handover($agent, $lead, CashHandover::APPROVED, 'USD', '200', null, null, 'In hand');
        $declined = $this->handover($agent, $lead, CashHandover::DECLINED, 'RMB', '1500', null, 'Bank charge', 'Wrong count');
        $lastMonth = $this->handover($agent, $lead, CashHandover::PENDING, 'UGX', '700000', now()->subMonthNoOverflow()->startOfMonth()->addDay());

        $this->desk($office, ['status' => 'pending'])->assertOk()
            ->assertSee($pending->reference)->assertSee($lastMonth->reference)
            ->assertDontSee($approved->reference)->assertDontSee($declined->reference);

        // The declined row prints its reason; the filter that asks for it finds it.
        $this->desk($office, ['status' => 'declined'])->assertOk()
            ->assertSee($declined->reference)->assertSee('Wrong count')->assertDontSee($pending->reference);

        $this->desk($office, ['period' => 'month'])->assertOk()
            ->assertSee($pending->reference)
            ->assertDontSee($lastMonth->reference);

        $this->desk($office, ['period' => 'last_month'])->assertOk()
            ->assertSee($lastMonth->reference)
            ->assertDontSee($pending->reference);

        $this->desk($office, ['currency' => 'USD'])->assertOk()
            ->assertSee($approved->reference)
            ->assertDontSee($pending->reference);

        $this->desk($office, ['search' => $declined->reference])->assertOk()
            ->assertSee($declined->reference)
            ->assertDontSee($pending->reference);

        // Searching a desk name is the other half of the same box.
        $this->desk($office, ['search' => 'Sarah'])->assertOk()
            ->assertSee($pending->reference)
            ->assertDontSee('No hand in requests for this filter.');
    }

    public function test_a_preset_covers_the_whole_of_its_last_day(): void
    {
        $office = $this->person('admin', 'Grace Naigaga');
        $agent = $this->person('agent', 'Sarah Nalin');
        $lead = $this->person('super-agent', 'Amina Lead');

        $tonight = $this->handover($agent, $lead, CashHandover::PENDING, 'UGX', '90000', today()->endOfDay()->subMinutes(5));

        $this->desk($office, ['period' => 'today'])->assertOk()->assertSee($tonight->reference);

        $this->desk($office, ['date_from' => today()->toDateString(), 'date_to' => today()->toDateString()])
            ->assertOk()->assertSee($tonight->reference);
    }

    public function test_the_summary_strip_counts_the_filtered_set(): void
    {
        $office = $this->person('admin', 'Grace Naigaga');
        $agent = $this->person('agent', 'Sarah Nalin');
        $lead = $this->person('super-agent', 'Amina Lead');

        $this->handover($agent, $lead, CashHandover::PENDING, 'UGX', '100000');
        $this->handover($agent, $lead, CashHandover::PENDING, 'UGX', '50000');
        $this->handover($agent, $lead, CashHandover::APPROVED, 'USD', '200', null, null, 'In hand');

        $page = $this->desk($office)->assertOk()->getContent();

        $this->assertStringContainsString('3 requests', $page);
        $this->assertStringContainsString('UGX 150,000', $page);
        $this->assertStringContainsString('$200.00', $page);
        $this->assertStringContainsString('already inside the agent', $page);

        $filtered = $this->desk($office, ['status' => 'pending'])->assertOk()->getContent();
        $this->assertStringContainsString('2 requests', $filtered);
        $this->assertStringNotContainsString('$200.00', $filtered);
    }

    public function test_an_empty_filter_says_so_in_words(): void
    {
        $office = $this->person('admin', 'Grace Naigaga');
        $agent = $this->person('agent', 'Sarah Nalin');
        $lead = $this->person('super-agent', 'Amina Lead');
        $this->handover($agent, $lead, CashHandover::PENDING, 'UGX', '100000');

        $page = $this->desk($office, ['status' => 'declined'])->assertOk()->getContent();

        $this->assertStringContainsString('No hand in requests for this filter.', $page);
        $this->assertStringNotContainsString('TODO', $page);
    }

    public function test_bulk_approval_decides_only_the_rows_this_desk_may_take(): void
    {
        $agent = $this->person('agent', 'Sarah Nalin', 'UGX', '500000');
        $other = $this->person('agent', 'Peter Okello', 'UGX', '300000');
        $lead = $this->person('super-agent', 'Amina Lead');
        $notNamed = $this->person('super-agent', 'Peter Lead');

        $first = $this->handover($agent, $lead, CashHandover::PENDING, 'UGX', '200000');
        $second = $this->handover($other, $lead, CashHandover::PENDING, 'UGX', '100000');
        $elsewhere = $this->handover($other, $notNamed, CashHandover::PENDING, 'UGX', '90000');

        $page = $this->desk($lead)->assertOk()->getContent();
        $this->assertStringContainsString('Approve the ticked rows', $page);

        $this->flushSession();
        $this->actingAs($lead)->post(route('cash-handovers.bulk'), [
            'action' => 'approve',
            'handover_ids' => [$first->id, $second->id, $elsewhere->id],
        ])->assertRedirect()->assertSessionHas('success');

        $this->assertSame(CashHandover::APPROVED, $first->fresh()->status);
        $this->assertSame(CashHandover::APPROVED, $second->fresh()->status);
        $this->assertSame(CashHandover::PENDING, $elsewhere->fresh()->status, 'A row sent to another super agent is skipped, not taken.');
        $this->assertSame('300000.00', Account::walletFor($lead, 'UGX')->fresh()->balance());
    }

    public function test_the_bulk_bar_hands_its_script_the_controls_it_counts(): void
    {
        $agent = $this->person('agent', 'Sarah Nalin', 'UGX', '500000');
        $other = $this->person('agent', 'Peter Okello', 'UGX', '300000');
        $lead = $this->person('super-agent', 'Amina Lead');
        $notNamed = $this->person('super-agent', 'Peter Lead');

        $this->handover($agent, $lead, CashHandover::PENDING, 'UGX', '200000');
        $this->handover($other, $lead, CashHandover::PENDING, 'UGX', '100000');
        $elsewhere = $this->handover($other, $notNamed, CashHandover::PENDING, 'UGX', '90000');

        $page = $this->desk($lead)->assertOk()->getContent();

        // The users desk keeps its bulk counter in a script that never loads on this page, so the
        // tick-all box, the running tally, the clear button and the figure the confirm card reads
        // back all have to be present here for that script to have anything to drive.
        $this->assertStringContainsString('id="bulk-all-handovers"', $page);
        $this->assertStringContainsString('id="bulk-tally"', $page);
        $this->assertStringContainsString('id="bulk-approve"', $page);
        $this->assertStringContainsString('data-clear-selection', $page);
        $this->assertStringContainsString('name="bulk_summary"', $page);
        $this->assertSame(1, preg_match('#data-confirm-rows="[^"]*bulk_summary[^"]*"#', $page), 'The confirm card reads the selection back.');
        $this->assertSame(2, substr_count($page, 'class="bulk-handover'), 'Only the rows this desk may decide carry a tick box.');
        $this->assertStringNotContainsString($elsewhere->reference.'</td>', $page);
    }

    public function test_the_export_is_the_same_set_as_the_page(): void
    {
        $office = $this->person('admin', 'Grace Naigaga');
        $agent = $this->person('agent', 'Sarah Nalin');
        $lead = $this->person('super-agent', 'Amina Lead');

        $pending = $this->handover($agent, $lead, CashHandover::PENDING, 'UGX', '100000');
        $declined = $this->handover($agent, $lead, CashHandover::DECLINED, 'USD', '200', null, null, 'Wrong count');
        $lastMonth = $this->handover($agent, $lead, CashHandover::PENDING, 'UGX', '700000', now()->subMonthNoOverflow()->startOfMonth()->addDay());

        $this->flushSession();
        $response = $this->actingAs($office)->get(route('cash-handovers.export', [
            'status' => 'pending',
            'period' => 'month',
        ]));

        $this->assertSame(200, $response->getStatusCode());
        $cells = $this->cells($response);

        $this->assertContains($pending->reference, $cells);
        $this->assertNotContains($declined->reference, $cells);
        $this->assertNotContains($lastMonth->reference, $cells, 'The export ignored the period.');
        $this->assertContains('UGX 100,000', $cells);

        // A desk exports its own rows only, whatever the screen was showing.
        $this->flushSession();
        $mine = $this->cells($this->actingAs($agent)->get(route('cash-handovers.export')));
        $this->assertContains($pending->reference, $mine);
        $this->assertContains($lastMonth->reference, $mine);
    }

    public function test_a_name_cannot_turn_a_cell_into_a_formula(): void
    {
        $office = $this->person('admin', 'Grace Naigaga');
        $clever = $this->person('agent', '=HYPERLINK("x")');
        $lead = $this->person('super-agent', 'Amina Lead');
        $this->handover($clever, $lead, CashHandover::PENDING, 'UGX', '100000');

        $this->flushSession();
        $response = $this->actingAs($office)->get(route('cash-handovers.export'));
        $path = storage_path('app/cash-handover-formula-check.xlsx');
        file_put_contents($path, $response->streamedContent());
        $sheet = IOFactory::load($path)->getActiveSheet();
        unlink($path);

        $this->assertSame($clever->name, $sheet->getCell('C2')->getValue());
        $this->assertStringStartsWith('=', (string) $sheet->getCell('C2')->getValue());
        $this->assertSame(DataType::TYPE_STRING, $sheet->getCell('C2')->getDataType());
    }

    /**
     * Every written cell of the sheet as plain strings, so a test can say a reference is in the
     * file rather than only that the file downloaded.
     *
     * @return array<int, string>
     */
    private function cells(\Illuminate\Testing\TestResponse $response): array
    {
        $path = storage_path('app/cash-handover-desk-export-check.xlsx');
        file_put_contents($path, $response->streamedContent());
        $sheet = IOFactory::load($path)->getActiveSheet();
        unlink($path);

        $cells = [];
        foreach ($sheet->getRowIterator() as $row) {
            $iterator = $row->getCellIterator();
            $iterator->setIterateOnlyExistingCells(false);
            foreach ($iterator as $cell) {
                $cells[] = (string) $cell->getValue();
            }
        }

        return $cells;
    }
}
