<?php

namespace App\Support;

use App\Models\Setting;
use Illuminate\Support\Carbon;

/**
 * Your own Meta app as a Tech Provider (used for WhatsApp Embedded Signup
 * and the shared platform webhook). Values come from the Settings page
 * (encrypted in the database) and fall back to .env.
 */
class MetaPlatform
{
    public const APP_ID = 'meta.app_id';
    public const APP_SECRET = 'meta.app_secret';
    public const CONFIG_ID = 'meta.es_config_id';
    public const VERIFY_TOKEN = 'meta.webhook_verify_token';
    public const GRAPH_VERSION = 'meta.graph_version';
    public const WEBHOOK_VERIFIED_AT = 'meta.webhook_verified_at';

    public function appId(): ?string
    {
        return Setting::get(self::APP_ID, config('whatsapp.platform.app_id'));
    }

    public function appSecret(): ?string
    {
        return Setting::get(self::APP_SECRET, config('whatsapp.platform.app_secret'));
    }

    public function configId(): ?string
    {
        return Setting::get(self::CONFIG_ID, config('whatsapp.platform.es_config_id'));
    }

    public function verifyToken(): ?string
    {
        return Setting::get(self::VERIFY_TOKEN, config('whatsapp.platform.webhook_verify_token'));
    }

    public function graphVersion(): string
    {
        return Setting::get(self::GRAPH_VERSION, config('whatsapp.default_graph_version')) ?? 'v26.0';
    }

    /** Ready to run Embedded Signup. */
    public function isConfigured(): bool
    {
        return filled($this->appId()) && filled($this->appSecret()) && filled($this->configId());
    }

    /** Ready to receive the shared platform webhook. */
    public function webhookConfigured(): bool
    {
        return filled($this->appSecret()) && filled($this->verifyToken());
    }

    public function webhookUrl(): string
    {
        return route('webhooks.whatsapp.platform');
    }

    public function webhookVerifiedAt(): ?Carbon
    {
        $value = Setting::get(self::WEBHOOK_VERIFIED_AT);

        return $value ? Carbon::parse($value) : null;
    }

    public function graphUrl(string $path = ''): string
    {
        return rtrim(config('whatsapp.graph_url'), '/').'/'.$this->graphVersion().'/'.ltrim($path, '/');
    }

    /** App access token "APP_ID|APP_SECRET" (server-side only, for /debug_token). */
    public function appAccessToken(): string
    {
        return $this->appId().'|'.$this->appSecret();
    }
}
