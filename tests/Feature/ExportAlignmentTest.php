<?php

namespace Tests\Feature;

use App\Models\CashHandover;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Every exported file is read in a spreadsheet, so the sheet has to open looking like a report:
 * the column headings centered on their columns and the records themselves left aligned, in the
 * shared writer and in the two controllers that build their own sheets.
 */
class ExportAlignmentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Role::firstOrCreate(['name' => 'superadmin', 'guard_name' => 'web']);
    }

    private function exporter(): User
    {
        $user = User::create([
            'name' => 'Export Desk',
            'email' => 'export'.uniqid().'@plussave.test',
            'password' => 'Password123',
            'is_active' => true,
        ]);
        $user->assignRole('superadmin');

        return $user;
    }

    private function sheetOf($response): \PhpOffice\PhpSpreadsheet\Worksheet\Worksheet
    {
        $path = tempnam(sys_get_temp_dir(), 'xlsx');
        file_put_contents($path, $response->streamedContent());
        $sheet = IOFactory::load($path)->getActiveSheet();
        unlink($path);

        return $sheet;
    }

    public function test_the_shared_writer_centers_the_headings_and_left_aligns_the_records(): void
    {
        $sheet = $this->sheetOf($this->actingAs($this->exporter())->get('/export/users'));

        $this->assertSame(Alignment::HORIZONTAL_CENTER, $sheet->getStyle('A5')->getAlignment()->getHorizontal());
        $this->assertSame(Alignment::HORIZONTAL_LEFT, $sheet->getStyle('A6')->getAlignment()->getHorizontal());
    }

    public function test_the_cash_hand_in_sheet_centers_the_headings_and_left_aligns_the_records(): void
    {
        $agent = $this->exporter();
        $lead = $this->exporter();

        CashHandover::create([
            'reference' => 'ALN-TEST1',
            'agent_id' => $agent->id,
            'super_agent_id' => $lead->id,
            'currency' => 'UGX',
            'amount' => '100000',
            'status' => CashHandover::PENDING,
        ]);

        $sheet = $this->sheetOf($this->actingAs($lead)->get('/cash-handovers/export'));

        $this->assertSame(Alignment::HORIZONTAL_CENTER, $sheet->getStyle('A1')->getAlignment()->getHorizontal());
        $this->assertSame(Alignment::HORIZONTAL_LEFT, $sheet->getStyle('A2')->getAlignment()->getHorizontal());
        // The money columns are numbers, and they read from the left like the rest of the row.
        $this->assertSame(Alignment::HORIZONTAL_LEFT, $sheet->getStyle('F2')->getAlignment()->getHorizontal());
    }

    public function test_the_report_sheet_centers_the_headings_and_left_aligns_the_records(): void
    {
        $agent = $this->exporter();

        Transaction::create([
            'receipt_number' => 'PS-ALIGN-1',
            'sender_name' => 'Sender One',
            'sender_contact' => '0700000001',
            'receiver_name' => 'Receiver One',
            'receiver_contact' => '8613800000001',
            'destination' => 'CN',
            'payout_currency' => 'RMB',
            'currency' => 'UGX',
            'amount' => '120000',
            'exchange_rate' => 6.75,
            'rmb_amount' => 216,
            'total_usd_equivalent' => 31.91,
            'charge_rate' => 0,
            'charge_amount' => 0,
            'charge_ugx' => '0',
            'status' => 'completed',
            'created_by' => $agent->id,
        ]);

        $sheet = $this->sheetOf($this->actingAs($agent)->get('/reports/export'));

        $this->assertSame(Alignment::HORIZONTAL_CENTER, $sheet->getStyle('A1')->getAlignment()->getHorizontal());
        $this->assertSame(Alignment::HORIZONTAL_LEFT, $sheet->getStyle('A2')->getAlignment()->getHorizontal());
        $this->assertSame(Alignment::HORIZONTAL_LEFT, $sheet->getStyle('H2')->getAlignment()->getHorizontal());
    }
}
