<?php

namespace Tests\Feature;

use App\Models\ExchangeRate;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Shillings are counted in notes, so every UGX figure in the book is whole. Dollars and yuan are
 * counted in cents and fen, so both keep two decimals even when a rate cut lands on a round
 * figure. This pins that one rule on the four screens that show the same booking, because the
 * figures have to read the same way on the slip the customer keeps and the board the office watches.
 */
class MoneyDecimalRuleTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['superadmin', 'admin', 'agent', 'staff'] as $role) {
            Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        }

        ExchangeRate::setPair('USD', 'RMB', 7.30, 7.00, 7.30);
        ExchangeRate::setPair('USD', 'UGX', 3760, 3600, 3760);

        $this->admin = User::create([
            'name' => 'Gorret Admin',
            'email' => 'decimals@plussave.test',
            'password' => 'Password123',
            'is_active' => true,
        ]);
        $this->admin->assignRole('admin');
    }

    /**
     * 250.5 dollars delivered as 1,850.5 yuan, with a 20,000 shilling charge taken out of the
     * money handed over. Both travelling figures land on a half and every profit figure lands
     * between two notes, so a screen that rounds a dollar away or keeps shilling decimals cannot
     * pass unnoticed.
     */
    private function booking(): Transaction
    {
        return Transaction::create([
            'receipt_number' => 'PS-DECIMALS-1',
            'sender_name' => 'Asuma Aggrey',
            'sender_contact' => '0700000001',
            'receiver_name' => 'Wei Ming',
            'receiver_contact' => '8613800000001',
            'currency' => 'USD',
            'amount' => 250.5,
            'exchange_rate' => 7.30,
            'rate_buy_ugx' => 3600,
            'rate_sell_ugx' => 3760,
            'rmb_amount' => 1850.5,
            'charge_rate' => 0.01,
            'charge_amount' => 2.5,
            'charge_ugx' => 20000,
            'total_usd_equivalent' => 250.5,
            'fx_profit_ugx' => 10000.25,
            'fx_profit_usd' => 2.66,
            'rmb_buying_rate' => 7.00,
            'usd_rmb_profit_cny' => -7.5,
            'usd_rmb_profit_ugx' => -3857.142857,
            'total_profit_ugx' => 26143.107143,
            'status' => 'completed',
            'created_by' => $this->admin->id,
        ]);
    }

    public function test_the_slip_prints_every_figure_in_its_own_currency_form(): void
    {
        $this->booking();

        $html = $this->actingAs($this->admin)->get('/receipts/PS-DECIMALS-1/print')->assertOk()->getContent();

        $this->assertStringContainsString('UGX 20,000', $html);
        $this->assertStringNotContainsString('UGX 20,000.00', $html);
        $this->assertStringContainsString('USD 250.50', $html);
        $this->assertStringContainsString('¥1,850.50', $html);
        $this->assertStringNotContainsString('USD 250.5 ', $html);
        $this->assertStringNotContainsString('¥1,850.5 ', $html);
    }

    public function test_the_transactions_list_and_the_transfer_page_agree_with_the_slip(): void
    {
        $this->booking();

        foreach (['/transactions', '/transactions/1'] as $path) {
            $html = $this->actingAs($this->admin)->get($path)->assertOk()->getContent();

            $this->assertStringContainsString('UGX 20,000', $html);
            $this->assertStringNotContainsString('UGX 20,000.00', $html);
            $this->assertStringContainsString('USD 250.50', $html);
            $this->assertStringContainsString('¥1,850.50', $html);
        }
    }

    public function test_the_board_keeps_the_profit_in_whole_shillings_and_the_money_to_the_cent(): void
    {
        $this->booking();

        $html = $this->actingAs($this->admin)->get('/dashboard')->assertOk()->getContent();

        // 10,000.25 of margin less 3,857.14 of yuan is 6,143.11 of exchange profit, and the
        // 20,000 charge brings the overall to 26,143.1. Shillings are never counted in fractions.
        $this->assertStringContainsString('UGX 20,000', $html);
        $this->assertStringNotContainsString('UGX 20,000.00', $html);
        $this->assertStringContainsString('UGX 6,143', $html);
        $this->assertStringContainsString('UGX 26,143', $html);
        $this->assertStringNotContainsString('UGX 26,143.1', $html);
        $this->assertStringContainsString('¥1,850.50', $html);
    }

    public function test_the_profit_cells_a_screen_and_the_download_both_read_from(): void
    {
        $transaction = $this->booking();

        $labels = $transaction->profitLabels();

        $this->assertSame('UGX 20,000', $labels['charge']['text']);
        $this->assertSame('UGX 6,143', $labels['exchange']['text']);
        $this->assertSame('UGX 26,143', $labels['total']['text']);
        $this->assertSame('USD 250.50', $transaction->principalLabel());
        $this->assertSame('¥1,850.50', $transaction->yuanLabel());
        $this->assertSame('USD 250.50 plus UGX 20,000', $transaction->collectedAmount());
    }

    public function test_the_reports_page_shows_the_same_figures_as_the_board(): void
    {
        $this->booking();

        $html = $this->actingAs($this->admin)->get('/reports')->assertOk()->getContent();

        $this->assertStringContainsString('UGX 20,000', $html);
        $this->assertStringNotContainsString('UGX 20,000.00', $html);
        $this->assertStringContainsString('UGX 6,143', $html);
        $this->assertStringContainsString('UGX 26,143', $html);
    }
}
