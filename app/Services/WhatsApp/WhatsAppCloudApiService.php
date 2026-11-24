<?php

namespace App\Services\WhatsApp;

use App\Models\WhatsAppAccount;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;

/**
 * Thin, reusable client for Meta's official WhatsApp Business Cloud API.
 *
 *   $api = WhatsAppCloudApiService::for($account);
 *   $api->sendTextMessage('919876543210', 'Hello');
 *
 * Every method throws WhatsAppApiException on failure.
 * The access token is sent only as a Bearer header — never in URLs or logs.
 */
class WhatsAppCloudApiService
{
    public function __construct(private readonly WhatsAppAccount $account) {}

    public static function for(WhatsAppAccount $account): self
    {
        return new self($account);
    }

    public function account(): WhatsAppAccount
    {
        return $this->account;
    }

    // =====================================================================
    //  Account / credential checks
    // =====================================================================

    /**
     * GET /{phone-number-id}
     *
     * @return array<string, mixed>
     */
    public function getPhoneNumberDetails(): array
    {
        $fields = 'id,display_phone_number,verified_name,quality_rating,code_verification_status,platform_type,throughput,name_status';

        try {
            return $this->get($this->account->phone_number_id, ['fields' => $fields]);
        } catch (WhatsAppApiException $e) {
            // Older API versions may not know every field — retry with the basics.
            if ($e->metaCode === 100 && str_contains(strtolower($e->getMessage()), 'field')) {
                return $this->get($this->account->phone_number_id, ['fields' => 'id,display_phone_number,verified_name,quality_rating']);
            }

            throw $e;
        }
    }

    /**
     * GET /{waba-id}/phone_numbers
     *
     * @return array<int, array<string, mixed>>
     */
    public function getWabaPhoneNumbers(): array
    {
        return $this->get($this->account->waba_id.'/phone_numbers', ['fields' => 'id,display_phone_number,verified_name', 'limit' => 100])['data'] ?? [];
    }

    /**
     * GET /{waba-id}/subscribed_apps — apps that receive this WABA's webhooks.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getSubscribedApps(): array
    {
        return $this->get($this->account->waba_id.'/subscribed_apps')['data'] ?? [];
    }

    /**
     * POST /{waba-id}/subscribed_apps — subscribe the token's app to this WABA's webhooks.
     */
    public function subscribeApp(): bool
    {
        return (bool) ($this->post($this->account->waba_id.'/subscribed_apps')['success'] ?? false);
    }

    /**
     * POST /{phone-number-id}/register — activates the number on Cloud API.
     * The 6-digit PIN becomes the number's two-step verification PIN.
     */
    public function registerPhoneNumber(string $pin): bool
    {
        return (bool) ($this->post($this->account->phone_number_id.'/register', [
            'messaging_product' => 'whatsapp',
            'pin' => $pin,
        ])['success'] ?? false);
    }

