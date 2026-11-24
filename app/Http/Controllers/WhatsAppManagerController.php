<?php

namespace App\Http\Controllers;

use App\Models\Contact;
use App\Models\Message;
use App\Models\MessageTemplate;
use App\Models\Setting;
use App\Models\WhatsAppAccount;
use App\Services\ActivityLogger;
use App\Services\WhatsApp\WhatsAppApiException;
use App\Services\WhatsApp\WhatsAppCloudApiService;
use App\Support\MetaPlatform;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\View\View;

/**
 * WhatsApp Manager for one number: health, business profile, two-step PIN,
 * blocked customers and a Meta charges estimate.
 */
class WhatsAppManagerController extends Controller
{
    public const TABS = ['health' => 'Number health', 'profile' => 'Business profile', 'security' => 'Two-step PIN', 'blocked' => 'Blocked customers', 'charges' => 'Meta charges'];

    public const VERTICALS = [
        'OTHER' => 'Other', 'AUTO' => 'Automotive', 'BEAUTY' => 'Beauty, spa & salon', 'APPAREL' => 'Clothing & apparel', 'EDU' => 'Education',
        'ENTERTAIN' => 'Entertainment', 'EVENT_PLAN' => 'Event planning', 'FINANCE' => 'Finance & banking', 'GROCERY' => 'Food & grocery',
        'GOVT' => 'Public service', 'HOTEL' => 'Hotel & lodging', 'HEALTH' => 'Medical & health', 'NONPROFIT' => 'Non-profit',
        'PROF_SERVICES' => 'Professional services', 'RETAIL' => 'Shopping & retail', 'TRAVEL' => 'Travel & transportation', 'RESTAURANT' => 'Restaurant',
    ];

    public const CATEGORIES = ['marketing' => 'Marketing', 'utility' => 'Utility', 'authentication' => 'Authentication'];

    public function __construct(private readonly ActivityLogger $logger) {}

    public function show(Request $request, WhatsAppAccount $account, string $tab = 'health'): View
    {
        $this->authorize('update', $account);
        abort_unless(array_key_exists($tab, self::TABS), 404);

        $data = ['account' => $account, 'tab' => $tab, 'tabs' => self::TABS];

        return view('accounts.manage', $data + match ($tab) {
            'health' => ['health' => $account->phone_meta['health'] ?? null, 'qualityEvent' => $account->phone_meta['quality_event'] ?? null, 'limitEvent' => $account->phone_meta['messaging_limit'] ?? null],
            'profile' => $this->profileData($account),
            'blocked' => ['blocked' => Contact::where('whatsapp_account_id', $account->id)->whereNotNull('blocked_at')->orderByDesc('blocked_at')->paginate(25)],
            'charges' => $this->chargesData($request, $account),
            default => [],
        });
    }

    // ------------------------------------------------------------------ health

    public function refreshHealth(WhatsAppAccount $account): RedirectResponse
    {
        $this->authorize('update', $account);

        try {
            $health = WhatsAppCloudApiService::for($account)->getHealth();
        } catch (WhatsAppApiException $e) {
            return back()->with('error', 'Meta: '.$e->getMessage());
        }

        $meta = $account->phone_meta ?? [];
        $meta['health'] = $health;
        $account->forceFill([
            'phone_meta' => $meta,
            'quality_rating' => $health['phone']['quality_rating'] ?? $account->quality_rating,
            'verified_name' => $health['phone']['verified_name'] ?? $account->verified_name,
        ])->save();

        return redirect()->route('accounts.manage', [$account, 'health'])->with('success', 'Number health refreshed from Meta.');
    }

    // ------------------------------------------------------------------ business profile

    /** @return array<string, mixed> */
    private function profileData(WhatsAppAccount $account): array
    {
        try {
            $profile = WhatsAppCloudApiService::for($account)->getBusinessProfile();
            $error = null;
        } catch (WhatsAppApiException $e) {
            $profile = [];
            $error = $e->getMessage();
        }

        return ['profile' => $profile, 'profileError' => $error, 'verticals' => self::VERTICALS, 'appId' => $this->appIdFor($account, detect: false)];
    }

