<?php

namespace App\Support;

use App\Models\Setting;
use Throwable;

/**
 * Website & application settings edited on Settings (Super Admin).
 * Every value is stored encrypted in the `settings` table; defaults below
 * apply until something is saved.
 */
class PlatformSettings
{
    /** Keys → default value. Secrets are never shown back in full. */
    public const DEFAULTS = [
        // General
        'site.name' => 'KN Softic',
        'site.tagline' => 'WhatsApp Cloud API Manager',
        'site.support_email' => null,
        'site.support_phone' => null,
        'site.logo' => null,                 // path under public/, e.g. uploads/branding/logo.png
        'platform.registration_enabled' => '1',

        // Appearance
        'theme.brand_color' => 'emerald',
        'theme.default' => 'system',         // system | light | dark
        'theme.allow_toggle' => '1',

        // Billing & payments
        'billing.currency' => 'PKR',
        'billing.self_purchase' => '0',
        'billing.pkpay_enabled' => '0',
        'billing.pkpay_base_url' => 'https://pkpay.site/api/v1',
        'billing.pkpay_merchant_id' => null,
        'billing.pkpay_api_key' => null,
        'billing.pkpay_api_secret' => null,
        'billing.manual_enabled' => '0',
        'billing.manual_instructions' => null,

        // Email (SMTP)
        'mail.enabled' => '0',
        'mail.host' => null,
        'mail.port' => '587',
        'mail.username' => null,
        'mail.password' => null,
        'mail.encryption' => 'tls',
        'mail.from_address' => null,
        'mail.from_name' => null,

        // Legal pages
        'legal.company' => null,
        'legal.email' => null,
        'legal.address' => null,
        'legal.jurisdiction' => null,
    ];

    public const SECRETS = ['billing.pkpay_api_secret', 'billing.pkpay_api_key', 'mail.password'];

    /** Tailwind palettes (RGB triplets 50…950) for the brand colour. */
    public const PALETTES = [
        'emerald' => ['236 253 245', '209 250 229', '167 243 208', '110 231 183', '52 211 153', '16 185 129', '5 150 105', '4 120 87', '6 95 70', '6 78 59', '2 44 34'],
        'green' => ['240 253 244', '220 252 231', '187 247 208', '134 239 172', '74 222 128', '34 197 94', '22 163 74', '21 128 61', '22 101 52', '20 83 45', '5 46 22'],
        'teal' => ['240 253 250', '204 251 241', '153 246 228', '94 234 212', '45 212 191', '20 184 166', '13 148 136', '15 118 110', '17 94 89', '19 78 74', '4 47 46'],
        'sky' => ['240 249 255', '224 242 254', '186 230 253', '125 211 252', '56 189 248', '14 165 233', '2 132 199', '3 105 161', '7 89 133', '12 74 110', '8 47 73'],
        'blue' => ['239 246 255', '219 234 254', '191 219 254', '147 197 253', '96 165 250', '59 130 246', '37 99 235', '29 78 216', '30 64 175', '30 58 138', '23 37 84'],
        'indigo' => ['238 242 255', '224 231 255', '199 210 254', '165 180 252', '129 140 248', '99 102 241', '79 70 229', '67 56 202', '55 48 163', '49 46 129', '30 27 75'],
        'violet' => ['245 243 255', '237 233 254', '221 214 254', '196 181 253', '167 139 250', '139 92 246', '124 58 237', '109 40 217', '91 33 182', '76 29 149', '46 16 101'],
        'purple' => ['250 245 255', '243 232 255', '233 213 255', '216 180 254', '192 132 252', '168 85 247', '147 51 234', '126 34 206', '107 33 168', '88 28 135', '59 7 100'],
        'rose' => ['255 241 242', '255 228 230', '254 205 211', '253 164 175', '251 113 133', '244 63 94', '225 29 72', '190 18 60', '159 18 57', '136 19 55', '76 5 25'],
        'orange' => ['255 247 237', '255 237 213', '254 215 170', '253 186 116', '251 146 60', '249 115 22', '234 88 12', '194 65 12', '154 52 18', '124 45 18', '67 20 7'],
        'amber' => ['255 251 235', '254 243 199', '253 230 138', '252 211 77', '251 191 36', '245 158 11', '217 119 6', '180 83 9', '146 64 14', '120 53 15', '69 26 3'],
    ];

