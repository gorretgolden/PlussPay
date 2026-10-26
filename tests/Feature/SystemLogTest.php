<?php

namespace Tests\Feature;

use App\Models\SystemLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class SystemLogTest extends TestCase
{
    use RefreshDatabase;

    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['superadmin', 'admin', 'agent', 'staff'] as $role) {
            Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        }

        $this->dir = sys_get_temp_dir() . '/pluspay-logs-' . uniqid();
        File::makeDirectory($this->dir, 0777, true);

        config(['logging.channels.single.path' => $this->dir . '/laravel.log']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        File::deleteDirectory($this->dir);

        parent::tearDown();
    }

    private function superAdmin(): User
    {
        $user = User::create([
            'name' => 'Boss',
            'email' => 'boss' . uniqid() . '@plussave.test',
            'password' => 'Password123',
            'is_active' => true,
        ]);

        $user->assignRole('superadmin');

        return $user;
    }

    private function role(string $role): User
    {
        $user = User::create([
            'name' => ucfirst($role),
            'email' => $role . uniqid() . '@plussave.test',
            'password' => 'Password123',
            'is_active' => true,
        ]);

        $user->assignRole($role);

        return $user;
    }

    /**
     * A saved error with its own timestamp, so the newest-first listing has something
     * to order. created_at is stamped on insert, so it is moved afterwards.
     */
    private function error(string $level, string $message, string $class, string $file, Carbon $when): SystemLog
    {
        $log = SystemLog::create([
            'level' => $level,
            'message' => $message,
            'exception_class' => $class,
            'file' => $file,
            'line' => 42,
            'url' => 'http://localhost/dashboard',
            'method' => 'GET',
        ]);

        $log->created_at = $when;
        $log->save();

        return $log;
    }

    public function test_the_page_lists_saved_errors_newest_first(): void
    {
        $this->error('error', 'Column not found', 'Illuminate\\Database\\QueryException', '/app/WalletController.php', Carbon::parse('2026-10-08 11:02:41'));
        $this->error('warning', 'Passing the null string', 'RuntimeException', '/app/ReportController.php', Carbon::parse('2026-10-07 09:15:22'));

        $logs = $this->actingAs($this->superAdmin())->get('/admin/system-logs')
            ->assertOk()
            ->assertSee('System Errors')
            ->viewData('logs');

        $this->assertSame(2, $logs->total());
        $this->assertSame([
            'Column not found',
            'Passing the null string',
        ], collect($logs->getCollection())->pluck('message')->all());
    }

    public function test_entries_can_be_filtered_by_level(): void
    {
        $this->error('critical', 'Dead lock', 'QueryException', '/app/a.php', Carbon::parse('2026-10-08 11:00:00'));
        $this->error('warning', 'Slow query', 'RuntimeException', '/app/b.php', Carbon::parse('2026-10-08 10:00:00'));

        $logs = $this->actingAs($this->superAdmin())->get('/admin/system-logs?level=critical')
            ->assertOk()
            ->viewData('logs');

        $this->assertSame(1, $logs->total());
        $this->assertSame('critical', $logs->getCollection()->first()->level);
    }

    public function test_entries_can_be_filtered_by_resolved_state(): void
    {
        $open = $this->error('error', 'Still broken', 'RuntimeException', '/app/a.php', Carbon::parse('2026-10-08 11:00:00'));
        $closed = $this->error('error', 'Already fixed', 'RuntimeException', '/app/b.php', Carbon::parse('2026-10-08 10:00:00'));
        $closed->update(['resolved' => true]);

        $logs = $this->actingAs($this->superAdmin())->get('/admin/system-logs?resolved=0')->viewData('logs');

        $this->assertSame(1, $logs->total());
        $this->assertSame($open->id, $logs->getCollection()->first()->id);
    }

    public function test_entries_can_be_searched_by_message_and_by_file(): void
    {
        $this->error('error', 'Unknown column receipt_status', 'QueryException', '/app/ReceiptController.php', Carbon::parse('2026-10-08 11:02:41'));

        $byMessage = $this->actingAs($this->superAdmin())->get('/admin/system-logs?search=Unknown%20column')->viewData('logs');
        $this->assertSame(1, $byMessage->total());

        $byFile = $this->actingAs($this->superAdmin())->get('/admin/system-logs?search=ReceiptController')->viewData('logs');
        $this->assertSame(1, $byFile->total());
    }

    public function test_entries_can_be_filtered_by_date(): void
    {
        $this->error('error', 'Today only', 'RuntimeException', '/app/a.php', Carbon::parse('2026-10-08 11:02:41'));
        $this->error('error', 'A week ago', 'RuntimeException', '/app/b.php', Carbon::parse('2026-10-01 09:00:00'));

        $logs = $this->actingAs($this->superAdmin())
            ->get('/admin/system-logs?date_from=2026-10-08&date_to=2026-10-08')
            ->viewData('logs');

        $this->assertSame(1, $logs->total());
        $this->assertSame('Today only', $logs->getCollection()->first()->message);
    }

    public function test_a_super_admin_can_mark_an_error_resolved_with_a_note(): void
    {
        $log = $this->error('error', 'Needs attention', 'RuntimeException', '/app/a.php', Carbon::now());
        $boss = $this->superAdmin();

        $this->actingAs($boss)
            ->patch("/admin/system-logs/{$log->id}/resolve", ['note' => 'Fixed in release 1.4.'])
            ->assertRedirect()
            ->assertSessionHas('success');

        $log->refresh();
        $this->assertTrue($log->resolved);
        $this->assertSame($boss->id, $log->resolved_by);
        $this->assertNotNull($log->resolved_at);
        $this->assertSame('Fixed in release 1.4.', $log->note);
    }

    public function test_resolving_one_error_resolves_its_identical_twins_too(): void
    {
        $first = $this->error('error', 'Same fault', 'RuntimeException', '/app/a.php', Carbon::now());
        $twin = $this->error('error', 'Same fault', 'RuntimeException', '/app/a.php', Carbon::now());
        $other = $this->error('error', 'Different fault', 'RuntimeException', '/app/a.php', Carbon::now());

        $this->actingAs($this->superAdmin())
            ->patch("/admin/system-logs/{$first->id}/resolve", ['note' => 'Deployed the fix.'])
            ->assertRedirect()
            ->assertSessionHas('success', 'Error marked resolved. 1 matching entry resolved too.');

        $this->assertTrue($first->fresh()->resolved);
        $this->assertTrue($twin->fresh()->resolved);
        $this->assertFalse($other->fresh()->resolved);
    }

    public function test_a_resolved_error_that_comes_back_is_reopened_with_a_note(): void
    {
        $old = $this->error('error', 'Disk unreadable', 'RuntimeException', '/app/a.php', Carbon::now());
        $old->update(['resolved' => true, 'resolved_at' => now(), 'note' => 'Replaced the disk.']);

        report(new \RuntimeException('Disk unreadable'));

        $fresh = $old->fresh();

        $this->assertFalse($fresh->resolved);
        $this->assertNull($fresh->resolved_at);
        $this->assertStringContainsString('Replaced the disk.', $fresh->note);
        $this->assertStringContainsString('Re-opened automatically', $fresh->note);
    }

    public function test_the_print_sheet_is_super_admin_only_and_carries_the_filters(): void
    {
        $this->error('critical', 'Print me', 'QueryException', '/app/a.php', Carbon::now());
        $this->error('warning', 'Leave me out', 'RuntimeException', '/app/b.php', Carbon::now());

        $this->actingAs($this->superAdmin())
            ->get('/admin/system-logs/print?level=critical')
            ->assertOk()
            ->assertSee('Print me')
            ->assertDontSee('Leave me out');

        $this->actingAs($this->role('admin'))
            ->get('/admin/system-logs/print')
            ->assertForbidden();
    }

    public function test_the_page_still_surfaces_the_raw_log_tail_as_a_secondary_source(): void
    {
        File::put($this->dir . '/laravel.log', "[2026-10-08 12:00:00] local.ERROR: Only in the file, never caught\n");

        $this->actingAs($this->superAdmin())
            ->get('/admin/system-logs')
            ->assertOk()
            ->assertSee('Only in the file');
    }

    public function test_an_agent_cannot_open_the_system_logs(): void
    {
        $this->error('error', 'Secret stack', 'RuntimeException', '/app/a.php', Carbon::now());

        $agent = $this->role('agent');

        $this->actingAs($agent)->get('/admin/system-logs')->assertForbidden();
        $this->actingAs($agent)->get('/export/system-logs')->assertForbidden();
    }

    public function test_an_admin_cannot_open_the_system_logs_either(): void
    {
        $this->error('error', 'Internals only', 'RuntimeException', '/app/a.php', Carbon::now());

        // Admins read the audit and login trails, but the error log names files and stack
        // traces, so it is kept to the Super Admin.
        $admin = $this->role('admin');

        $this->actingAs($admin)->get('/admin/system-logs')->assertForbidden();
        $this->actingAs($admin)->get('/export/system-logs')->assertForbidden();
    }

    public function test_the_export_lists_saved_errors_in_the_right_columns(): void
    {
        $this->error('critical', 'Connection refused', 'QueryException', '/app/Db.php', Carbon::parse('2026-10-08 11:02:41'));

        $response = $this->actingAs($this->superAdmin())->get('/export/system-logs?level=critical');
        $response->assertOk();

        $path = tempnam(sys_get_temp_dir(), 'pluspay-') . '.xlsx';
        file_put_contents($path, $response->streamedContent());
        $sheet = IOFactory::load($path)->getActiveSheet();
        @unlink($path);

        $this->assertSame('PlusPay', $sheet->getCell('A1')->getValue());
        $this->assertSame('System Logs', $sheet->getTitle());
        $this->assertSame('Level', $sheet->getCell('B5')->getValue());
        $this->assertSame('CRITICAL', $sheet->getCell('B6')->getValue());
        $this->assertSame('Connection refused', $sheet->getCell('C6')->getValue());
        $this->assertSame('Open', $sheet->getCell('G6')->getValue());
        $this->assertSame('Total records', $sheet->getCell('A7')->getValue());
        $this->assertSame('1', $sheet->getCell('B7')->getValue());
    }

    // The file reader still works on its own, independent of the page, so a couple of its
    // parsing rules stay covered here.
    public function test_the_multi_line_entry_keeps_its_message_and_stack_apart(): void
    {
        File::put($this->dir . '/laravel.log', implode("\n", [
            '[2026-10-08 11:02:41] local.ERROR: SQLSTATE[42S22]: Column not found: 1054 Unknown column ',
            '  "receipt_status" in "field list" {"userId":9,"exception":"[object] (Illuminate\\\\Database\\\\QueryException(code: 42S22): SQLSTATE[42S22] at /home/plussave/pluspay/vendor/laravel/framework/src/Illuminate/Database/Connection.php:857)',
            '#1 /home/plussave/pluspay/app/Http/Controllers/ReceiptController.php:88',
            '#2 {main}"}',
            '',
        ]));

        $entries = \App\Services\SystemLogReader::read(null, 2)['entries'];
        $sql = collect($entries)->first(fn ($e) => str_contains($e['message'], 'SQLSTATE'));

        $this->assertNotNull($sql);
        $this->assertSame('ERROR', $sql['level']);
        $this->assertStringNotContainsString('{"userId"', $sql['message']);
        $this->assertStringContainsString('Connection.php:857', $sql['stack']);
    }

    public function test_the_reader_copes_with_a_broken_context_block(): void
    {
        File::put($this->dir . '/laravel.log', '[2026-10-08 12:00:00] local.ERROR: Boom {"userId":5,"exception":"unterminated at /app/X.php:1' . "\n");

        $entry = \App\Services\SystemLogReader::read(null, 2)['entries'][0];

        $this->assertStringContainsString('Boom', $entry['message']);
    }
}
