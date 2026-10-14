<?php

namespace App\Services;

use App\Models\ExchangeRate;
use App\Models\Setting;

/**
 * The one place that decides what a transfer earns.
 *
 * A transfer makes money in three separate places, and two of them run in opposite
 * directions, which is why the signs here are worth reading twice:
 *
 *   1. The service fee, quoted as shillings per block of dollars.
 *   2. The shilling leg. The customer's shillings are converted at the selling price while
 *      the dollars they buy cost us the buying price, so selling above buying is a gain.
 *   3. The yuan leg. The China side settles with us at the buying price but the recipient is
 *      credited at the selling price, so selling above buying is a LOSS. This is the only
 *      figure that was never stored anywhere, and therefore the only one nobody could see.
 *
 * The fee and the shilling leg keep the two decimal places they have always been stored with,
 * so no figure already in the books moves. The yuan leg is held at six places and rounded
 * only on screen, and the total is the sum of the stored parts so a breakdown always adds up.
 */
class ProfitCalculator
{
    /**
     * Price one transfer from the currency the customer paid in and the dollar value of it.
     *
     * Returns the columns a booking needs, ready to merge into the create array. Null means
     * the rates or the fee block are missing, which is the same condition the booking form
     * already refuses to save on.
     *
     * @return array<string, string|null>|null
     */
    public static function quote(string $currency, string $usdEquivalent): ?array
    {
        $feeUgx = (float) Setting::get('service_fee_ugx', 2000);
        $feeBlockUsd = (float) Setting::get('service_fee_usd', 100);
        $usdToUgx = (float) (ExchangeRate::usdToUgx() ?? 0);

        if ($usdToUgx <= 0 || $feeBlockUsd <= 0) {
            return null;
        }

        $ugxPerUsd = $feeUgx / $feeBlockUsd;
        $chargeUgx = bcmul($usdEquivalent, (string) $ugxPerUsd, 2);

        $pair = ExchangeRate::usdUgxPair();
        $priced = $pair && (float) $pair['sell'] > 0;

        // Only shillings handed over by the customer ever go through the till at a spread.
        // Dollars paid in directly are forwarded as they are, so there is no leg to profit on.
        $paidInUgx = strtoupper($currency) === 'UGX';
        $ugxProfit = $priced && $paidInUgx
            ? bcmul($usdEquivalent, bcsub((string) $pair['sell'], (string) $pair['buy'], 6), 2)
            : '0.00';

        // The recipient is credited at the rate the conversion itself used, so this can never
        // disagree with the yuan that were actually paid out.
        $rmb = ExchangeRate::getActiveRate('USD', 'RMB');
        $rmbSell = $rmb ? (string) $rmb->rate : null;
        $rmbBuy = $rmb ? (string) ($rmb->buy_rate ?: $rmb->rate) : null;

        $cnyProfit = $rmbBuy !== null && $rmbSell !== null
            ? bcmul($usdEquivalent, bcsub($rmbBuy, $rmbSell, 6), 6)
            : null;

        // Yuan lost or gained converts back to shillings at what shillings cost us, not at
        // what we sell them for, because that is the price the business really pays.
        $cnyProfitUgx = $cnyProfit !== null && $priced && (float) $rmbBuy > 0
            ? bcmul(bcdiv($cnyProfit, $rmbBuy, 8), (string) $pair['buy'], 6)
            : null;

        return [
            'charge_ugx' => $chargeUgx,
            'charge_amount' => bcdiv($chargeUgx, (string) $usdToUgx, 2),
            'charge_rate' => (string) ($ugxPerUsd * 100 / $usdToUgx),
            'rate_buy_ugx' => $pair ? (string) $pair['buy'] : null,
            'rate_sell_ugx' => $pair ? (string) $pair['sell'] : null,
            'fx_profit_ugx' => $ugxProfit,
            'fx_profit_usd' => $priced ? bcdiv($ugxProfit, (string) $pair['sell'], 2) : '0.00',
            'rmb_buying_rate' => $rmbBuy,
            'usd_rmb_profit_cny' => $cnyProfit,
            'usd_rmb_profit_ugx' => $cnyProfitUgx,
            // An unknown yuan leg makes the total unknown. Calling it zero would turn a
            // transfer that lost money into one that appears to have broken even.
            'total_profit_ugx' => $cnyProfitUgx === null
                ? null
                : bcadd(bcadd($chargeUgx, $ugxProfit, 6), $cnyProfitUgx, 6),
        ];
    }
}