    public const SHADES = [50, 100, 200, 300, 400, 500, 600, 700, 800, 900, 950];

    public const CURRENCIES = ['PKR' => 'Pakistani Rupee (PKR)', 'INR' => 'Indian Rupee (INR)', 'USD' => 'US Dollar (USD)', 'AED' => 'UAE Dirham (AED)', 'SAR' => 'Saudi Riyal (SAR)', 'GBP' => 'British Pound (GBP)', 'EUR' => 'Euro (EUR)', 'BDT' => 'Bangladeshi Taka (BDT)'];

    /** @var array<string, string|null>|null */
    private ?array $loaded = null;

    public function get(string $key, ?string $default = null): ?string
    {
        $value = Setting::get($key);

        return $value ?? $default ?? (self::DEFAULTS[$key] ?? null);
    }

    public function bool(string $key): bool
    {
        return $this->get($key) === '1';
    }

    public function put(string $key, ?string $value): void
    {
        Setting::put($key, $value);
    }

    // -------------------------------------------------------------- shortcuts

    public function siteName(): string
    {
        return (string) $this->get('site.name');
    }

    public function tagline(): string
    {
        return (string) $this->get('site.tagline');
    }

    public function logoUrl(): ?string
    {
        $path = $this->get('site.logo');

        return $path && is_file(public_path($path)) ? asset($path).'?v='.filemtime(public_path($path)) : null;
    }

    public function brandColor(): string
    {
        $color = (string) $this->get('theme.brand_color');

        return isset(self::PALETTES[$color]) ? $color : 'emerald';
    }

    public function defaultTheme(): string
    {
        $theme = (string) $this->get('theme.default');

        return in_array($theme, ['light', 'dark', 'system'], true) ? $theme : 'system';
    }

    public function currency(): string
    {
        return (string) $this->get('billing.currency');
    }

    /** CSS custom properties for the brand palette (used by Tailwind's `brand-*` classes). */
    public function brandCss(): string
    {
        $palette = self::PALETTES[$this->brandColor()];
        $vars = [];

        foreach (self::SHADES as $i => $shade) {
            $vars[] = "--brand-{$shade}: {$palette[$i]};";
        }

        return ':root{'.implode('', $vars).'}';
    }

    /**
     * Push saved values into Laravel config (app name, legal pages, SMTP).
     * Safe to call before the settings table exists.
     */
    public function applyRuntimeConfig(): void
    {
        try {
            Setting::preload();
            config(['app.name' => $this->siteName()]);

            foreach (['company', 'email', 'address', 'jurisdiction'] as $field) {
                if (filled($value = Setting::get('legal.'.$field))) {
                    config(['legal.'.$field => $value]);
                }
            }

            if (filled(Setting::get('site.name')) && ! filled(Setting::get('legal.company'))) {
                config(['legal.company' => $this->siteName()]);
            }

            if ($this->bool('mail.enabled') && filled($this->get('mail.host'))) {
                $encryption = $this->get('mail.encryption');

                config([
                    'mail.default' => 'smtp',
                    'mail.mailers.smtp.host' => $this->get('mail.host'),
                    'mail.mailers.smtp.port' => (int) $this->get('mail.port'),
                    'mail.mailers.smtp.username' => $this->get('mail.username'),
                    'mail.mailers.smtp.password' => $this->get('mail.password'),
                    'mail.mailers.smtp.scheme' => $encryption === 'ssl' ? 'smtps' : null,
                    'mail.from.address' => $this->get('mail.from_address') ?: config('mail.from.address'),
                    'mail.from.name' => $this->get('mail.from_name') ?: $this->siteName(),
                ]);
            }
        } catch (Throwable) {
            // Settings table not migrated yet — keep .env / config defaults.
        }
    }
}
