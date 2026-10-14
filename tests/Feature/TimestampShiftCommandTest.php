<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Customer;
use App\Models\ExchangeRate;
use App\Models\Setting;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TimestampShiftCommandTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<int, string> */
    private array $backups = [];

    protected function tearDown(): void
    {
        foreach ($this->backups as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }

        parent::tearDown();
    }

    private function runShift(array $args): string
    {
        Artisan::call('timestamps:shift', $args);

        return Artisan::output();
    }

    private function backupFiles(): array
    {
        return glob(storage_path('app/timestamp-shift-*.json')) ?: [];
    }

    private function rememberBackups(): void
    {
        foreach ($this->backupFiles() as $path) {
            $this->backups[] = $path;
        }
    }

    private function oldestTransaction(): ?string
    {
        return DB::table('transactions')->where('receipt_number', 'PS-OLD')->value('created_at');
    }

    private function newestTransaction(): ?string
    {
        return DB::table('transactions')->where('receipt_number', 'PS-NEW')->value('created_at');
    }

    private function seedRows(): void
    {
        $user = User::create([
            'name' => 'Desk Officer',
            'email' => 'desk.' . uniqid() . '@plussave.test',
            'password' => 'Password123',
            'is_active' => true,
        ]);

        foreach (['PS-OLD', 'PS-NEW'] as $receipt) {
            Transaction::create([
                'receipt_number' => $receipt,
                'sender_name' => 'Sender One',
                'sender_contact' => '0700000001',
                'receiver_name' => 'Receiver One',
                'receiver_contact' => '8613800000001',
                'currency' => 'UGX',
                'amount' => 1000000,
                'exchange_rate' => 7.2,
                'rate_sell_ugx' => 4000,
                'rmb_amount' => 1764.71,
                'charge_rate' => 0.02,
                'charge_amount' => 2.5,
                'charge_ugx' => 10000,
                'total_usd_equivalent' => 250,
                'status' => 'completed',
                'created_by' => $user->id,
            ]);
        }

        // Written straight to the table because the point of the test is rows that
        // already sit in the database stamped under the old clock.
        DB::table('transactions')->where('receipt_number', 'PS-OLD')->update([
            'created_at' => '2026-10-10 06:30:00',
            'updated_at' => '2026-10-10 06:30:00',
        ]);
        DB::table('transactions')->where('receipt_number', 'PS-NEW')->update([
            'created_at' => '2026-10-12 09:40:00',
            'updated_at' => '2026-10-12 09:40:00',
        ]);

        $customer = Customer::create(['name' => 'Amina Nakato', 'created_by' => $user->id]);
        DB::table('customers')->where('id', $customer->id)->update(['created_at' => '2026-10-11 05:00:00']);

        $log = ActivityLog::create([
            'user_id' => $user->id,
            'action' => 'transaction_created',
            'description' => 'Old entry',
        ]);
        DB::table('activity_logs')->where('id', $log->id)->update(['created_at' => '2026-10-11 05:00:00']);

        $rate = ExchangeRate::create([
            'from_currency' => 'USD',
            'to_currency' => 'UGX',
            'rate' => 3700,
            'is_active' => true,
            'created_by' => $user->id,
        ]);
        DB::table('exchange_rates')->where('id', $rate->id)->update(['created_at' => '2026-10-11 05:00:00']);
    }

    public function test_it_refuses_to_move_anything_without_a_cut_off(): void
    {
        $this->seedRows();

        $output = $this->runShift([]);

        $this->assertStringContainsString('until you say where the old clock stops', $output);
        $this->assertSame('2026-10-10 06:30:00', $this->oldestTransaction());
    }

    public function test_a_vague_cut_off_is_refused(): void
    {
        $this->seedRows();

        $output = $this->runShift(['--before' => 'last tuesday']);

        $this->assertStringContainsString('I could not read that time', $output);
        $this->assertSame('2026-10-10 06:30:00', $this->oldestTransaction());
    }

    public function test_a_dry_run_lists_the_rows_and_writes_nothing(): void
    {
        $this->seedRows();

        $output = $this->runShift(['--before' => '2026-10-12 09:20']);

        $this->assertStringContainsString('transactions', $output);
        $this->assertStringContainsString('Dry run', $output);
        $this->assertSame('2026-10-10 06:30:00', $this->oldestTransaction());
        $this->assertSame([], $this->backupFiles());
        $this->assertNull(Setting::get('timestamps_shifted'));
    }

    public function test_applying_moves_only_the_rows_from_before_the_update(): void
    {
        $this->seedRows();

        $output = $this->runShift(['--before' => '2026-10-12 09:20', '--apply' => true]);
        $this->rememberBackups();

        $this->assertStringContainsString('value(s) moved', $output);
        $this->assertSame('2026-10-10 09:30:00', $this->oldestTransaction());
        $this->assertSame('2026-10-12 09:40:00', $this->newestTransaction());
        $this->assertSame('2026-10-11 08:00:00', DB::table('customers')->where('name', 'Amina Nakato')->value('created_at'));
        $this->assertSame('2026-10-11 08:00:00', DB::table('activity_logs')->where('description', 'Old entry')->value('created_at'));
        $this->assertSame('2026-10-11 08:00:00', DB::table('exchange_rates')->value('created_at'));

        $marker = Setting::get('timestamps_shifted');
        $this->assertSame('2026-10-12 09:20:00', $marker['before']);
        $this->assertSame('3', $marker['hours']);
        $this->assertFileExists(storage_path('app/' . $marker['backup']));
    }

    public function test_the_same_shift_cannot_be_applied_twice(): void
    {
        $this->seedRows();

        $this->runShift(['--before' => '2026-10-12 09:20', '--apply' => true]);
        $this->rememberBackups();

        $output = $this->runShift(['--before' => '2026-10-12 09:20', '--apply' => true]);

        $this->assertStringContainsString('already moved', $output);
        $this->assertSame('2026-10-10 09:30:00', $this->oldestTransaction());
    }

    public function test_the_backup_file_puts_every_value_back(): void
    {
        $this->seedRows();

        $this->runShift(['--before' => '2026-10-12 09:20', '--apply' => true]);
        $this->rememberBackups();
        $marker = Setting::get('timestamps_shifted');

        $output = $this->runShift(['--restore' => $marker['backup'], '--apply' => true]);

        $this->assertStringContainsString('restored', $output);
        $this->assertSame('2026-10-10 06:30:00', $this->oldestTransaction());
        $this->assertSame('2026-10-11 05:00:00', DB::table('customers')->where('name', 'Amina Nakato')->value('created_at'));
        $this->assertNull(Setting::get('timestamps_shifted'));
    }
}