    /**
     * Verifies that the token works, the phone number belongs to the WABA,
     * the App Secret matches the token's app (appsecret_proof), and reports
     * whether an app is subscribed to the WABA's webhooks.
     */
    public function validateCredentials(): CredentialCheckResult
    {
        try {
            $phone = $this->getPhoneNumberDetails();
        } catch (WhatsAppApiException $e) {
            return CredentialCheckResult::failed('Phone number check failed: '.$e->userMessage());
        }

        try {
            $numbers = $this->getWabaPhoneNumbers();
        } catch (WhatsAppApiException $e) {
            return CredentialCheckResult::failed('WhatsApp Business Account (WABA) check failed: '.$e->userMessage());
        }

        $belongs = collect($numbers)->contains(fn ($n) => (string) ($n['id'] ?? '') === (string) $this->account->phone_number_id);

        if (! $belongs) {
            return CredentialCheckResult::failed(
                "Phone Number ID {$this->account->phone_number_id} was not found in WABA {$this->account->waba_id}. Check that both IDs come from the same WhatsApp Business Account."
            );
        }

        $warnings = [];
        $status = $phone['code_verification_status'] ?? null;

        if ($status === 'NOT_VERIFIED') {
            $warnings[] = 'Phone number is not verified yet (code_verification_status = NOT_VERIFIED).';
        }

        if (($phone['name_status'] ?? null) && ! in_array($phone['name_status'], ['APPROVED', 'AVAILABLE_WITHOUT_REVIEW'], true)) {
            $warnings[] = "Display name status is {$phone['name_status']}.";
        }

        try {
            $apps = $this->getSubscribedApps();
            $names = collect($apps)->map(fn ($a) => $a['whatsapp_business_api_data']['name'] ?? ($a['name'] ?? null))->filter()->implode(', ');
            $subscribed = count($apps) > 0;
            $subscriptionMessage = $subscribed
                ? 'Subscribed app(s): '.($names ?: count($apps).' app')
                : 'No app is subscribed to this WABA — Meta will not deliver webhooks. Use "Subscribe app".';
        } catch (WhatsAppApiException $e) {
            $subscribed = null;
            $subscriptionMessage = 'Could not read subscribed apps: '.$e->userMessage();
        }

        $label = trim(($phone['display_phone_number'] ?? '').' '.(isset($phone['verified_name']) ? '('.$phone['verified_name'].')' : ''));

        return new CredentialCheckResult(
            ok: true,
            message: 'API access confirmed for '.($label ?: $this->account->phone_number_id).'.',
            phone: $phone,
            subscribed: $subscribed,
            subscriptionMessage: $subscriptionMessage,
            warnings: $warnings,
        );
    }

    // =====================================================================
    //  Sending  —  POST /{phone-number-id}/messages
    // =====================================================================

    /**
     * Low-level send. Adds messaging_product / recipient_type / reply context.
     *
     * @param  array<string, mixed>  $payload  e.g. ['type' => 'text', 'text' => [...]]
     * @return array<string, mixed>  Meta response (contains messages[0].id = wamid)
     */
    public function sendMessage(string $to, array $payload, ?string $replyToWaMessageId = null): array
    {
        $body = array_merge([
            'messaging_product' => 'whatsapp',
            'recipient_type' => 'individual',
            'to' => self::normalizeRecipient($to),
        ], $payload);

        if ($replyToWaMessageId) {
            $body['context'] = ['message_id' => $replyToWaMessageId];
        }

        return $this->post($this->account->phone_number_id.'/messages', $body);
    }

    public function sendTextMessage(string $to, string $text, bool $previewUrl = false, ?string $replyTo = null): array
    {
        return $this->sendMessage($to, [
            'type' => 'text',
            'text' => ['preview_url' => $previewUrl, 'body' => $text],
        ], $replyTo);
    }

    /**
     * @param  array<int, array<string, mixed>>  $components  Meta template components (header/body/button parameters)
     */
    public function sendTemplateMessage(string $to, string $templateName, string $languageCode, array $components = []): array
    {
        $template = [
            'name' => $templateName,
            'language' => ['code' => $languageCode],
        ];

        if ($components !== []) {
            $template['components'] = $components;
        }

        return $this->sendMessage($to, ['type' => 'template', 'template' => $template]);
    }

    /**
     * @param  array{id?: string, link?: string}  $media  Uploaded media id or a public HTTPS link
     */
    public function sendImageMessage(string $to, array $media, ?string $caption = null, ?string $replyTo = null): array
    {
        return $this->sendMessage($to, ['type' => 'image', 'image' => self::mediaObject($media, $caption)], $replyTo);
    }

    public function sendVideoMessage(string $to, array $media, ?string $caption = null, ?string $replyTo = null): array
    {
        return $this->sendMessage($to, ['type' => 'video', 'video' => self::mediaObject($media, $caption)], $replyTo);
    }

    /** $voice = true → shown as a voice message (OGG/Opus mono only). */
    public function sendAudioMessage(string $to, array $media, ?string $replyTo = null, bool $voice = false): array
    {
        return $this->sendMessage($to, ['type' => 'audio', 'audio' => self::mediaObject($media) + ($voice ? ['voice' => true] : [])], $replyTo);
    }

