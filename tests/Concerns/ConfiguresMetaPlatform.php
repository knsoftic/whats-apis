<?php

namespace Tests\Concerns;

use App\Models\Setting;
use App\Support\MetaPlatform;

trait ConfiguresMetaPlatform
{
    protected string $platformSecret = 'platformsecret0123456789abcdef00';

    protected function configurePlatform(bool $webhookVerified = false): void
    {
        Setting::flushCache();
        Setting::put(MetaPlatform::APP_ID, '111222333444');
        Setting::put(MetaPlatform::APP_SECRET, $this->platformSecret);
        Setting::put(MetaPlatform::CONFIG_ID, '555666777888');
        Setting::put(MetaPlatform::VERIFY_TOKEN, 'platform-verify-token');
        Setting::put(MetaPlatform::GRAPH_VERSION, 'v26.0');

        if ($webhookVerified) {
            Setting::put(MetaPlatform::WEBHOOK_VERIFIED_AT, now()->toIso8601String());
        }
    }

    protected function tearDown(): void
    {
        Setting::flushCache();
        parent::tearDown();
    }
}
