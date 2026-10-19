<?php

namespace App\Services;

use App\Models\ExchangeRate;

/**
 * The one place that decides what a transfer earns.
 *
 * The operator enters the cash handed over, the charge taken out of it, and which way the money is
 * going. Whatever the route, the book answers the same question the office asks of it: the shillings
 * that came in, less the shillings' worth of what went out. The two differ by the spread on every
 * leg the money was turned through, plus the charge, and that difference is the profit.
 *
 * The desk always turns money at its own prices, and its prices lean its way: money a customer hands
 * over is valued at what it costs the desk to obtain, and money a customer is credited with is
 * priced at what the desk hands over for it. So a booking to China credits the receiver at the
 * selling prices and settles at the buying ones, and a booking into Uganda takes the customer's yuan
 * at the buying prices and turns it over at the selling ones. Both ways the figure the receiver is
 * handed is the one the desk keeps its margin on.
 *
 * Three routes are priced here:
 *   - Uganda in, yuan out in China: the charge comes out of the shillings handed over, the dollars
 *     are divided out at full precision, and the yuan is priced from those unrounded dollars.
 *   - Uganda in, dollars out in China: the same money that travels is simply not turned into yuan,
 *     so no yuan price is read and no yuan leg is booked.
 *   - China in, shillings out in Uganda: the yuan or dollars handed over in China are worth a fixed
 *     number of shillings at the desk's buying prices, the charge comes out of those shillings, and
 *     what the receiver is handed is all that is left.
 *
 * The exchange profit is kept in two stored legs so the trail survives: the shilling leg is the
 * spread on the dollar-to-shilling turn, and the yuan leg is the spread on the yuan-to-dollar turn.
 * The two add back to the exchange profit exactly, including whatever rounding the stored dollar,
 * yuan and shilling figures hand over to the customer.
 */
class ProfitCalculator
{
    public const TO_CHINA = 'CN';

    public const TO_UGANDA = 'UG';

    /**
     * Price one transfer from the cash handed over, the charge taken out of it, and the route.
     *
     * Returns the columns a booking needs, ready to merge into the create array. Null means a rate
     * that this route depends on is missing, or the charge leaves nothing to send, both of which the
     * booking form refuses to save on. A route that never touches yuan does not need a yuan price.
     *
     * @return array<string, string>|null
     */
    public static function quote(
        string $currency,
        string $cashReceived,
        string $chargeUgx,
        string $destination = self::TO_CHINA,
        string $payoutCurrency = 'RMB',
    ): ?array {
        return match (true) {
            strtoupper($destination) === self::TO_UGANDA => static::quoteToUganda($currency, $cashReceived, $chargeUgx),
            strtoupper($payoutCurrency) === 'USD' => static::quoteDollarsToChina($currency, $cashReceived, $chargeUgx),
            default => static::quoteYuanToChina($currency, $cashReceived, $chargeUgx),
        };
    }

    /**
     * Which prices a route cannot be booked without, named the way the rate sheet prints them. The
     * booking form asks so it can refuse with the pair that is missing rather than a rate the
     * customer was never charged.
     *
     * @return array<int, string>
     */
    public static function requiredPairs(
        string $currency,
        string $destination = self::TO_CHINA,
        string $payoutCurrency = 'RMB',
    ): array {
        $toUganda = strtoupper($destination) === self::TO_UGANDA;
        $pairs = [['USD', 'UGX']];

        // Yuan only has to be priced where it is either promised to a receiver in China or handed
        // over by a customer in China. Dollars that stop at China, and dollars that arrive in
        // China, are both turned by the shilling price alone.
        if (! $toUganda && strtoupper($payoutCurrency) !== 'USD') {
            $pairs[] = ['USD', 'RMB'];
        } elseif ($toUganda && strtoupper($currency) === 'RMB') {
            $pairs[] = ['USD', 'RMB'];
        }

        return array_map(fn (array $pair) => $pair[0].' → '.$pair[1], $pairs);
    }

