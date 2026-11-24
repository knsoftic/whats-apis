<?php

namespace App\Http\Controllers;

use App\Models\MessageTemplate;
use App\Models\WhatsAppAccount;
use App\Services\ActivityLogger;
use App\Services\WhatsApp\WhatsAppApiException;
use App\Services\WhatsApp\WhatsAppCloudApiService;
use App\Support\CurrentAccount;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/** Create a message template and submit it to Meta for review; delete templates. */
class TemplateBuilderController extends Controller
{
    public const LANGUAGES = [
        'en' => 'English', 'en_US' => 'English (US)', 'en_GB' => 'English (UK)', 'ur' => 'Urdu', 'hi' => 'Hindi', 'ar' => 'Arabic',
        'bn' => 'Bengali', 'pa' => 'Punjabi', 'fa' => 'Persian', 'tr' => 'Turkish', 'id' => 'Indonesian', 'ms' => 'Malay',
        'es' => 'Spanish', 'fr' => 'French', 'de' => 'German', 'pt_BR' => 'Portuguese (BR)', 'ru' => 'Russian', 'zh_CN' => 'Chinese (CN)',
    ];

    public function __construct(private readonly ActivityLogger $logger) {}

    public function create(Request $request, CurrentAccount $current): View
    {
        $accounts = $current->accounts();
        $account = $accounts->firstWhere('id', $request->integer('account_id')) ?? $current->getOrFirst();

        return view('templates.create', ['accounts' => $accounts, 'account' => $account, 'languages' => self::LANGUAGES]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'whatsapp_account_id' => ['required', 'integer'],
            'name' => ['required', 'string', 'max:512', 'regex:/^[a-z0-9_]+$/'],
            'language' => ['required', 'in:'.implode(',', array_keys(self::LANGUAGES))],
            'category' => ['required', 'in:MARKETING,UTILITY,AUTHENTICATION'],
            'header_type' => ['nullable', 'in:NONE,TEXT,IMAGE,VIDEO,DOCUMENT'],
            'header_text' => ['nullable', 'string', 'max:60'],
            'header_example' => ['nullable', 'string', 'max:60'],
            'header_file' => ['nullable', 'file', 'max:16384'],
            'body' => ['required_unless:category,AUTHENTICATION', 'nullable', 'string', 'max:1024'],
            'body_examples' => ['nullable', 'array', 'max:20'],
            'body_examples.*' => ['nullable', 'string', 'max:200'],
            'footer' => ['nullable', 'string', 'max:60'],
            'buttons' => ['nullable', 'array', 'max:10'],
            'buttons.*.type' => ['required', 'in:QUICK_REPLY,URL,PHONE_NUMBER'],
            'buttons.*.text' => ['required', 'string', 'max:25'],
            'buttons.*.url' => ['nullable', 'string', 'max:2000'],
            'buttons.*.example' => ['nullable', 'string', 'max:200'],
            'buttons.*.phone' => ['nullable', 'string', 'max:20'],
            'auth_security' => ['nullable', 'boolean'],
            'auth_expiry' => ['nullable', 'integer', 'min:1', 'max:90'],
        ], [
            'name.regex' => 'Use only small letters, numbers and underscores, e.g. order_update.',
            'buttons.*.text.max' => 'Button text can be up to 25 characters.',
        ]);

        /** @var WhatsAppAccount $account */
        $account = $request->user()->accessibleAccounts()->findOrFail($data['whatsapp_account_id']);
        $components = $this->components($data, $request, $account);

        try {
            $res = WhatsAppCloudApiService::for($account)->createTemplate([
                'name' => $data['name'],
                'language' => $data['language'],
                'category' => $data['category'],
                'components' => $components,
            ]);
        } catch (WhatsAppApiException $e) {
            return back()->withInput()->with('error', 'Meta did not accept the template: '.$e->getMessage());
        }

