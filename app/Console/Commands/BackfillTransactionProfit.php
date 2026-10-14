<?php

namespace App\Console\Commands;

use App\Models\ExchangeRate;
use App\Models\Transaction;
use Illuminate\Console\Command;

/**
 * Fills the yuan leg on transfers booked before it was stored, and only where the books can
 * prove the price. The buying rate that leg needs was never recorded on the transfer, so the
 * command reads it back out of the rate history: a row counts as the live price on a booking's
 * date if it was created at or before that date and carries a real buying price of its own.
 *
 * A booking is left untouched when no such row can be found. That is deliberate: an old
 * October transfer has no proven yuan buying price, and writing one would turn "we do not know"
 * into a figure that reads as fact. Those rows stay flagged as unpriced.
 */
class BackfillTransactionProfit extends Command
{
    protected $signature = 'profit:backfill-yuan-leg
        {--apply : Write the prices. Without this option nothing is touched}';

    protected $description = 'Price the yuan leg on past transfers only where a rate was provably live on the booking date';

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');

        $candidates = Transaction::where('status', 'completed')
            ->whereNull('usd_rmb_profit_cny')
            ->where('exchange_rate', '>', 0)
            ->where('total_usd_equivalent', '>', 0)
            ->orderBy('id')
            ->get();

        if ($candidates->isEmpty()) {
            $this->info('No completed transfers are missing a yuan leg. Nothing to do.');

            return self::SUCCESS;
        }

        $priced = 0;
        $skipped = 0;

        foreach ($candidates as $transaction) {
            $attributes = $this->prove($transaction);

            if ($attributes === null) {
                $skipped++;
                $this->line("  {$transaction->receipt_number}  left unpriced: no rate can be proven live on ".$transaction->created_at->format('M d, Y'));

                continue;
            }

            $priced++;
            $this->line(sprintf(
                '  %s  yuan leg %s CNY = %s UGX%s',
                $transaction->receipt_number,
                rtrim(rtrim($attributes['usd_rmb_profit_cny'], '0'), '.'),
                rtrim(rtrim($attributes['usd_rmb_profit_ugx'], '0'), '.'),
                array_key_exists('total_profit_ugx', $attributes) ? ', total '.$attributes['total_profit_ugx'] : ''
            ));

            if ($apply) {
                Transaction::whereKey($transaction->id)->update($attributes);
            }
        }

        $this->line('');

        if (!$apply) {
            $this->warn("Dry run. {$priced} transfer(s) would be priced, {$skipped} left unpriced. Repeat with --apply to write them.");

            return self::SUCCESS;
        }

        $this->info("{$priced} transfer(s) priced. {$skipped} left unpriced because no booking rate could be proven.");

        return self::SUCCESS;
    }

    /**
     * @return array<string, string>|null
     */
    private function prove(Transaction $transaction): ?array
    {
        $usd = (string) $transaction->total_usd_equivalent;
        $sell = (string) $transaction->exchange_rate;

        $rmb = ExchangeRate::rateLiveAt('USD', 'RMB', $transaction->created_at);
        $ugx = ExchangeRate::rateLiveAt('USD', 'UGX', $transaction->created_at);

        // The transfer was credited at the selling price; the buying price is the missing half
        // of the leg. Both a yuan buying price and a shilling buying price have to be proven
        // from a history row that actually holds one, or the whole figure is a guess.
        if (!$rmb || $rmb->buy_rate === null || (float) $rmb->buy_rate <= 0) {
            return null;
        }

        if (!$ugx || (float) ($ugx->buy_rate ?? $ugx->sell_rate ?? $ugx->rate) <= 0) {
            return null;
        }

        $buy = (string) $rmb->buy_rate;
        $buyUgx = (string) ($ugx->buy_rate ?? ($ugx->sell_rate ?? $ugx->rate));

        $cnyProfit = bcmul($usd, bcsub($buy, $sell, 6), 6);
        $ugxProfit = bcmul(bcdiv($cnyProfit, $buy, 8), $buyUgx, 6);

        $attributes = [
            'rmb_buying_rate' => $buy,
            'usd_rmb_profit_cny' => $cnyProfit,
            'usd_rmb_profit_ugx' => $ugxProfit,
        ];

        // The total only writes when the other two parts are already on the row, so the
        // breakdown still adds up. A missing fee or shilling margin keeps the total unknown.
        if ($transaction->charge_ugx !== null && $transaction->fx_profit_ugx !== null) {
            $attributes['total_profit_ugx'] = bcadd(
                bcadd((string) $transaction->charge_ugx, (string) $transaction->fx_profit_ugx, 6),
                $ugxProfit,
                6
            );
        }

        return $attributes;
    }
}