    public function sendDocumentMessage(string $to, array $media, ?string $caption = null, ?string $filename = null, ?string $replyTo = null): array
    {
        $object = self::mediaObject($media, $caption);

        if ($filename) {
            $object['filename'] = $filename;
        }

        return $this->sendMessage($to, ['type' => 'document', 'document' => $object], $replyTo);
    }

    /**
     * Sends a read receipt (blue ticks) for an incoming message.
     */
    public function markMessageAsRead(string $waMessageId): bool
    {
        $response = $this->post($this->account->phone_number_id.'/messages', [
            'messaging_product' => 'whatsapp',
            'status' => 'read',
            'message_id' => $waMessageId,
        ]);

        return (bool) ($response['success'] ?? false);
    }

    // =====================================================================
    //  Media
    // =====================================================================

    /**
     * GET /{media-id} — returns a short-lived download URL + metadata.
     *
     * @return array{url?: string, mime_type?: string, sha256?: string, file_size?: int, id?: string}
     */
    public function retrieveMedia(string $mediaId): array
    {
        return $this->get($mediaId, ['phone_number_id' => $this->account->phone_number_id]);
    }

    /**
     * Downloads media bytes from the URL returned by retrieveMedia() straight to a file.
     * The URL itself also requires the Bearer token.
     */
    public function downloadMediaTo(string $url, string $absolutePath): Response
    {
        try {
            $response = Http::withToken($this->account->access_token)
                ->timeout(max(60, config('whatsapp.http.timeout')))
                ->connectTimeout(config('whatsapp.http.connect_timeout'))
                ->sink($absolutePath)
                ->get($url);
        } catch (ConnectionException $e) {
            throw WhatsAppApiException::connection($e);
        }

        if ($response->failed()) {
            @unlink($absolutePath);

            throw WhatsAppApiException::fromResponse($response);
        }

        return $response;
    }

    /**
     * POST /{phone-number-id}/media — upload a file and get a media id for sending.
     */
    public function uploadMedia(UploadedFile|string $file, string $mimeType, ?string $filename = null): string
    {
        $path = $file instanceof UploadedFile ? $file->getRealPath() : $file;
        $filename ??= $file instanceof UploadedFile ? $file->getClientOriginalName() : basename($path);

        $stream = fopen($path, 'r');

        try {
            $response = $this->client()
                ->timeout(max(120, config('whatsapp.http.timeout')))
                ->attach('file', $stream, $filename, ['Content-Type' => $mimeType])
                ->post($this->account->phone_number_id.'/media', [
                    'messaging_product' => 'whatsapp',
                    'type' => $mimeType,
                ]);
        } catch (ConnectionException $e) {
            throw WhatsAppApiException::connection($e);
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }

        if ($response->failed()) {
            throw WhatsAppApiException::fromResponse($response);
        }

        return (string) $response->json('id');
    }

    // =====================================================================
    //  Templates
    // =====================================================================

    /**
     * GET /{waba-id}/message_templates — all pages.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getTemplates(int $maxPages = 20): array
    {
        $templates = [];
        $after = null;

        for ($page = 0; $page < $maxPages; $page++) {
            $query = [
                'fields' => 'id,name,language,status,category,components,parameter_format,rejected_reason',
                'limit' => 100,
            ];

            if ($after) {
                $query['after'] = $after;
            }

            $response = $this->get($this->account->waba_id.'/message_templates', $query);
            array_push($templates, ...($response['data'] ?? []));

            $after = $response['paging']['cursors']['after'] ?? null;

            if (! $after || empty($response['paging']['next'])) {
                break;
            }
        }

        return $templates;
    }

    /**
     * POST /{waba-id}/message_templates — submit a new template for Meta review.
     *
     * @param  array<string, mixed>  $template  name, language, category, components[, parameter_format]
     * @return array<string, mixed>  {id, status, category}
     */
    public function createTemplate(array $template): array
    {
        return $this->post($this->account->waba_id.'/message_templates', $template);
    }

