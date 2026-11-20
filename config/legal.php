<?php

/*
|--------------------------------------------------------------------------
| Public legal pages (/privacy-policy, /terms-conditions)
|--------------------------------------------------------------------------
| Set the real values in .env — they appear on the public pages and are
| what Meta reviewers and customers will read.
*/

$host = parse_url((string) env('APP_URL', 'https://whats-apis.com'), PHP_URL_HOST) ?: 'whats-apis.com';

return [
    'company' => env('LEGAL_COMPANY_NAME', 'KN Softic'),
    'product' => env('LEGAL_PRODUCT_NAME', 'KN Softic – WhatsApp Cloud API Manager'),
    'website' => env('LEGAL_WEBSITE', $host),
    'email' => env('LEGAL_CONTACT_EMAIL', 'support@'.$host),
    'address' => env('LEGAL_ADDRESS'),                       // optional postal address
    'jurisdiction' => env('LEGAL_JURISDICTION', 'India'),
    'effective_date' => env('LEGAL_EFFECTIVE_DATE', '2026-11-20'),
];
