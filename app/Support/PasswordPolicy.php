<?php

namespace App\Support;

use App\Models\Setting;

/**
 * The one password rule the whole app writes, so a form cannot accept what its own controller
 * will reject. Length is 8 to 72 in every mode; the only thing Site Mode moves is the letters
 * requirement.
 */
class PasswordPolicy
{
    public const MIN = 8;
    public const MAX = 72;

    /**
     * A desk in Testing works from demo accounts and shared sample figures, and the password it
     * is handed is the kind a customer would never be allowed to keep: 12345678, all digits. That
     * is the only reason the letters requirement lifts here, and Testing itself is refused on a
     * production host, so the two never overlap. Anything the office has not switched to
     * Production is treated as a desk still being set up.
     */
    public static function requiresLetters(): bool
    {
        return Setting::get('site_mode', 'testing') !== 'testing';
    }

    /**
     * @return list<string>
     */
    public static function rules(): array
    {
        return [
            'string',
            'min:'.self::MIN,
            'max:'.self::MAX,
            ...(static::requiresLetters() ? ['regex:/[a-zA-Z]/'] : []),
            'regex:/[0-9]/',
        ];
    }

    public static function ruleString(): string
    {
        return implode('|', static::rules());
    }

    /**
     * The browser is shown exactly the rule the server will apply. The users form turns native
     * validation off and reads these attributes itself, so a pattern that disagreed with the
     * controller would be a message the desk sees before anyone presses Save.
     */
    public static function pattern(): string
    {
        // The dot is part of the length rule, not decoration: a quantifier has to sit on the
        // character it counts, and (?=.*[0-9]){8,72} repeats the lookahead instead of the text.
        $length = '.{'.self::MIN.','.self::MAX.'}';

        return static::requiresLetters()
            ? '(?=.*[A-Za-z])(?=.*[0-9])'.$length
            : '(?=.*[0-9])'.$length;
    }

    public static function help(): string
    {
        return static::requiresLetters()
            ? '8 to 72 characters, including letters and numbers.'
            : '8 to 72 characters, with at least one number. Letters are optional while the site is in Testing.';
    }

    public static function message(): string
    {
        return static::requiresLetters()
            ? 'Use 8 to 72 characters, with letters and numbers.'
            : 'Use 8 to 72 characters, with at least one number.';
    }

    /**
     * The controller speaks as itself rather than as the field, so the same rule is worded twice
     * in this class on purpose: a desk reading a server rejection is not reading a hint under a
     * box it has not typed in yet.
     */
    public static function serverMessage(): string
    {
        return static::requiresLetters()
            ? 'Password must contain both letters and at least one number.'
            : 'Password must contain at least one number.';
    }
}
