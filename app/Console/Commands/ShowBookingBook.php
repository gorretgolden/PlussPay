<?php

namespace App\Console\Commands;

use App\Models\Account;
use App\Models\ExchangeRate;
use App\Models\Transaction;
use Illuminate\Console\Command;

/**
 * Where the figures on the board come from, printed for one booking.
 *
 * The dashboard, the receipts sheet and this command all read the same stored columns, so when a
 * number on screen looks wrong the question is which stored figure produced it and whether that
 * figure was priced at the rate it should have been. This writes nothing.
 */
class ShowBookingBook extends Command
{
    protected $signature = 'book:show {receipt : The receipt number on the booking, eg PS-20261017-0001}';

    protected $description = 'Print every money figure stored on one booking and how each board number is derived from it';

    public function handle(): int
    {
        $booking = Transaction::with('creator:id,name')
            ->where('receipt_number', trim($this->argument('receipt')))
            ->first();

        if (! $booking) {
            $this->error('No booking carries that receipt number. Check it on the Transactions screen.');

            return self::FAILURE;
        }

        $usd = (float) $booking->total_usd_equivalent;
        $sellUgx = (float) ($booking->rate_sell_ugx ?: 0);
        $rate = (float) $booking->exchange_rate;

        // What the stored dollars and yuan would be at the rates kept on the row, so a figure that
        // was priced elsewhere shows up as a difference rather than passing as correct.
        $principal = (float) $booking->amount;
        $expectedUsd = $sellUgx > 0 && $booking->currency === 'UGX'
            ? $principal / $sellUgx
            : $principal;
        $expectedRmb = $rate > 0 ? $usd * $rate : 0.0;

        $this->line('Booking '.$booking->receipt_number.' for '.$booking->receiver_name);
        $this->line('');

        $this->table(['What', 'Stored on the row', 'How the board reads it'], [
            ['Status', $booking->status, 'Only completed bookings carry money on the board'],
            ['Booked at', (string) $booking->created_at, 'The Today and This Month windows cut on this clock'],
            ['Booked by', $booking->creator?->name ?? '(nobody: id '.($booking->created_by ?? 'none').')',
                'An agent sees only rows booked in their name'],
            ['Currency', $booking->currency, ''],
            ['Paid how', ($booking->payment_method ?? '(nobody said)')
                .($booking->payment_reference ? ', confirmation '.$booking->payment_reference : ''),
                'How the money reached the desk. It moves nothing in the ledger; the till holds it either way'],
            ['Amount that travels', Account::format($booking->currency, $booking->amount),
                'This is the Sent figure, after the charge is taken out'],
            ['Charge', Account::format('UGX', $booking->charge_ugx),
                'Handed over = Sent + Charge = '.Account::format('UGX', (float) $booking->amount + (float) $booking->charge_ugx)],
            ['Dollars', '$'.number_format($usd, 2),
                $booking->currency === 'UGX'
                    ? 'UGX '.number_format((float) $booking->amount, 2).' / '.number_format($sellUgx, 4).' = '
                        .'$'.number_format($expectedUsd, 2)
                        .(abs($expectedUsd - $usd) > 0.02 ? '  DOES NOT MATCH the stored dollars' : '')
                    : 'Typed in as dollars'],
            ['Yuan promised', '¥'.number_format((float) $booking->rmb_amount, 2),
                '$'.number_format($usd, 2).' × '.number_format($rate, 6).' = ¥'.number_format($expectedRmb, 2)
                .(abs($expectedRmb - (float) $booking->rmb_amount) > 0.02 ? '  DOES NOT MATCH the stored yuan' : '')],
            ['Rate kept on the row', number_format($sellUgx, 4).' UGX per dollar, '.number_format($rate, 6).' yuan per dollar',
                'The price the booking was sold at, kept for the whole life of the row'],
            ['Buying price kept', number_format((float) $booking->rate_buy_ugx, 4), ''],
            ['FX profit', Account::format('UGX', $booking->fx_profit_ugx), 'Shilling leg'],
            ['', Account::format('UGX', $booking->usd_rmb_profit_ugx), 'Dollar to yuan leg'],
            ['Overall profit', Account::format('UGX', (float) $booking->fx_profit_ugx + (float) $booking->usd_rmb_profit_ugx
                + (float) $booking->charge_ugx), 'Charge plus both legs'],
        ]);

        $live = ExchangeRate::query()
            ->whereIn('to_currency', ['UGX', 'RMB'])
            ->where('is_active', true)
            ->get(['from_currency', 'to_currency', 'rate', 'buy_rate', 'sell_rate']);

        $this->line('Prices live on the rates screen right now:');

        $this->table(['Pair', 'Rate', 'Buy', 'Sell'], $live
            ->map(fn (ExchangeRate $row) => [
                $row->from_currency.' to '.$row->to_currency,
                number_format((float) $row->rate, 6),
                number_format((float) $row->buy_rate, 6),
                number_format((float) $row->sell_rate, 6),
            ])
            ->all());

        $this->line('A booking keeps the prices it was sold at, so a difference between the two is not a');
        $this->line('mistake on its own. It matters when the yuan or the dollars do not come out of the');
        $this->line('prices on the row, which the lines above mark as DOES NOT MATCH.');

        return self::SUCCESS;
    }
}
