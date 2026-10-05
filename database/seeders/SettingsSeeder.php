<?php

namespace Database\Seeders;

use App\Models\Setting;
use Illuminate\Database\Seeder;

class SettingsSeeder extends Seeder
{
    public function run(): void
    {
        $settings = [
            [
                'key' => 'company_name',
                'value' => 'PlusPay',
                'type' => 'string',
                'description' => 'Company name displayed on receipts and reports',
            ],
            [
                'key' => 'default_charge_rate',
                'value' => '5',
                'type' => 'number',
                'description' => 'Default charge rate percentage per transaction',
            ],
            [
                'key' => 'max_login_attempts',
                'value' => '5',
                'type' => 'number',
                'description' => 'Maximum failed login attempts before account lockout',
            ],
            [
                'key' => 'lockout_duration_minutes',
                'value' => '15',
                'type' => 'number',
                'description' => 'Duration in minutes for account lockout after max failed attempts',
            ],
        ];

        foreach ($settings as $setting) {
            Setting::firstOrCreate(
                ['key' => $setting['key']],
                [
                    'value' => $setting['value'],
                    'type' => $setting['type'],
                    'description' => $setting['description'],
                ]
            );
        }
    }
}
