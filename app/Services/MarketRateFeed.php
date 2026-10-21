<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class MarketRateFeed
{
    /**
     * The app calls the Chinese yuan RMB; the feed quotes it as CNY.
     * The bounds catch a feed that changes units or sends a broken figure.
     */
    private const BOUNDS = [
        'UGX' => [500, 20000],
        'RMB' => [1, 20],
    ];

    /**
     * The mid-market dollar price from the first source that answers with usable
     * figures. Google's converter has no rate API, so this reads the same kind of
     * open mid-market figure and names the source that supplied it.
     *
     * @return array{rates: array<string, float>, source: string, rate_date: string}
     */
    public function latest(): array
    {
        $errors = [];

        foreach ($this->sources() as $source) {
            try {
                $response = Http::timeout(8)->acceptJson()->get($source['url']);

                if (! $response->successful()) {
                    $errors[] = $source['name'].' answered '.$response->status().'.';

                    continue;
                }

                $reading = $this->read($source['shape'], $response, $source['name']);

                if ($reading !== null) {
                    return $reading;
                }

                $errors[] = $source['name'].' sent figures outside the usable range.';
            } catch (\Throwable $exception) {
                $errors[] = $source['name'].': '.$exception->getMessage();
            }
        }

        Log::warning('No market rate could be read from any source.', ['errors' => $errors]);

        throw new RuntimeException('The market rate could not be reached. Check the internet connection and pull again.');
    }

    /**
     * One dated reading from the same feed, used once to give a fresh install a short
     * history to draw. The dated files live on jsDelivr because the latest mirror
     * only serves today.
     *
     * @return array{rates: array<string, float>, source: string, rate_date: string}|null
     */
    public function history(CarbonInterface $date): ?array
    {
        $url = 'https://cdn.jsdelivr.net/npm/@fawazahmed0/currency-api@'.$date->toDateString().'/v1/currencies/usd.json';

        try {
            $response = Http::timeout(5)->acceptJson()->get($url);

            return $response->successful() ? $this->read('nested', $response, 'cdn.jsdelivr.net') : null;
        } catch (\Throwable $exception) {
            Log::warning('A dated market rate could not be read.', [
                'date' => $date->toDateString(),
                'error' => $exception->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * @return array<int, array{name: string, url: string, shape: string}>
     */
    private function sources(): array
    {
        return [
            [
                'name' => 'currency-api.pages.dev',
                'url' => 'https://latest.currency-api.pages.dev/v1/currencies/usd.json',
                'shape' => 'nested',
            ],
            [
                'name' => 'open.er-api.com',
                'url' => 'https://open.er-api.com/v6/latest/USD',
                'shape' => 'flat',
            ],
        ];
    }

    /**
     * @return array{rates: array<string, float>, source: string, rate_date: string}|null
     */
    private function read(string $shape, Response $response, string $source): ?array
    {
        $data = $response->json();

        if (! is_array($data)) {
            return null;
        }

        if ($shape === 'nested') {
            $ugx = data_get($data, 'usd.ugx');
            $rmb = data_get($data, 'usd.cny');
            $date = $data['date'] ?? null;
        } else {
            if (($data['result'] ?? null) !== 'success') {
                return null;
            }

            $ugx = data_get($data, 'rates.UGX');
            $rmb = data_get($data, 'rates.CNY');
            $date = isset($data['time_last_update_unix'])
                ? CarbonImmutable::createFromTimestampUTC((int) $data['time_last_update_unix'])
                    ->setTimezone(config('app.timezone'))
                    ->toDateString()
                : null;
        }

        $rates = [
            'UGX' => $this->number($ugx, 'UGX'),
            'RMB' => $this->number($rmb, 'RMB'),
        ];

        if ($rates['UGX'] === null || $rates['RMB'] === null) {
            return null;
        }

        return [
            'rates' => $rates,
            'source' => $source,
            'rate_date' => $this->date($date),
        ];
    }

    private function number(mixed $value, string $quote): ?float
    {
        if (! is_numeric($value)) {
            return null;
        }

        $number = (float) $value;
        [$minimum, $maximum] = self::BOUNDS[$quote];

        return is_finite($number) && $number >= $minimum && $number <= $maximum ? $number : null;
    }

    private function date(mixed $value): string
    {
        if (is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1) {
            return $value;
        }

        return now()->toDateString();
    }
}