    /** DELETE /{waba-id}/message_templates?name=…[&hsm_id=…] */
    public function deleteTemplate(string $name, ?string $metaTemplateId = null): bool
    {
        $res = $this->send('delete', $this->account->waba_id.'/message_templates', array_filter(['name' => $name, 'hsm_id' => $metaTemplateId]));

        return (bool) ($res['success'] ?? false);
    }

    // =====================================================================
    //  WhatsApp Manager — health, business profile, PIN, block list, pricing
    // =====================================================================

    /**
     * Number + WABA health. Optional fields are asked for separately so an
     * older Graph version never breaks the whole page.
     *
     * @return array<string, mixed>
     */
    public function getHealth(): array
    {
        $phone = $this->get($this->account->phone_number_id, [
            'fields' => 'id,display_phone_number,verified_name,quality_rating,name_status,code_verification_status,platform_type,throughput',
        ]);

        $phoneExtra = $this->tryGet($this->account->phone_number_id, ['fields' => 'messaging_limit_tier,is_official_business_account,account_mode,health_status']);
        $waba = $this->tryGet($this->account->waba_id, ['fields' => 'id,name,currency,timezone_id,account_review_status,ownership_type']);
        $wabaHealth = $this->tryGet($this->account->waba_id, ['fields' => 'health_status']);

        return ['phone' => $phone + $phoneExtra, 'waba' => $waba + $wabaHealth, 'fetched_at' => now()->toIso8601String()];
    }

    /** @return array<string, mixed> */
    public function getBusinessProfile(): array
    {
        $res = $this->get($this->account->phone_number_id.'/whatsapp_business_profile', [
            'fields' => 'about,address,description,email,profile_picture_url,websites,vertical',
        ]);

        return $res['data'][0] ?? [];
    }

    /** @param  array<string, mixed>  $fields  about, address, description, email, websites[], vertical, profile_picture_handle */
    public function updateBusinessProfile(array $fields): bool
    {
        $res = $this->post($this->account->phone_number_id.'/whatsapp_business_profile', ['messaging_product' => 'whatsapp'] + $fields);

        return (bool) ($res['success'] ?? true);
    }

    /** POST /{phone-number-id} {pin} — set / change the two-step verification PIN. */
    public function setTwoStepPin(string $pin): bool
    {
        $res = $this->post($this->account->phone_number_id, ['pin' => $pin]);

        return (bool) ($res['success'] ?? true);
    }

    /**
     * Block or unblock customers (Block Users API).
     *
     * @param  list<string>  $waIds
     * @return array{added: list<string>, failed: list<array{user: string, error: string}>}
     */
    public function blockUsers(array $waIds, bool $block = true): array
    {
        $res = $this->send($block ? 'post' : 'delete', $this->account->phone_number_id.'/block_users', [
            'messaging_product' => 'whatsapp',
            'block_users' => array_map(fn ($id) => ['user' => self::normalizeRecipient($id)], $waIds),
        ]);

        $key = $block ? 'added_users' : 'removed_users';
        $ok = array_map(fn ($u) => (string) ($u['wa_id'] ?? $u['input'] ?? ''), $res['block_users'][$key] ?? []);
        $failed = array_map(fn ($u) => [
            'user' => (string) ($u['wa_id'] ?? $u['input'] ?? ''),
            'error' => (string) ($u['errors'][0]['error_data']['details'] ?? $u['errors'][0]['message'] ?? 'Meta refused the request.'),
        ], $res['block_users']['failed_users'] ?? []);

        return ['added' => array_values(array_filter($ok)), 'failed' => $failed];
    }

    /**
     * Meta's own cost figures (per-message pricing analytics) for a period.
     *
     * @return list<array<string, mixed>>  data points: pricing_category, pricing_type, volume, cost, start, end
     */
    public function getPricingAnalytics(\DateTimeInterface $start, \DateTimeInterface $end): array
    {
        $field = sprintf(
            'pricing_analytics.start(%d).end(%d).granularity(MONTHLY).dimensions(["PRICING_CATEGORY","PRICING_TYPE"])',
            $start->getTimestamp(),
            $end->getTimestamp(),
        );

        $res = $this->get($this->account->waba_id, ['fields' => $field]);

        $points = [];
        foreach ($res['pricing_analytics']['data'] ?? [] as $block) {
            foreach ($block['data_points'] ?? [] as $point) {
                $points[] = $point;
            }
        }

        return $points;
    }

