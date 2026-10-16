<?php

/*
| Demo sign-ins. The same environment keys the seeder reads, so the login page can fill
| a password it was given rather than one it guessed. Nothing here holds a value: the
| passwords live in .env only, and the page prints them on a local or non-production
| host. Set these to the passwords the accounts already use and no reseeding is needed.
*/

return [
    'accounts' => [
        'superadmin@plussave.com' => env('SEED_SUPERADMIN_PASSWORD'),
        'admin@plussave.com' => env('SEED_ADMIN_PASSWORD'),
        'agent@plussave.com' => env('SEED_AGENT_PASSWORD'),
        'china.agent@plussave.com' => env('SEED_CHINA_AGENT_PASSWORD'),
        'accountant@plussave.com' => env('SEED_ACCOUNTANT_PASSWORD'),
    ],
];
