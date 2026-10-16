<?php

/*
| Demo sign-ins. The accounts are the seeder's own list, so the login page can never show a
| button for an account the seed does not make. Each email maps to the environment key that
| holds its password. Nothing here holds a value: the passwords live in .env only, and the
| page prints them on a local or non-production host. Set these to the passwords the accounts
| already use and no reseeding is needed.
*/

return [
    'accounts' => collect(\Database\Seeders\RoleAndUserSeeder::DEMO_ACCOUNTS)
        ->mapWithKeys(fn (array $account) => [$account['email'] => env($account['env'])])
        ->all(),
];
