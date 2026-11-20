<?php

namespace App\Http\Controllers;

use App\Services\ActivityLogger;
use App\Support\PlatformSettings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Throwable;

/**
 * Settings center (Super Admin): General · Appearance · Payments · Email · Legal.
 * Meta Tech Provider lives on its own tab (MetaSettingsController).
 */
class SettingsController extends Controller
{
    public const SECTIONS = ['general', 'appearance', 'payments', 'email', 'legal'];

    public function __construct(
        private readonly PlatformSettings $settings,
        private readonly ActivityLogger $logger,
    ) {}

    public function edit(string $section = 'general'): View
    {
        abort_unless(in_array($section, self::SECTIONS, true), 404);

        return view('settings.'.$section, [
            'section' => $section,
            's' => $this->settings,
            'masked' => fn (string $key) => \App\Models\WhatsAppAccount::mask((string) $this->settings->get($key)),
        ]);
    }

    public function update(Request $request, string $section): RedirectResponse
    {
        abort_unless(in_array($section, self::SECTIONS, true), 404);

        $values = match ($section) {
            'general' => $this->general($request),
            'appearance' => $this->appearance($request),
            'payments' => $this->payments($request),
            'email' => $this->email($request),
            'legal' => $this->legal($request),
        };

        foreach ($values as $key => $value) {
            // Blank secret = keep the current one.
            if (in_array($key, PlatformSettings::SECRETS, true) && ($value === null || $value === '')) {
                continue;
            }

            $this->settings->put($key, $value === null ? null : (string) $value);
        }

        $this->logger->log('settings.updated', "Updated {$section} settings", properties: [
            'keys' => array_values(array_diff(array_keys($values), PlatformSettings::SECRETS)),
        ]);

        return redirect()->route('settings.edit', $section)->with('success', ucfirst($section).' settings saved.');
    }

    public function testEmail(Request $request): RedirectResponse
    {
        try {
            Mail::raw('This is a test email from '.$this->settings->siteName().'. Your SMTP settings work.', function ($m) use ($request) {
                $m->to($request->user()->email)->subject('Test email · '.$this->settings->siteName());
            });
        } catch (Throwable $e) {
            return back()->with('error', 'Sending failed: '.Str::limit($e->getMessage(), 200));
        }

        return back()->with('success', 'Test email sent to '.$request->user()->email.'.');
    }

    // ------------------------------------------------------------------ sections

    /** @return array<string, mixed> */
    private function general(Request $request): array
    {
        $data = $request->validate([
            'site_name' => ['required', 'string', 'max:60'],
            'site_tagline' => ['nullable', 'string', 'max:80'],
            'support_email' => ['nullable', 'email', 'max:190'],
            'support_phone' => ['nullable', 'string', 'max:40'],
            'logo' => ['nullable', 'file', 'mimes:png,jpg,jpeg,webp', 'max:1024'],
            'remove_logo' => ['nullable', 'boolean'],
            'registration_enabled' => ['nullable', 'boolean'],
        ]);

        $values = [
            'site.name' => $data['site_name'],
            'site.tagline' => $data['site_tagline'] ?? '',
            'site.support_email' => $data['support_email'] ?? null,
            'site.support_phone' => $data['support_phone'] ?? null,
            'platform.registration_enabled' => $request->boolean('registration_enabled') ? '1' : '0',
        ];

        if ($request->boolean('remove_logo')) {
            $this->deleteLogo();
            $values['site.logo'] = null;
        } elseif ($request->hasFile('logo')) {
            $this->deleteLogo();
            $dir = public_path('uploads/branding');
            File::ensureDirectoryExists($dir);
            $name = 'logo-'.time().'.'.strtolower($request->file('logo')->getClientOriginalExtension());
            $request->file('logo')->move($dir, $name);
            $values['site.logo'] = 'uploads/branding/'.$name;
        }

        return $values;
    }

    private function deleteLogo(): void
    {
        $old = $this->settings->get('site.logo');

        if ($old && str_starts_with($old, 'uploads/branding/') && is_file(public_path($old))) {
            @unlink(public_path($old));
        }
    }

    /** @return array<string, mixed> */
    private function appearance(Request $request): array
    {
        $data = $request->validate([
            'brand_color' => ['required', Rule::in(array_keys(PlatformSettings::PALETTES))],
            'default_theme' => ['required', Rule::in(['system', 'light', 'dark'])],
            'allow_toggle' => ['nullable', 'boolean'],
        ]);

        return [
            'theme.brand_color' => $data['brand_color'],
            'theme.default' => $data['default_theme'],
            'theme.allow_toggle' => $request->boolean('allow_toggle') ? '1' : '0',
        ];
    }

