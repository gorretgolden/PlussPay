<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
        File::deleteDirectory($this->dir);

        parent::tearDown();
    }

    private function writeLog(string $contents, string $name = 'laravel.log'): void
    {
        File::put($this->dir . '/' . $name, $contents);
    }

    private function sampleLog(): string
    {
        return implode("\n", [
            '[2026-10-06 08:00:00] local.INFO: Booted the scheduler ',
            '[2026-10-07 09:15:22] local.ERROR: Call to a member function toArray() on null {"userId":3,"exception":"[object] (Error(code: 0): Call to a member function toArray() on null at /home/plussave/pluspay/app/Http/Controllers/WalletController.php:42)\\n#0 {main}"}',
            '[2026-10-08 10:30:00] local.WARNING: Passing the null string',
            '[2026-10-08 11:02:41] local.ERROR: SQLSTATE[42S22]: Column not found: 1054 Unknown column ',
            '  "receipt_status" in "field list" {"userId":9,"exception":"[object] (Illuminate\\\\Database\\\\QueryException(code: 42S22): SQLSTATE[42S22] at /home/plussave/pluspay/vendor/laravel/framework/src/Illuminate/Database/Connection.php:857)',
            '#1 /home/plussave/pluspay/app/Http/Controllers/ReceiptController.php:88',
            '#2 {main}"}',
            '',
        ]);
    }

    private function admin(): User
    {
        $user = User::create([
            'name' => 'Log Admin',
            'email' => 'logs' . uniqid() . '@plussave.test',
            'password' => 'Password123',
            'is_active' => true,
        ]);

        $user->assignRole('admin');

        return $user;
    }

    public function test_the_page_lists_the_newest_entries_first(): void
    {
        $this->writeLog($this->sampleLog());

        $logs = $this->actingAs($this->admin())->get('/admin/system-logs')
            ->assertOk()
            ->assertSee('System Logs')
            ->assertSee('Column not found')
            ->viewData('logs');

        $times = collect($logs->getCollection())->pluck('time')->all();

        $this->assertSame([
            '2026-10-08 11:02:41',
            '2026-10-08 10:30:00',
            '2026-10-07 09:15:22',
            '2026-10-06 08:00:00',
        ], $times);
        $this->assertSame(4, $logs->total());
    }

    public function test_the_multi_line_entry_keeps_its_message_and_stack_apart(): void
    {
        $this->writeLog($this->sampleLog());

        $entries = \App\Services\SystemLogReader::read(null, 2)['entries'];

        $sql = collect($entries)->first(fn ($e) => str_contains($e['message'], 'SQLSTATE'));

        $this->assertNotNull($sql);
        $this->assertSame('ERROR', $sql['level']);
        $this->assertStringNotContainsString('{"userId"', $sql['message']);
        $this->assertStringContainsString('Connection.php:857', $sql['stack']);
        $this->assertSame('/home/plussave/pluspay/vendor/laravel/framework/src/Illuminate/Database/Connection.php:857', $sql['where']);
        $this->assertSame('9', (string) $sql['context']['userId']);
    }

    public function test_an_entry_with_a_broken_context_block_still_shows_everything_it_said(): void
    {
        $this->writeLog('[2026-10-08 12:00:00] local.ERROR: Boom {"userId":5,"exception":"unterminated at /home/plussave/pluspay/app/Http/Controllers/X.php:1' . "\n");

        $entry = \App\Services\SystemLogReader::read(null, 2)['entries'][0];

        $this->assertSame('Boom {"userId":5,"exception":"unterminated at /home/plussave/pluspay/app/Http/Controllers/X.php:1', $entry['message']);
        $this->assertSame([], $entry['context']);
    }

    public function test_entries_can_be_filtered_by_level(): void
    {
        $this->writeLog($this->sampleLog());

        $logs = $this->actingAs($this->admin())->get('/admin/system-logs?level=error')
            ->assertOk()
            ->viewData('logs');

        $this->assertSame(2, $logs->total());
        $this->assertSame(['ERROR', 'ERROR'], collect($logs->getCollection())->pluck('level')->all());
    }

    public function test_entries_can_be_searched_by_message_and_by_file(): void
    {
        $this->writeLog($this->sampleLog());
        $admin = $this->admin();

        $byMessage = $this->actingAs($admin)->get('/admin/system-logs?search=Unknown%20column')->viewData('logs');
        $this->assertSame(1, $byMessage->total());

        // The stack trace is searched too, so a file name finds the entry that threw there.
        $byFile = $this->actingAs($admin)->get('/admin/system-logs?search=WalletController')->viewData('logs');
        $this->assertSame(1, $byFile->total());
        $this->assertStringContainsString('toArray', $byFile->getCollection()->first()['message']);
    }

    public function test_entries_can_be_filtered_by_date(): void
    {
        $this->writeLog($this->sampleLog());

        $logs = $this->actingAs($this->admin())
            ->get('/admin/system-logs?date_from=2026-10-07&date_to=2026-10-07')
            ->viewData('logs');

        $this->assertSame(1, $logs->total());
        $this->assertSame('2026-10-07 09:15:22', $logs->getCollection()->first()['time']);
    }

    public function test_only_log_files_can_be_opened(): void
    {
        $this->writeLog($this->sampleLog());
        File::put($this->dir . '/secret.log', '[2026-10-08 11:00:00] local.ERROR: should not be reachable');

        $response = $this->actingAs($this->admin())->get('/admin/system-logs?file=../laravel.log');

        $response->assertOk();
        $meta = $response->viewData('meta');

        // A name that is not a Laravel log file falls back to the newest real one.
        $this->assertSame('laravel.log', $meta['file']);
        $this->assertStringNotContainsString('should not be reachable', $response->getContent());
    }

    public function test_the_page_copes_with_an_empty_folder(): void
    {
        $this->actingAs($this->admin())
            ->get('/admin/system-logs')
            ->assertOk()
            ->assertSee('There is no log file in the folder yet');
    }

    public function test_agents_cannot_open_the_system_logs(): void
    {
        $this->writeLog($this->sampleLog());

        $agent = User::create([
            'name' => 'Field Agent',
            'email' => 'agent' . uniqid() . '@plussave.test',
            'password' => 'Password123',
            'is_active' => true,
        ]);
        $agent->assignRole('agent');

        $this->actingAs($agent)->get('/admin/system-logs')->assertForbidden();
        $this->actingAs($agent)->get('/export/system-logs')->assertForbidden();
    }

    public function test_the_export_matches_the_filters_and_carries_the_pluspay_header(): void
    {
        $this->writeLog($this->sampleLog());

        $response = $this->actingAs($this->admin())->get('/export/system-logs?level=error');
        $response->assertOk();

        $path = tempnam(sys_get_temp_dir(), 'pluspay-') . '.xlsx';
        file_put_contents($path, $response->streamedContent());
        $sheet = IOFactory::load($path)->getActiveSheet();
        @unlink($path);

        $this->assertSame('PlusPay', $sheet->getCell('A1')->getValue());
        $this->assertSame('System Logs', $sheet->getTitle());
        $this->assertSame('Level', $sheet->getCell('B5')->getValue());
        $this->assertSame('ERROR', $sheet->getCell('B6')->getValue());
        $this->assertSame('ERROR', $sheet->getCell('B7')->getValue());
        $this->assertNull($sheet->getCell('B8')->getValue());
    }
}
