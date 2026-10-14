<?php

namespace App\Services;

use App\Models\ExchangeRate;

/**
 * The prices a currency exchange with a walk-in customer is worked out from.
 *
 * A bureau earns the gap between the two rates it quotes, so every deal is priced off the same
 * card the transfers use. What the customer hands over is worth what that currency fetches when
 * we sell it; what we hand back costs what it takes to replace it. The difference is the margin,
 * and it is always stated in shillings because that is what the till is kept in.
 *
 *   Shillings in, dollars out   dollars = shillings / selling rate
 *   Dollars in, shillings out   shillings = dollars x buying rate
 *   Shillings in, yuan out      yuan = (shillings / selling rate) x yuan per dollar we pay out
 *   Yuan in, shillings out      shillings = (yuan / yuan per dollar we buy at) x buying rate
 *   Dollars in, yuan out        yuan = dollars x yuan per dollar we pay out
 *   Yuan in, dollars out        dollars = yuan / yuan per dollar we buy at
 */
class ExchangeCalculator
{
    public const CURRENCIES = ['UGX', 'USD', 'RMB'];

    /**
     * The four prices the card holds, or null when a leg cannot be priced. Nothing here is
     * guessed at when a rate is missing: the caller is told there is no quote.
     *
     * @return array{usd_buy: string, usd_sell: string, rmb_buy: string, rmb_sell: string}|null
     */
    public static function card(): ?array
    {
        $usd = ExchangeRate::usdUgxPair();
        $rmb = ExchangeRate::getActiveRate('USD', 'RMB');

        if (! $usd || $usd['buy'] <= 0 || $usd['sell'] <= 0 || ! $rmb) {
            return null;
        }

        $rmbSell = (float) ($rmb->sell_rate ?: $rmb->rate);
        $rmbBuy = (float) ($rmb->buy_rate ?: $rmb->rate);

        if ($rmbSell <= 0 || $rmbBuy <= 0) {
            return null;
        }

        return [
            'usd_buy' => (string) $usd['buy'],
            'usd_sell' => (string) $usd['sell'],
            'rmb_buy' => (string) $rmbBuy,
            'rmb_sell' => (string) $rmbSell,
        ];
    }

    /**
     * What we would hand over for the money the customer brought in, at the card. The operator
     * can override it when a different price was agreed, and the margin then follows the figure
     * actually paid rather than the one the card suggested.
     */
    public static function suggest(?array $card, string $inCurrency, string $inAmount, string $outCurrency): ?string
    {
        if (! $card || (float) $inAmount <= 0 || $inCurrency === $outCurrency) {
            return null;
        }

        $amount = self::money($inAmount);

        return match ($inCurrency.'->'.$outCurrency) {
            'UGX->USD' => self::money(bcdiv($amount, $card['usd_sell'], 8)),
            'USD->UGX' => self::money(bcmul($amount, $card['usd_buy'], 4)),
            'UGX->RMB' => self::money(bcmul(bcdiv($amount, $card['usd_sell'], 8), $card['rmb_sell'], 6)),
            'RMB->UGX' => self::money(bcmul(bcdiv($amount, $card['rmb_buy'], 8), $card['usd_buy'], 4)),
            'USD->RMB' => self::money(bcmul($amount, $card['rmb_sell'], 4)),
            'RMB->USD' => self::money(bcdiv($amount, $card['rmb_buy'], 8)),
            default => null,
        };
    }

    /**
     * What the deal earned in shillings: the money taken in valued at what it fetches, less the
     * money handed over valued at what it would cost to replace. Null when the card cannot price
     * either side, which leaves the deal unpriced rather than guessed at.
     */
    public static function margin(?array $card, string $inCurrency, string $inAmount, string $outCurrency, string $outAmount): ?string
    {
        if (! $card) {
            return null;
        }

        $received = self::received($card, $inCurrency, $inAmount);
        $given = self::given($card, $outCurrency, $outAmount);

        if ($received === null || $given === null) {
            return null;
        }

        return self::money(bcsub($received, $given, 2));
    }

    /**
     * The shillings the money we took in is worth, priced at the rate we sell that currency at.
     */
    public static function received(?array $card, string $currency, string $amount): ?string
    {
        if (! $card) {
            return null;
        }

        $amount = self::money($amount);

        return match ($currency) {
            'UGX' => $amount,
            'USD' => self::money(bcmul($amount, $card['usd_sell'], 4)),
            'RMB' => self::money(bcmul(bcdiv($amount, $card['rmb_buy'], 8), $card['usd_sell'], 4)),
            default => null,
        };
    }

    /**
     * What the money we handed over would cost to replace, priced at the rate we buy that
     * currency back at.
     */
    public static function given(?array $card, string $currency, string $amount): ?string
    {
        if (! $card) {
            return null;
        }

        $amount = self::money($amount);

        return match ($currency) {
            'UGX' => $amount,
            'USD' => self::money(bcmul($amount, $card['usd_buy'], 4)),
            'RMB' => self::money(bcmul(bcdiv($amount, $card['rmb_sell'], 8), $card['usd_buy'], 4)),
            default => null,
        };
    }

    /**
     * One plain sentence saying which way round the card was applied, so the figure on screen
     * can be checked by hand against the rates in System Settings.
     */
    public static function rule(?array $card, string $inCurrency, string $outCurrency): ?string
    {
        if (! $card || $inCurrency === $outCurrency) {
            return null;
        }

        $usdBuy = self::shillings($card['usd_buy']);
        $usdSell = self::shillings($card['usd_sell']);
        $rmbBuy = rtrim(rtrim($card['rmb_buy'], '0'), '.');
        $rmbSell = rtrim(rtrim($card['rmb_sell'], '0'), '.');

        return match ($inCurrency.'->'.$outCurrency) {
            'UGX->USD' => "Dollars are sold to customers at UGX {$usdSell} each, so shillings divided by {$usdSell} gives the dollars.",
            'USD->UGX' => "Dollars are bought from customers at UGX {$usdBuy} each, so dollars times {$usdBuy} gives the shillings.",
            'UGX->RMB' => "Shillings are turned into dollars at UGX {$usdSell} to the dollar, and each dollar is paid out as {$rmbSell} yuan.",
            'RMB->UGX' => "Yuan are turned into dollars at {$rmbBuy} yuan to the dollar, and each dollar is worth UGX {$usdBuy}.",
            'USD->RMB' => "Each dollar is paid out as {$rmbSell} yuan.",
            'RMB->USD' => "Each dollar costs {$rmbBuy} yuan, so yuan divided by {$rmbBuy} gives the dollars.",
            default => null,
        };
    }

    private static function shillings(string $rate): string
    {
        return number_format((float) $rate, (float) $rate >= 100 ? 0 : 2);
    }

    private static function money(string $value): string
    {
        return number_format((float) $value, 2, '.', '');
    }
}
