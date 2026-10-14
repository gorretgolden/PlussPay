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
                'value' => 'Plus Save Financial Solutions',
                'type' => 'string',
                'description' => 'Company name displayed on receipts and reports',
            ],
            [
                'key' => 'company_phone',
                'value' => '+256 393 256 744 | +256 702 799 326',
                'type' => 'string',
                'description' => 'Call / WhatsApp numbers printed on receipts',
            ],
            [
                'key' => 'company_address',
                'value' => "Level 3 Room TA, Saasa Plaza Nabugabo Street\n& Level 2, Old Taxi Park - Aponye Mall",
                'type' => 'string',
                'description' => 'Branch addresses printed on receipts',
            ],
            [
                'key' => 'company_website',
                'value' => 'www.plussavefinancialsolutions.com',
                'type' => 'string',
                'description' => 'Website printed on receipts',
            ],
            [
                'key' => 'receipt_prefix',
                'value' => 'PS',
                'type' => 'string',
                'description' => 'Prefix for generated receipt numbers, e.g. PS-20261005-0001',
            ],
            [
                'key' => 'service_fee_ugx',
                'value' => '2000',
                'type' => 'float',
                'description' => 'Service fee charged in UGX for every service_fee_usd block sent',
            ],
            [
                'key' => 'service_fee_usd',
                'value' => '100',
                'type' => 'float',
                'description' => 'The USD amount the service fee is charged for, e.g. 100',
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
            [
                'key' => 'log_retention_days',
                'value' => '180',
                'type' => 'integer',
                'description' => 'How many days audit, login and system logs are kept before the daily prune deletes them',
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