    public function updateProfile(Request $request, WhatsAppAccount $account): RedirectResponse
    {
        $this->authorize('update', $account);

        $data = $request->validate([
            'about' => ['nullable', 'string', 'max:139'],
            'description' => ['nullable', 'string', 'max:512'],
            'address' => ['nullable', 'string', 'max:256'],
            'email' => ['nullable', 'email', 'max:128'],
            'website_1' => ['nullable', 'url', 'starts_with:http://,https://', 'max:256'],
            'website_2' => ['nullable', 'url', 'starts_with:http://,https://', 'max:256'],
            'vertical' => ['nullable', 'in:'.implode(',', array_keys(self::VERTICALS))],
            'photo' => ['nullable', 'image', 'mimes:jpg,jpeg,png', 'max:5120', 'dimensions:min_width=192,min_height=192'],
        ], ['photo.dimensions' => 'Use a square photo of at least 192×192 px (640×640 recommended).']);

        $api = WhatsAppCloudApiService::for($account);
        $fields = [
            'about' => $data['about'] ?? '',
            'description' => $data['description'] ?? '',
            'address' => $data['address'] ?? '',
            'email' => $data['email'] ?? '',
            'websites' => array_values(array_filter([$data['website_1'] ?? null, $data['website_2'] ?? null])),
        ] + (! empty($data['vertical']) ? ['vertical' => $data['vertical']] : []);

        try {
            if ($request->hasFile('photo')) {
                $appId = $this->appIdFor($account);

                if (! $appId) {
                    return back()->withInput()->with('error', 'To change the photo, first enter your Meta App ID below (Meta → App settings → Basic).');
                }

                $file = $request->file('photo');
                $fields['profile_picture_handle'] = $api->uploadForHandle($appId, $file->getRealPath(), $file->getMimeType() ?: 'image/jpeg');
            }

            $api->updateBusinessProfile($fields);
        } catch (WhatsAppApiException $e) {
            return back()->withInput()->with('error', 'Meta: '.$e->getMessage());
        }

        $this->logger->log('account.profile_updated', "Updated the WhatsApp business profile of {$account->name}", $account, ['photo' => $request->hasFile('photo')], $account->id);

        return redirect()->route('accounts.manage', [$account, 'profile'])->with('success', 'Business profile saved on WhatsApp. Customers see it within a few minutes.');
    }

    public function saveAppId(Request $request, WhatsAppAccount $account): RedirectResponse
    {
        $this->authorize('update', $account);
        $data = $request->validate(['meta_app_id' => ['required', 'regex:/^\d{5,25}$/']], ['meta_app_id.regex' => 'The App ID is a number (Meta → App settings → Basic).']);
        $account->forceFill(['meta_app_id' => $data['meta_app_id']])->save();

        return back()->with('success', 'Meta App ID saved.');
    }

    /** Account's own app id → detected from the token → platform (Embedded Signup) app. */
    private function appIdFor(WhatsAppAccount $account, bool $detect = true): ?string
    {
        if ($account->meta_app_id) {
            return $account->meta_app_id;
        }

        if ($account->isEmbeddedSignup() && ($platform = app(MetaPlatform::class)->appId())) {
            return $platform;
        }

        $detected = $detect ? WhatsAppCloudApiService::for($account)->detectAppId() : null;

        if ($detected) {
            $account->forceFill(['meta_app_id' => $detected])->save();
        }

        return $detected;
    }

    // ------------------------------------------------------------------ two-step PIN