    /** @return array<string, mixed> */
    private function payments(Request $request): array
    {
        $data = $request->validate([
            'currency' => ['required', Rule::in(array_keys(PlatformSettings::CURRENCIES))],
            'self_purchase' => ['nullable', 'boolean'],
            'pkpay_enabled' => ['nullable', 'boolean'],
            'pkpay_base_url' => ['required', 'url', 'starts_with:https://', 'max:200'],
            'pkpay_merchant_id' => ['nullable', 'string', 'max:100'],
            'pkpay_api_key' => ['nullable', 'string', 'max:200', 'regex:/^pk_[A-Za-z0-9_\-]+$/'],
            'pkpay_api_secret' => ['nullable', 'string', 'max:200', 'regex:/^sk_[A-Za-z0-9_\-]+$/'],
            'manual_enabled' => ['nullable', 'boolean'],
            'manual_instructions' => ['nullable', 'string', 'max:3000'],
        ], [
            'pkpay_api_key.regex' => 'The PKPay API key starts with pk_',
            'pkpay_api_secret.regex' => 'The PKPay API secret starts with sk_',
        ]);

        if ($request->boolean('pkpay_enabled')
            && ! filled($data['pkpay_api_key'] ?? $this->settings->get('billing.pkpay_api_key'))) {
            throw \Illuminate\Validation\ValidationException::withMessages(['pkpay_api_key' => 'Enter the API key and secret to enable PKPay.']);
        }

        return [
            'billing.currency' => $data['currency'],
            'billing.self_purchase' => $request->boolean('self_purchase') ? '1' : '0',
            'billing.pkpay_enabled' => $request->boolean('pkpay_enabled') ? '1' : '0',
            'billing.pkpay_base_url' => rtrim($data['pkpay_base_url'], '/'),
            'billing.pkpay_merchant_id' => $data['pkpay_merchant_id'] ?? null,
            'billing.pkpay_api_key' => $data['pkpay_api_key'] ?? null,
            'billing.pkpay_api_secret' => $data['pkpay_api_secret'] ?? null,
            'billing.manual_enabled' => $request->boolean('manual_enabled') ? '1' : '0',
            'billing.manual_instructions' => $data['manual_instructions'] ?? null,
        ];
    }

    /** @return array<string, mixed> */
    private function email(Request $request): array
    {
        $data = $request->validate([
            'mail_enabled' => ['nullable', 'boolean'],
            'mail_host' => ['nullable', 'required_if:mail_enabled,1', 'string', 'max:190'],
            'mail_port' => ['nullable', 'integer', 'min:1', 'max:65535'],
            'mail_username' => ['nullable', 'string', 'max:190'],
            'mail_password' => ['nullable', 'string', 'max:190'],
            'mail_encryption' => ['nullable', Rule::in(['tls', 'ssl', 'none'])],
            'mail_from_address' => ['nullable', 'required_if:mail_enabled,1', 'email', 'max:190'],
            'mail_from_name' => ['nullable', 'string', 'max:100'],
        ]);

        return [
            'mail.enabled' => $request->boolean('mail_enabled') ? '1' : '0',
            'mail.host' => $data['mail_host'] ?? null,
            'mail.port' => (string) ($data['mail_port'] ?? 587),
            'mail.username' => $data['mail_username'] ?? null,
            'mail.password' => $data['mail_password'] ?? null,
            'mail.encryption' => $data['mail_encryption'] ?? 'tls',
            'mail.from_address' => $data['mail_from_address'] ?? null,
            'mail.from_name' => $data['mail_from_name'] ?? null,
        ];
    }

    /** @return array<string, mixed> */
    private function legal(Request $request): array
    {
        $data = $request->validate([
            'legal_company' => ['nullable', 'string', 'max:120'],
            'legal_email' => ['nullable', 'email', 'max:190'],
            'legal_address' => ['nullable', 'string', 'max:300'],
            'legal_jurisdiction' => ['nullable', 'string', 'max:80'],
        ]);

        return [
            'legal.company' => $data['legal_company'] ?? null,
            'legal.email' => $data['legal_email'] ?? null,
            'legal.address' => $data['legal_address'] ?? null,
            'legal.jurisdiction' => $data['legal_jurisdiction'] ?? null,
        ];
    }
}
