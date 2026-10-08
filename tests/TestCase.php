<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\MessageBag;
use Illuminate\Support\ViewErrorBag;

abstract class TestCase extends BaseTestCase
{
    /**
     * Reads the message a user would actually see for a flashed validation error.
     */
    protected function flashedError(string $key): string
    {
        $errors = session('errors');

        if ($errors instanceof MessageBag || $errors instanceof ViewErrorBag) {
            return (string) $errors->first($key);
        }

        if (is_array($errors)) {
            foreach ($errors as $value) {
                if ($value instanceof ViewErrorBag) {
                    $value = $value->getBag('default');
                }

                if ($value instanceof MessageBag && $value->has($key)) {
                    return (string) $value->first($key);
                }

                // An aged flash bag arrives as plain arrays.
                if (is_array($value) && isset($value['messages'][$key][0])) {
                    return (string) $value['messages'][$key][0];
                }
            }

            return (string) ($errors[$key][0] ?? '');
        }

        return '';
    }
}