    /**
     * Uganda in, yuan out: the flow the desk has run since it opened.
     *
     * @return array<string, string>|null
     */
    private static function quoteYuanToChina(string $currency, string $cashReceived, string $chargeUgx): ?array
    {
        $ugx = static::shillingPrices();
        $rmb = static::yuanPrices();

        if (! $ugx || ! $rmb) {
            return null;
        }

        [$usdSell, $usdBuy] = $ugx;
        [$rmbSell, $rmbBuy] = $rmb;

        $paidInUgx = static::isShillings($currency);
        $charge = self::money($chargeUgx);
        $sent = $paidInUgx ? bcsub(self::money($cashReceived), $charge, 2) : self::money($cashReceived);

        if ((float) $sent <= 0) {
            return null;
        }

        // The dollars are divided out at full precision and the yuan payout is worked from that
        // unrounded figure, so the receiver is never priced off a credit nobody was given. Each
        // figure is rounded once, for storing, which is why the two can show a cent apart.
        $exact = $paidInUgx ? bcdiv($sent, $usdSell, 10) : $sent;
        $dollars = self::money($exact);
        $payout = self::money(bcmul($exact, $rmbSell, 10));

        // What those dollars would have cost us at our own buying price. The cash collected above
        // it is the shilling leg, and what the delivered yuan cost below it is the yuan leg.
        $anchor = self::money(bcmul($dollars, $usdBuy, 8));
        $cost = self::money(bcmul(bcdiv($payout, $rmbBuy, 8), $usdBuy, 6));

        $shillingLeg = $paidInUgx ? bcsub($sent, $anchor, 2) : '0.00';
        $yuanLeg = bcsub($anchor, $cost, 2);

        return static::frame(
            destination: self::TO_CHINA,
            payoutCurrency: 'RMB',
            amount: $sent,
            dollars: $dollars,
            charge: $charge,
            usdSell: $usdSell,
            usdBuy: $usdBuy,
            rmbBuy: $rmbBuy,
            yuanRate: $rmbSell,
            yuan: $payout,
        ) + [
            'fx_profit_ugx' => $shillingLeg,
            'fx_profit_usd' => self::money(bcdiv($shillingLeg, $usdSell, 6)),
            'usd_rmb_profit_cny' => self::money(bcmul(bcdiv($yuanLeg, $usdBuy, 8), $rmbBuy, 8), 6),
            'usd_rmb_profit_ugx' => $yuanLeg,
            'total_profit_ugx' => bcadd(bcadd($shillingLeg, $yuanLeg, 2), $charge, 2),
        ];
    }

    /**
     * Uganda in, dollars out: the receiver in China is paid dollars, so the money that travels is
     * never turned into yuan and no yuan price is read for it.
     *
     * @return array<string, string>|null
     */
    private static function quoteDollarsToChina(string $currency, string $cashReceived, string $chargeUgx): ?array
    {
        $ugx = static::shillingPrices();

        if (! $ugx) {
            return null;
        }

        [$usdSell, $usdBuy] = $ugx;

        $paidInUgx = static::isShillings($currency);
        $charge = self::money($chargeUgx);
        $sent = $paidInUgx ? bcsub(self::money($cashReceived), $charge, 2) : self::money($cashReceived);

        if ((float) $sent <= 0) {
            return null;
        }

        // The dollars the receiver is handed are the dollars the booking is credited with, so the
        // figure is divided out once, at full precision, and rounded once, for storing.
        $dollars = self::money($paidInUgx ? bcdiv($sent, $usdSell, 10) : $sent);

        // What the desk would have had to pay to obtain those dollars is the only cost this route
        // carries: there is no second turn through yuan to price.
        $anchor = self::money(bcmul($dollars, $usdBuy, 8));
        $shillingLeg = $paidInUgx ? bcsub($sent, $anchor, 2) : '0.00';

        return static::frame(
            destination: self::TO_CHINA,
            payoutCurrency: 'USD',
            amount: $sent,
            dollars: $dollars,
            charge: $charge,
            usdSell: $usdSell,
            usdBuy: $usdBuy,
        ) + [
            'fx_profit_ugx' => $shillingLeg,
            'fx_profit_usd' => self::money(bcdiv($shillingLeg, $usdSell, 6)),
            'usd_rmb_profit_cny' => '0.000000',
            'usd_rmb_profit_ugx' => '0.00',
            'total_profit_ugx' => bcadd($shillingLeg, $charge, 2),
        ];
    }