        $template = MessageTemplate::updateOrCreate(
            ['whatsapp_account_id' => $account->id, 'name' => $data['name'], 'language' => $data['language']],
            [
                'meta_template_id' => isset($res['id']) ? (string) $res['id'] : null,
                'category' => strtoupper((string) ($res['category'] ?? $data['category'])),
                'status' => strtoupper((string) ($res['status'] ?? 'PENDING')),
                'parameter_format' => 'POSITIONAL',
                'components' => $components,
                'rejected_reason' => null,
                'synced_at' => now(),
            ],
        );

        $this->logger->log('template.created', "Submitted template {$template->name} ({$template->language}) to Meta", $template, accountId: $account->id);

        return redirect()->route('templates.show', $template)->with('success', $template->status === 'APPROVED'
            ? 'Template approved by Meta — you can use it now.'
            : 'Template submitted. Meta usually reviews it within minutes (sometimes up to 24 hours); the status updates automatically.');
    }

    public function destroy(Request $request, MessageTemplate $template): RedirectResponse
    {
        abort_unless($request->user()->canAccessAccount($template->whatsapp_account_id), 404);

        try {
            WhatsAppCloudApiService::for($template->account)->deleteTemplate($template->name, $template->meta_template_id);
        } catch (WhatsAppApiException $e) {
            if (! str_contains(strtolower($e->getMessage()), 'not found') && ! str_contains(strtolower($e->getMessage()), 'does not exist')) {
                return back()->with('error', 'Meta: '.$e->getMessage());
            }
        }

        $accountId = $template->whatsapp_account_id;
        $name = $template->name;
        $template->delete();
        $this->logger->log('template.deleted', "Deleted template {$name}", accountId: $accountId);

        return redirect()->route('templates.index', ['account_id' => $accountId])->with('success', "Template {$name} deleted. Meta does not allow re-using the same name for 30 days.");
    }

    /**
     * Builds Meta's "components" array (and checks the rules Meta enforces).
     *
     * @param  array<string, mixed>  $data
     * @return list<array<string, mixed>>
     */
    private function components(array $data, Request $request, WhatsAppAccount $account): array
    {
        $fail = fn (string $field, string $message) => throw ValidationException::withMessages([$field => $message]);

        if ($data['category'] === 'AUTHENTICATION') {
            return [
                ['type' => 'BODY', 'add_security_recommendation' => (bool) ($data['auth_security'] ?? true)],
                ['type' => 'FOOTER', 'code_expiration_minutes' => (int) ($data['auth_expiry'] ?? 10)],
                ['type' => 'BUTTONS', 'buttons' => [['type' => 'OTP', 'otp_type' => 'COPY_CODE', 'text' => 'Copy code']]],
            ];
        }

        $components = [];

        // Header
        $headerType = $data['header_type'] ?? 'NONE';

        if ($headerType === 'TEXT') {
            $text = trim((string) ($data['header_text'] ?? ''));
            $vars = self::variables($text);

            if ($text === '') {
                $fail('header_text', 'Enter the header text.');
            }
            if (count($vars) > 1 || ($vars && $vars !== [1])) {
                $fail('header_text', 'The header can have at most one variable: {{1}}.');
            }

            $header = ['type' => 'HEADER', 'format' => 'TEXT', 'text' => $text];
            if ($vars) {
                $example = trim((string) ($data['header_example'] ?? '')) ?: $fail('header_example', 'Give an example for {{1}} in the header.');
                $header['example'] = ['header_text' => [$example]];
            }
            $components[] = $header;
        } elseif (in_array($headerType, ['IMAGE', 'VIDEO', 'DOCUMENT'], true)) {
            $file = $request->file('header_file') ?? $fail('header_file', 'Upload a sample '.strtolower($headerType).' for the header.');
            $allowed = ['IMAGE' => ['image/jpeg', 'image/png'], 'VIDEO' => ['video/mp4'], 'DOCUMENT' => ['application/pdf']][$headerType];
            $mime = (string) $file->getMimeType();

            if (! in_array($mime, $allowed, true)) {
                $fail('header_file', 'Use '.['IMAGE' => 'a JPG or PNG image', 'VIDEO' => 'an MP4 video', 'DOCUMENT' => 'a PDF'][$headerType].'.');
            }

            $appId = $account->meta_app_id ?: WhatsAppCloudApiService::for($account)->detectAppId();
            if (! $appId) {
                $fail('header_file', 'Media headers need your Meta App ID — add it on WhatsApp Manager → Business profile.');
            }

            try {
                $handle = WhatsAppCloudApiService::for($account)->uploadForHandle($appId, $file->getRealPath(), $mime);
            } catch (WhatsAppApiException $e) {
                $fail('header_file', 'Upload to Meta failed: '.$e->getMessage());
            }

            $components[] = ['type' => 'HEADER', 'format' => $headerType, 'example' => ['header_handle' => [$handle]]];
        }

        // Body
        $body = trim((string) $data['body']);
        $vars = self::variables($body);

        if ($vars && $vars !== range(1, count($vars))) {
            $fail('body', 'Number the variables in order: {{1}}, {{2}}, {{3}} …');
        }
        if (preg_match('/^\s*\{\{\d+}}|\{\{\d+}}\s*$/', $body)) {
            $fail('body', 'Meta does not allow a variable at the very start or end of the body — add some text around it.');
        }

        $bodyComponent = ['type' => 'BODY', 'text' => $body];
        if ($vars) {
            $examples = [];
            foreach ($vars as $n) {
                $examples[] = trim((string) ($data['body_examples'][$n] ?? '')) ?: $fail("body_examples.{$n}", "Give an example for {{{$n}}}.");
            }
            $bodyComponent['example'] = ['body_text' => [$examples]];
        }
        $components[] = $bodyComponent;

        // Footer
        if (filled($data['footer'] ?? null)) {
            if (self::variables((string) $data['footer'])) {
                $fail('footer', 'The footer cannot contain variables.');
            }
            $components[] = ['type' => 'FOOTER', 'text' => trim((string) $data['footer'])];
        }

        // Buttons
        $buttons = [];
        $urlCount = 0;
        $phoneCount = 0;

        foreach (array_values($data['buttons'] ?? []) as $i => $b) {
            $n = $i + 1;
            $text = trim((string) $b['text']);

            if ($b['type'] === 'QUICK_REPLY') {
                $buttons[] = ['type' => 'QUICK_REPLY', 'text' => $text];
            } elseif ($b['type'] === 'URL') {
                $url = trim((string) ($b['url'] ?? ''));
                if (! preg_match('#^https?://#i', $url) || ! filter_var(str_replace('{{1}}', 'x', $url), FILTER_VALIDATE_URL)) {
                    $fail("buttons.{$i}.url", "Button {$n}: enter a full link starting with https://");
                }
                $button = ['type' => 'URL', 'text' => $text, 'url' => $url];
                if (str_contains($url, '{{')) {
                    if (! str_ends_with($url, '{{1}}')) {
                        $fail("buttons.{$i}.url", "Button {$n}: a link variable must be {{1}} at the very end.");
                    }
                    $example = trim((string) ($b['example'] ?? '')) ?: $fail("buttons.{$i}.example", "Button {$n}: give a full example link.");
                    $button['example'] = [$example];
                }
                $buttons[] = $button;
                $urlCount++;
            } else {
                $phone = '+'.preg_replace('/\D/', '', (string) ($b['phone'] ?? ''));
                if (strlen($phone) < 8) {
                    $fail("buttons.{$i}.phone", "Button {$n}: enter the phone number with country code.");
                }
                $buttons[] = ['type' => 'PHONE_NUMBER', 'text' => $text, 'phone_number' => $phone];
                $phoneCount++;
            }
        }

        if ($urlCount > 2 || $phoneCount > 1) {
            $fail('buttons', 'Meta allows at most 2 link buttons and 1 call button.');
        }

        if ($buttons) {
            $components[] = ['type' => 'BUTTONS', 'buttons' => $buttons];
        }

        return $components;
    }

    /** @return list<int> distinct {{n}} numbers in order of first appearance, sorted */
    private static function variables(string $text): array
    {
        preg_match_all('/\{\{(\d+)}}/', $text, $m);
        $vars = array_values(array_unique(array_map('intval', $m[1])));
        sort($vars);

        return $vars;
    }
}
