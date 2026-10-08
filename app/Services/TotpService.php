<?php

namespace App\Services;

/**
 * RFC 6238 time-based one-time codes, so codes work in Google Authenticator,
 * FreeOTP and Authy without adding a package to the deploy.
 */
class TotpService
{
    private const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    private const PERIOD = 30;

    private const DIGITS = 6;

    private const WINDOW = 1;

    public static function generateSecret(): string
    {
        return self::encode32(random_bytes(20));
    }

    public static function code(string $secret, ?int $timestamp = null): string
    {
        $timestamp ??= time();

        return self::hot(self::decode32($secret), intdiv($timestamp, self::PERIOD));
    }

    public static function verify(string $secret, string $code, ?int $timestamp = null): bool
    {
        $code = preg_replace('/\D/', '', $code);

        if (strlen($code) !== self::DIGITS || ! self::isValidSecret($secret)) {
            return false;
        }

        $timestamp ??= time();
        $counter = intdiv($timestamp, self::PERIOD);
        $key = self::decode32($secret);

        for ($offset = -self::WINDOW; $offset <= self::WINDOW; $offset++) {
            if (hash_equals(self::hot($key, $counter + $offset), $code)) {
                return true;
            }
        }

        return false;
    }

    public static function provisioningUri(string $secret, string $account, string $issuer = 'PlusPay'): string
    {
        return sprintf(
            'otpauth://totp/%s:%s?secret=%s&issuer=%s&algorithm=SHA1&digits=%d&period=%d',
            rawurlencode($issuer),
            rawurlencode($account),
            $secret,
            rawurlencode($issuer),
            self::DIGITS,
            self::PERIOD
        );
    }

    public static function formatSecret(string $secret): string
    {
        return trim(chunk_split($secret, 4, ' '));
    }

    public static function isValidSecret(string $secret): bool
    {
        return $secret !== '' && preg_match('/^[A-Z2-7]{16,64}$/', $secret) === 1;
    }

    private static function hot(string $key, int $counter): string
    {
        // A 4-byte counter is enough for a 30 second step until 2106.
        $challenge = pack('N', 0) . pack('N', $counter);
        $hash = hash_hmac('sha1', $challenge, $key, true);
        $offset = ord($hash[19]) & 0x0f;

        $value = ((ord($hash[$offset]) & 0x7f) << 24)
            | ((ord($hash[$offset + 1]) & 0xff) << 16)
            | ((ord($hash[$offset + 2]) & 0xff) << 8)
            | (ord($hash[$offset + 3]) & 0xff);

        return str_pad((string) ($value % 1_000_000), self::DIGITS, '0', STR_PAD_LEFT);
    }

    private static function encode32(string $data): string
    {
        $output = '';
        $buffer = 0;
        $bitsLeft = 0;

        foreach (str_split($data) as $char) {
            $buffer = ($buffer << 8) | ord($char);
            $bitsLeft += 8;

            while ($bitsLeft >= 5) {
                $bitsLeft -= 5;
                $output .= self::ALPHABET[($buffer >> $bitsLeft) & 0x1f];
            }
        }

        if ($bitsLeft > 0) {
            $output .= self::ALPHABET[($buffer << (5 - $bitsLeft)) & 0x1f];
        }

        return $output;
    }

    private static function decode32(string $secret): string
    {
        $secret = strtoupper(preg_replace('/[^A-Za-z2-7]/', '', $secret));
        $buffer = 0;
        $bitsLeft = 0;
        $output = '';

        foreach (str_split($secret) as $char) {
            $index = strpos(self::ALPHABET, $char);

            if ($index === false) {
                continue;
            }

            $buffer = ($buffer << 5) | $index;
            $bitsLeft += 5;

            if ($bitsLeft >= 8) {
                $bitsLeft -= 8;
                $output .= chr(($buffer >> $bitsLeft) & 0xff);
            }
        }

        return $output;
    }
}