    public function updatePin(Request $request, WhatsAppAccount $account): RedirectResponse
    {
        $this->authorize('update', $account);

        $data = $request->validate([
            'pin' => ['required', 'digits:6', 'confirmed', 'not_in:000000,111111,123456,654321'],
        ], ['pin.digits' => 'The PIN must be exactly 6 digits.', 'pin.not_in' => 'Choose a less obvious PIN.']);

        try {
            WhatsAppCloudApiService::for($account)->setTwoStepPin($data['pin']);
        } catch (WhatsAppApiException $e) {
            return back()->with('error', 'Meta: '.$e->getMessage());
        }

        $this->logger->log('account.pin_changed', "Changed the two-step verification PIN of {$account->name}", $account, accountId: $account->id);

        return redirect()->route('accounts.manage', [$account, 'security'])->with('success', 'Two-step verification PIN changed. Keep it safe — Meta asks for it when the number is registered again.');
    }

    // ------------------------------------------------------------------ Meta charges

    /** @return array<string, mixed> */
    private function chargesData(Request $request, WhatsAppAccount $account): array
    {
        $month = preg_match('/^\d{4}-\d{2}$/', (string) $request->query('month')) ? (string) $request->query('month') : now()->format('Y-m');
        $start = Carbon::createFromFormat('Y-m-d', $month.'-01')->startOfMonth();
        $end = $start->copy()->endOfMonth();

        // Our own count of business messages sent this month, by template category.
        $counts = array_fill_keys(array_keys(self::CATEGORIES), 0) + ['service' => 0, 'failed' => 0];
        $templateCategories = MessageTemplate::where('whatsapp_account_id', $account->id)->pluck('category', 'id')->map(fn ($c) => strtolower((string) $c));

        Message::where('whatsapp_account_id', $account->id)
            ->where('direction', Message::OUT)
            ->whereBetween('created_at', [$start, $end])
            ->select(['id', 'type', 'status', 'content'])
            ->chunkById(1000, function ($messages) use (&$counts, $templateCategories) {
                foreach ($messages as $m) {
                    if ($m->status === Message::STATUS_FAILED) {
                        $counts['failed']++;
                    } elseif ($m->type === 'template') {
                        $cat = strtolower((string) ($m->content['category'] ?? $templateCategories[$m->content['template_id'] ?? 0] ?? 'marketing'));
                        $counts[array_key_exists($cat, self::CATEGORIES) ? $cat : 'marketing']++;
                    } else {
                        $counts['service']++;
                    }
                }
            });

        $rates = $this->rates($account);
        $estimate = 0.0;
        foreach (array_keys(self::CATEGORIES) as $cat) {
            $estimate += $counts[$cat] * (float) ($rates[$cat] ?? 0);
        }

        // Meta's own figures (when available for this account / Graph version).
        $metaPoints = null;
        $metaError = null;

        if ($request->boolean('meta')) {
            try {
                $metaPoints = WhatsAppCloudApiService::for($account)->getPricingAnalytics($start, $end->copy()->min(now()));
            } catch (WhatsAppApiException $e) {
                $metaError = $e->getMessage();
            }
        }

        return compact('month', 'start', 'counts', 'rates', 'estimate', 'metaPoints', 'metaError') + ['categories' => self::CATEGORIES];
    }

    public function saveRates(Request $request, WhatsAppAccount $account): RedirectResponse
    {
        $this->authorize('update', $account);

        $data = $request->validate([
            'currency' => ['required', 'string', 'size:3'],
            'marketing' => ['nullable', 'numeric', 'min:0', 'max:1000'],
            'utility' => ['nullable', 'numeric', 'min:0', 'max:1000'],
            'authentication' => ['nullable', 'numeric', 'min:0', 'max:1000'],
        ]);

        Setting::put('meta_rates.'.$account->id, json_encode(['currency' => strtoupper($data['currency'])] + array_map(fn ($v) => (float) ($v ?? 0), array_intersect_key($data, self::CATEGORIES))));

        return back()->with('success', 'Rates saved — the estimate uses them now.');
    }

    /** @return array{currency: string, marketing: float, utility: float, authentication: float} */
    private function rates(WhatsAppAccount $account): array
    {
        $saved = json_decode((string) Setting::get('meta_rates.'.$account->id), true);

        return (is_array($saved) ? $saved : []) + ['currency' => 'USD', 'marketing' => 0.0, 'utility' => 0.0, 'authentication' => 0.0];
    }
}
