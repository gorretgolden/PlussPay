<?php

namespace App\Services;

use App\Models\ExchangeRate;
use App\Models\Setting;

/**
 * The one place that decides what a transfer earns.
 *
 * The operator enters the cash handed over and the charge taken out of it. Whatever is left is
 * the money that travels, and everything else follows from four rates and one question: what did
 * the yuan we delivered really cost us in shillings.
 *
 *   1. Charge, entered by hand. A customer paying shillings has it taken out of the money handed
 *      over; a customer paying dollars forwards the dollars whole and pays the charge on top.
 *   2. Exchange profit, which is cash in minus the cost of the yuan actually delivered. This is
 *      the figure the till can prove, so it is the one that is reported.
 *   3. Total profit, which is those two added and always stated in shillings.
 *
 * The exchange profit is still split into two stored legs so the trail survives. The shilling leg
 * is the cash collected less those dollars valued at what dollars cost us, and the yuan leg is the
 * rest, which is the gap between the rate the receiver is credited at and the rate China settles
 * at. The two add back to the exchange profit exactly, including whatever giving the dollar figure
 * to one decimal place hands over to the customer.
 */
class ProfitCalculator
{
    /**
     * Price one transfer from the cash handed over and the charge taken out of it.
     *
     * Returns the columns a booking needs, ready to merge into the create array. Null means a
     * rate the pricing depends on is missing, or the charge leaves nothing to send, both of
     * which the booking form refuses to save on.
     *
     * @return array<string, string>|null
     */
    public static function quote(string $currency, string $cashReceived, string $chargeUgx): ?array
    {
        $pair = ExchangeRate::usdUgxPair();
        $rmb = ExchangeRate::getActiveRate('USD', 'RMB');

        if (! $pair || $pair['sell'] <= 0 || ! $rmb) {
            return null;
        }

        $usdSell = (string) $pair['sell'];
        $usdBuy = (string) $pair['buy'];
        $rmbSell = (string) ($rmb->sell_rate ?: $rmb->rate);
        $rmbBuy = (string) ($rmb->buy_rate ?: $rmb->rate);

        if ((float) $rmbSell <= 0 || (float) $rmbBuy <= 0) {
            return null;
        }

        $paidInUgx = strtoupper($currency) === 'UGX';
        $charge = self::money($chargeUgx);
        $sent = $paidInUgx
            ? bcsub(self::money($cashReceived), $charge, 2)
            : self::money($cashReceived);

        if ((float) $sent <= 0) {
            return null;
        }

        // The dollars the customer is credited with are rounded to one decimal place, and the
        // yuan payout is priced from that rounded figure so the two can never disagree.
        $dollars = self::dollars($paidInUgx ? bcdiv($sent, $usdSell, 6) : $sent);
        $payout = self::money(bcmul($dollars, $rmbSell, 8));

        // What those dollars would have cost us at our own buying price. The cash collected above
        // it is the shilling leg, and what the delivered yuan cost below it is the yuan leg.
        $anchor = self::money(bcmul($dollars, $usdBuy, 8));
        $cost = self::money(bcmul(bcdiv($payout, $rmbBuy, 8), $usdBuy, 6));

        $shillingLeg = $paidInUgx ? bcsub($sent, $anchor, 2) : '0.00';
        $yuanLeg = bcsub($anchor, $cost, 2);

        return [
            'amount' => $sent,
            'total_usd_equivalent' => $dollars,
            'exchange_rate' => $rmbSell,
            'rmb_amount' => $payout,
            'rate_sell_ugx' => $usdSell,
            'rate_buy_ugx' => $usdBuy,
            'rmb_buying_rate' => $rmbBuy,
            'charge_ugx' => $charge,
            'charge_amount' => self::money(bcdiv($charge, $usdSell, 6)),
            'fx_profit_ugx' => $shillingLeg,
            'fx_profit_usd' => self::money(bcdiv($shillingLeg, $usdSell, 6)),
            'usd_rmb_profit_cny' => self::money(bcmul(bcdiv($yuanLeg, $usdBuy, 8), $rmbBuy, 8), 6),
            'usd_rmb_profit_ugx' => $yuanLeg,
            'total_profit_ugx' => bcadd(bcadd($shillingLeg, $yuanLeg, 2), $charge, 2),
        ];
    }

    /**
     * The charge the System Settings rule would suggest for this much cash, so the booking form
     * can open with a figure the operator is free to override.
     */
    public static function suggestedCharge(string $currency, string $cashReceived): ?string
    {
        $feeUgx = (float) Setting::get('service_fee_ugx', 2000);
        $feeBlockUsd = (float) Setting::get('service_fee_usd', 100);
        $usdSell = (string) (ExchangeRate::usdUgxPair()['sell'] ?? 0);

        if ($feeUgx <= 0 || $feeBlockUsd <= 0 || $usdSell <= 0) {
            return null;
        }

        $dollars = self::dollars(
            strtoupper($currency) === 'UGX'
                ? bcdiv(self::money($cashReceived), $usdSell, 6)
                : self::money($cashReceived)
        );

        return self::money(bcmul($dollars, (string) ($feeUgx / $feeBlockUsd), 4));
    }

    /**
     * The dollar figure a booking is priced on. One decimal place, half up, because that is the
     * precision the customer is credited with before the yuan payout is worked out.
     */
    public static function dollars(string $value): string
    {
        return number_format((float) $value, 1, '.', '');
    }

    private static function money(string $value, int $scale = 2): string
    {
        return number_format((float) $value, $scale, '.', '');
    }
}