    /** Which Meta app the token belongs to (for uploads). */
    public function detectAppId(): ?string
    {
        $res = $this->tryGet('debug_token', ['input_token' => (string) $this->account->access_token]);

        return isset($res['data']['app_id']) ? (string) $res['data']['app_id'] : null;
    }

    /**
     * Resumable Upload API → file handle (profile photo, template media sample).
     */
    public function uploadForHandle(string $appId, string $path, string $mimeType): string
    {
        $token = (string) $this->account->access_token;

        try {
            $session = Http::baseUrl($this->account->graphBaseUrl())->acceptJson()->withToken($token)
                ->timeout(config('whatsapp.http.timeout'))
                ->post($appId.'/uploads?'.http_build_query(['file_name' => basename($path), 'file_length' => filesize($path), 'file_type' => $mimeType]));

            if ($session->failed()) {
                throw WhatsAppApiException::fromResponse($session);
            }

            $upload = Http::baseUrl($this->account->graphBaseUrl())->acceptJson()
                ->withHeaders(['Authorization' => 'OAuth '.$token, 'file_offset' => '0'])
                ->timeout(max(120, config('whatsapp.http.timeout')))
                ->withBody((string) file_get_contents($path), $mimeType)
                ->post((string) $session->json('id'));
        } catch (ConnectionException $e) {
            throw WhatsAppApiException::connection($e);
        }

        if ($upload->failed()) {
            throw WhatsAppApiException::fromResponse($upload);
        }

        return (string) $upload->json('h');
    }

    /**
     * GET that returns [] instead of failing (for optional fields).
     *
     * @return array<string, mixed>
     */
    private function tryGet(string $path, array $query): array
    {
        try {
            return $this->get($path, $query);
        } catch (WhatsAppApiException) {
            return [];
        }
    }

    // =====================================================================
    //  HTTP plumbing
    // =====================================================================

    protected function client(): PendingRequest
    {
        $client = Http::baseUrl($this->account->graphBaseUrl())
            ->withToken((string) $this->account->access_token)
            ->acceptJson()
            ->timeout(config('whatsapp.http.timeout'))
            ->connectTimeout(config('whatsapp.http.connect_timeout'));

        // appsecret_proof proves the App Secret matches the token's app
        // (required when "Require App Secret" is on in the Meta app settings).
        if (config('whatsapp.appsecret_proof') && $this->account->app_secret) {
            $client->withQueryParameters([
                'appsecret_proof' => hash_hmac('sha256', (string) $this->account->access_token, (string) $this->account->app_secret),
            ]);
        }

        return $client;
    }

    /**
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>
     */
    protected function get(string $path, array $query = []): array
    {
        return $this->send('get', $path, $query);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function post(string $path, array $data = []): array
    {
        return $this->send('post', $path, $data);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function send(string $method, string $path, array $data): array
    {
        try {
            /** @var Response $response */
            $response = $this->client()->{$method}(ltrim($path, '/'), $data);
        } catch (ConnectionException $e) {
            throw WhatsAppApiException::connection($e);
        }

        if ($response->failed()) {
            throw WhatsAppApiException::fromResponse($response);
        }

        return $response->json() ?? [];
    }

    /**
     * @param  array{id?: string, link?: string}  $media
     * @return array<string, string>
     */
    private static function mediaObject(array $media, ?string $caption = null): array
    {
        $object = isset($media['id']) ? ['id' => (string) $media['id']] : ['link' => (string) ($media['link'] ?? '')];

        if ($caption !== null && $caption !== '') {
            $object['caption'] = $caption;
        }

        return $object;
    }

    public static function normalizeRecipient(string $to): string
    {
        return (string) preg_replace('/\D+/', '', $to);
    }
}