    /**
     * China in, shillings out: a customer in China hands over yuan or dollars and a receiver in
     * Uganda is paid shillings out of the till there.
     *
     * The money handed over is valued at the prices at which the desk obtains it, which is the same
     * spread the desk charges on the way out, and it is why the shillings paid over come to fewer
     * than the face value of the yuan. What the desk then turns those dollars into at its own
     * selling prices is what it earned the difference on.
     *
     * @return array<string, string>|null
     */
    private static function quoteToUganda(string $currency, string $cashReceived, string $chargeUgx): ?array
    {
        $ugx = static::shillingPrices();

        if (! $ugx) {
            return null;
        }

        [$usdSell, $usdBuy] = $ugx;

        $intake = strtoupper($currency);
        $inYuan = $intake === 'RMB';

        if ($intake === 'UGX') {
            return null;
        }

        $rmb = $inYuan ? static::yuanPrices() : null;

        if ($inYuan && ! $rmb) {
            return null;
        }

        [$rmbSell, $rmbBuy] = $rmb ?? [null, null];

        $cash = self::money($cashReceived);
        $charge = self::money($chargeUgx);

        // Every yuan or dollar handed over is taken in, so the charge does not come out of the
        // intake. It comes out of the shillings paid over in Uganda instead, which is the only
        // shilling figure this route has.
        $dollars = self::money($inYuan ? bcdiv($cash, $rmbBuy, 10) : $cash);
        $credited = self::money(bcmul($dollars, $usdBuy, 8));
        $payout = bcsub($credited, $charge, 2);

        if ((float) $payout <= 0) {
            return null;
        }

        // The dollars taken in, turned over at the price the desk sells them at, and the yuan
        // handed to the desk, turned back at the price it sells dollars for. That is what the money
        // the desk now holds is worth to it, and the payout above is what it gave away.
        $anchor = self::money(bcmul($dollars, $usdSell, 8));
        $realized = self::money(bcmul($inYuan ? bcdiv($cash, $rmbSell, 10) : $dollars, $usdSell, 8));

        $shillingLeg = bcsub($anchor, $credited, 2);
        $yuanLeg = bcsub($realized, $anchor, 2);

        return static::frame(
            destination: self::TO_UGANDA,
            payoutCurrency: 'UGX',
            amount: $cash,
            dollars: $dollars,
            charge: $charge,
            usdSell: $usdSell,
            usdBuy: $usdBuy,
            rmbBuy: $rmbBuy,
            yuanRate: $rmbBuy,
            shillingPayout: $payout,
        ) + [
            'fx_profit_ugx' => $shillingLeg,
            'fx_profit_usd' => self::money(bcdiv($shillingLeg, $usdSell, 6)),
            'usd_rmb_profit_cny' => $inYuan
                ? self::money(bcmul(bcdiv($yuanLeg, $usdBuy, 8), $rmbBuy, 8), 6)
                : '0.000000',
            'usd_rmb_profit_ugx' => $yuanLeg,
            'total_profit_ugx' => bcadd(bcadd($shillingLeg, $yuanLeg, 2), $charge, 2),
        ];
    }

    /**
     * The columns every route fills, so a booking always says what was taken in, what was credited
     * in dollars, what was charged, and the prices it was priced at. Figures that a route does not
     * carry are left at zero rather than guessed at, which is what keeps them out of the totals the
     * board reads for the other corridor.
     *
     * @return array<string, string>
     */
    private static function frame(
        string $destination,
        string $payoutCurrency,
        string $amount,
        string $dollars,
        string $charge,
        string $usdSell,
        string $usdBuy,
        ?string $rmbBuy = null,
        ?string $yuanRate = null,
        string $yuan = '0.00',
        string $shillingPayout = '0.00',
    ): array {
        return [
            'destination' => $destination,
            'payout_currency' => $payoutCurrency,
            'amount' => $amount,
            'total_usd_equivalent' => $dollars,
            'exchange_rate' => $yuanRate ?? '0',
            'rmb_buying_rate' => $rmbBuy ?? '0',
            'rate_sell_ugx' => $usdSell,
            'rate_buy_ugx' => $usdBuy,
            'rmb_amount' => $yuan,
            'ugx_amount' => $shillingPayout,
            'charge_ugx' => $charge,
            'charge_amount' => self::money(bcdiv($charge, $usdSell, 6)),
        ];
    }

    /**
     * What one dollar costs the desk in shillings, and what the desk hands over for one.
     *
     * @return array{0: string, 1: string}|null
     */
    private static function shillingPrices(): ?array
    {
        $pair = ExchangeRate::usdUgxPair();

        if (! $pair || $pair['sell'] <= 0 || $pair['buy'] <= 0) {
            return null;
        }

        return [self::money((string) $pair['sell'], 6), self::money((string) $pair['buy'], 6)];
    }

    /**
     * What one dollar is worth in yuan when the desk sells it, and what the desk pays to obtain one.
     *
     * @return array{0: string, 1: string}|null
     */
    private static function yuanPrices(): ?array
    {
        $row = ExchangeRate::getActiveRate('USD', 'RMB');

        if (! $row) {
            return null;
        }

        $sell = self::money((string) ($row->sell_rate ?: $row->rate), 6);
        $buy = self::money((string) ($row->buy_rate ?: $row->rate), 6);

        return (float) $sell <= 0 || (float) $buy <= 0 ? null : [$sell, $buy];
    }

    private static function isShillings(string $currency): bool
    {
        return strtoupper($currency) === 'UGX';
    }

    private static function money(string $value, int $scale = 2): string
    {
        return number_format((float) $value, $scale, '.', '');
    }
}
