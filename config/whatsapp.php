<?php

/*
|--------------------------------------------------------------------------
| WhatsApp Business Cloud API (official Meta Graph API only)
|--------------------------------------------------------------------------
| Per-number credentials are stored encrypted in the `whatsapp_accounts`
| table — never here and never in .env.
*/

return [

    'graph_url' => env('WHATSAPP_GRAPH_URL', 'https://graph.facebook.com'),

    // Used for new accounts; each account can override it.
    'default_graph_version' => env('WHATSAPP_GRAPH_VERSION', 'v26.0'),

    /*
    | Your Meta app as a Tech Provider — WhatsApp Embedded Signup + shared webhook.
    | Normally entered on Settings → Meta Tech Provider (encrypted in the DB);
    | these .env values are only a fallback.
    */
    'platform' => [
        'app_id' => env('META_APP_ID'),
        'app_secret' => env('META_APP_SECRET'),
        'es_config_id' => env('META_ES_CONFIG_ID'),
        'webhook_verify_token' => env('META_WEBHOOK_VERIFY_TOKEN'),
    ],

    // Send appsecret_proof (HMAC of token with App Secret) on every Graph API call.
    // Confirms the saved App Secret belongs to the token's app.
    'appsecret_proof' => (bool) env('WHATSAPP_APPSECRET_PROOF', true),

    'http' => [
        'timeout' => (int) env('WHATSAPP_HTTP_TIMEOUT', 30),
        'connect_timeout' => 10,
    ],

    'queue' => env('WHATSAPP_QUEUE', 'default'),

    'webhook' => [
        // Requests per minute per account accepted by the webhook endpoint.
        'rate_limit' => (int) env('WHATSAPP_WEBHOOK_RATE_LIMIT', 1200),
        // Keep processed raw webhook payloads this many days (0 = forever).
        'retention_days' => (int) env('WHATSAPP_WEBHOOK_RETENTION_DAYS', 30),
    ],

    // Outgoing messages per minute per user (dashboard sending).
    'send_rate_limit' => (int) env('WHATSAPP_SEND_RATE_LIMIT', 60),

    'media' => [
        'disk' => env('WHATSAPP_MEDIA_DISK', 'local'), // private disk — never public
        'directory' => 'whatsapp-media',

        // Limits from Meta's "Supported media types" documentation (bytes).
        'max_size' => [
            'image' => 5 * 1024 * 1024,
            'video' => 16 * 1024 * 1024,
            'audio' => 16 * 1024 * 1024,
            'document' => 100 * 1024 * 1024,
            'sticker' => 500 * 1024,
        ],

        'mimes' => [
            'image' => ['image/jpeg', 'image/png'],
            'video' => ['video/mp4', 'video/3gpp'],
            'audio' => ['audio/aac', 'audio/amr', 'audio/mpeg', 'audio/mp4', 'audio/ogg'],
            'document' => [
                'text/plain',
                'application/pdf',
                'application/msword',
                'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                'application/vnd.ms-excel',
                'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'application/vnd.ms-powerpoint',
                'application/vnd.openxmlformats-officedocument.presentationml.presentation',
            ],
        ],

        // File extensions accepted by the upload form (checked together with MIME).
        'extensions' => [
            'image' => ['jpg', 'jpeg', 'png'],
            'video' => ['mp4', '3gp'],
            'audio' => ['aac', 'amr', 'mp3', 'm4a', 'ogg', 'opus'],
            'document' => ['txt', 'pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx'],
        ],
    ],
];
