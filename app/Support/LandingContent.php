<?php

namespace App\Support;

use App\Models\Setting;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;

/**
 * Everything on the public landing page — editable on Settings → Landing page.
 * Stored as one JSON document (setting "landing.content"), merged over defaults.
 */
class LandingContent
{
    public const KEY = 'landing.content';

    public const ICONS = ['chat', 'phone', 'users', 'contacts', 'template', 'shield', 'bolt', 'chart', 'clock', 'link', 'lock', 'signal', 'send', 'inbox', 'tag', 'key', 'photo', 'map-pin', 'check', 'activity', 'user', 'envelope', 'refresh', 'download', 'upload'];

    public const MEDIA_TYPES = ['none', 'image', 'video', 'youtube'];

    /**
     * @return array<string, mixed>
     */
    public static function defaults(): array
    {
        return [
            'seo' => [
                'title' => '',
                'description' => 'Manage your WhatsApp Business numbers in one shared inbox — real-time chat, templates, bots and contacts, built on Meta\'s WhatsApp Business Platform (Cloud API).',
                'og_image' => '',
            ],
            'hero' => [
                'enabled' => true,
                'badge' => 'Built on the WhatsApp Business Platform',
                'title' => 'All your WhatsApp Business numbers in one smart inbox',
                'subtitle' => 'Connect with Meta in one click, chat with customers in real time, automate replies with bots and send approved templates — without QR codes or unofficial tools.',
                'primary_text' => 'Start free',
                'secondary_text' => 'See how it works',
                'media_type' => 'image',
                'image' => '',
                'video' => '',
                'youtube' => '',
            ],
            'stats' => [
                'enabled' => true,
                'items' => [
                    ['value' => '0', 'label' => 'QR codes or unofficial tools'],
                    ['value' => '< 2 s', 'label' => 'Real-time delivery'],
                    ['value' => '24/7', 'label' => 'Bots that never sleep'],
                    ['value' => 'Multi', 'label' => 'Numbers & team members'],
                ],
            ],
            'features' => [
                'enabled' => true,
                'title' => 'Everything your team needs on WhatsApp',
                'subtitle' => 'Built on Meta\'s WhatsApp Business Platform (Cloud API) — secure, reliable and ready to scale.',
                'items' => [
                    ['icon' => 'link', 'title' => 'Connect with Meta', 'text' => 'Embedded Signup connects your number in minutes. No tokens to copy.'],
                    ['icon' => 'chat', 'title' => 'Shared live inbox', 'text' => 'WhatsApp Web-style chat with delivery ticks, media, replies and search.'],
                    ['icon' => 'bolt', 'title' => 'Bot flows', 'text' => 'Keyword replies, welcome menus with buttons and handoff to your agents.'],
                    ['icon' => 'template', 'title' => 'Approved templates', 'text' => 'Sync templates from Meta and message customers outside the 24-hour window.'],
                    ['icon' => 'contacts', 'title' => 'Contacts & tags', 'text' => 'Import, tag and segment customers with consent tracking built in.'],
                    ['icon' => 'shield', 'title' => 'Secure by design', 'text' => 'Encrypted credentials, signed webhooks, roles and per-number access.'],
                ],
            ],
            'steps' => [
                'enabled' => true,
                'title' => 'Go live in three steps',
                'items' => [
                    ['title' => 'Create your account', 'text' => 'Sign up in seconds and pick a plan that fits your business.'],
                    ['title' => 'Connect your number', 'text' => 'Log in with Facebook and verify your WhatsApp Business number.'],
                    ['title' => 'Start chatting', 'text' => 'Reply to customers, send templates and switch on your bots.'],
                ],
            ],
            'video' => [
                'enabled' => false,
                'title' => 'See it in action',
                'subtitle' => 'A two-minute tour of the inbox, bots and templates.',
                'youtube' => '',
                'file' => '',
            ],
            'pricing' => [
                'enabled' => true,
                'title' => 'Simple, transparent pricing',
                'subtitle' => 'Start free. Upgrade when you grow. WhatsApp conversation charges are billed by Meta.',
            ],
            'testimonials' => [
                'enabled' => true,
                'title' => 'Loved by growing businesses',
                'items' => [
                    ['name' => 'Ayesha K.', 'role' => 'Online store owner', 'text' => 'Our team answers twice as fast and nobody misses a customer message anymore.'],
                    ['name' => 'Bilal R.', 'role' => 'Clinic manager', 'text' => 'The welcome menu books appointments automatically. Setup took ten minutes.'],
                    ['name' => 'Sara M.', 'role' => 'Travel agency', 'text' => 'Templates and contacts in one place — exactly what we needed.'],
                ],
            ],
            'faq' => [
                'enabled' => true,
                'title' => 'Frequently asked questions',
                'items' => [
                    ['q' => 'Which WhatsApp API do you use?', 'a' => 'Meta\'s WhatsApp Business Platform (Cloud API) — no QR codes, no WhatsApp Web automation. We are an independent software company, not WhatsApp or Meta.'],
                    ['q' => 'Do I need a Facebook account?', 'a' => 'Yes, a Facebook / Meta Business account is used to connect and verify your WhatsApp Business number.'],
                    ['q' => 'Who pays for WhatsApp messages?', 'a' => 'Meta charges conversation fees directly to the payment method in your WhatsApp Manager. Our plans cover the software.'],
                    ['q' => 'Can I use my existing number?', 'a' => 'Yes, as long as it can receive an SMS or call for verification. Numbers on the WhatsApp Business app can also be connected.'],
                ],
            ],
            'contact' => [
                'enabled' => true,
                'title' => 'Talk to us',
                'subtitle' => 'Questions about plans or setup? Send us a message — we usually reply within a few hours.',
                'email' => '',
                'phone' => '',
                'whatsapp' => '',
                'address' => '',
                'show_form' => true,
            ],
            'cta' => [
                'enabled' => true,
                'title' => 'Ready to grow on WhatsApp?',
                'subtitle' => 'Create your account and connect your first number today.',
                'button' => 'Create free account',
            ],
            'footer' => [
                'text' => '',
            ],
        ];
    }

    /**
     * Saved content merged over the defaults (so new sections appear automatically).
     *
     * @return array<string, mixed>
     */
    public static function get(): array
    {
        $saved = json_decode((string) Setting::get(self::KEY), true);
        $content = self::defaults();

        if (! is_array($saved)) {
            return $content;
        }

        foreach ($content as $section => $values) {
            if (! isset($saved[$section]) || ! is_array($saved[$section])) {
                continue;
            }

            foreach ($values as $field => $default) {
                if (array_key_exists($field, $saved[$section])) {
                    $content[$section][$field] = $saved[$section][$field];
                }
            }
        }

        return $content;
    }

    /**
     * Clean editor input: only known sections/fields, sane lengths, safe URLs.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public static function sanitize(array $input): array
    {
        $defaults = self::defaults();
        $clean = [];

        $text = fn ($v, int $max) => Str::limit(trim(strip_tags((string) $v)), $max, '');
        $bool = fn ($v) => filter_var($v, FILTER_VALIDATE_BOOLEAN);
        $url = function ($v) {
            $v = trim((string) $v);

            if ($v === '') {
                return '';
            }

            // Local uploads (uploads/landing/...) or absolute http(s) URLs only.
            if (preg_match('#^uploads/landing/[A-Za-z0-9._\-]+$#', $v)) {
                return $v;
            }

            return filter_var($v, FILTER_VALIDATE_URL) && preg_match('#^https?://#i', $v) ? Str::limit($v, 500, '') : '';
        };
        $items = function ($list, array $fields, int $max) use ($text) {
            $out = [];

            foreach (array_slice(is_array($list) ? $list : [], 0, $max) as $item) {
                if (! is_array($item)) {
                    continue;
                }

                $row = [];

                foreach ($fields as $field => $limit) {
                    $row[$field] = $field === 'icon'
                        ? (in_array($item['icon'] ?? '', self::ICONS, true) ? $item['icon'] : 'check')
                        : $text($item[$field] ?? '', $limit);
                }

                if (implode('', array_diff_key($row, ['icon' => 1])) !== '') {
                    $out[] = $row;
                }
            }

            return $out;
        };

        $s = fn (string $section) => is_array($input[$section] ?? null) ? $input[$section] : [];

        $clean['seo'] = [
            'title' => $text($s('seo')['title'] ?? '', 70),
            'description' => $text($s('seo')['description'] ?? '', 200),
            'og_image' => $url($s('seo')['og_image'] ?? ''),
        ];

        $hero = $s('hero');
        $clean['hero'] = [
            'enabled' => $bool($hero['enabled'] ?? true),
            'badge' => $text($hero['badge'] ?? '', 60),
            'title' => $text($hero['title'] ?? $defaults['hero']['title'], 120),
            'subtitle' => $text($hero['subtitle'] ?? '', 400),
            'primary_text' => $text($hero['primary_text'] ?? '', 30),
            'secondary_text' => $text($hero['secondary_text'] ?? '', 30),
            'media_type' => in_array($hero['media_type'] ?? 'image', self::MEDIA_TYPES, true) ? $hero['media_type'] : 'image',
            'image' => $url($hero['image'] ?? ''),
            'video' => $url($hero['video'] ?? ''),
            'youtube' => self::youtubeId((string) ($hero['youtube'] ?? '')) ? trim((string) $hero['youtube']) : '',
        ];

        $clean['stats'] = ['enabled' => $bool($s('stats')['enabled'] ?? true), 'items' => $items($s('stats')['items'] ?? [], ['value' => 20, 'label' => 40], 6)];

        $clean['features'] = [
            'enabled' => $bool($s('features')['enabled'] ?? true),
            'title' => $text($s('features')['title'] ?? '', 100),
            'subtitle' => $text($s('features')['subtitle'] ?? '', 300),
            'items' => $items($s('features')['items'] ?? [], ['icon' => 0, 'title' => 60, 'text' => 220], 12),
        ];

        $clean['steps'] = [
            'enabled' => $bool($s('steps')['enabled'] ?? true),
            'title' => $text($s('steps')['title'] ?? '', 100),
            'items' => $items($s('steps')['items'] ?? [], ['title' => 60, 'text' => 220], 6),
        ];

        $video = $s('video');
        $clean['video'] = [
            'enabled' => $bool($video['enabled'] ?? false),
            'title' => $text($video['title'] ?? '', 100),
            'subtitle' => $text($video['subtitle'] ?? '', 300),
            'youtube' => self::youtubeId((string) ($video['youtube'] ?? '')) ? trim((string) $video['youtube']) : '',
            'file' => $url($video['file'] ?? ''),
        ];

        $clean['pricing'] = [
            'enabled' => $bool($s('pricing')['enabled'] ?? true),
            'title' => $text($s('pricing')['title'] ?? '', 100),
            'subtitle' => $text($s('pricing')['subtitle'] ?? '', 300),
        ];

        $clean['testimonials'] = [
            'enabled' => $bool($s('testimonials')['enabled'] ?? true),
            'title' => $text($s('testimonials')['title'] ?? '', 100),
            'items' => $items($s('testimonials')['items'] ?? [], ['name' => 60, 'role' => 60, 'text' => 400], 9),
        ];

        $clean['faq'] = [
            'enabled' => $bool($s('faq')['enabled'] ?? true),
            'title' => $text($s('faq')['title'] ?? '', 100),
            'items' => $items($s('faq')['items'] ?? [], ['q' => 200, 'a' => 1000], 20),
        ];

        $contact = $s('contact');
        $clean['contact'] = [
            'enabled' => $bool($contact['enabled'] ?? true),
            'title' => $text($contact['title'] ?? '', 100),
            'subtitle' => $text($contact['subtitle'] ?? '', 300),
            'email' => filter_var(trim((string) ($contact['email'] ?? '')), FILTER_VALIDATE_EMAIL) ?: '',
            'phone' => $text($contact['phone'] ?? '', 40),
            'whatsapp' => preg_replace('/[^0-9+]/', '', (string) ($contact['whatsapp'] ?? '')),
            'address' => $text($contact['address'] ?? '', 300),
            'show_form' => $bool($contact['show_form'] ?? true),
        ];

        $clean['cta'] = [
            'enabled' => $bool($s('cta')['enabled'] ?? true),
            'title' => $text($s('cta')['title'] ?? '', 100),
            'subtitle' => $text($s('cta')['subtitle'] ?? '', 300),
            'button' => $text($s('cta')['button'] ?? '', 30),
        ];

        $clean['footer'] = ['text' => $text($s('footer')['text'] ?? '', 300)];

        return $clean;
    }

    public static function save(array $content): void
    {
        Setting::put(self::KEY, json_encode($content, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    public static function youtubeId(string $url): ?string
    {
        if (preg_match('~(?:youtube\.com/(?:watch\?v=|embed/|shorts/)|youtu\.be/)([A-Za-z0-9_-]{11})~', $url, $m)) {
            return $m[1];
        }

        return null;
    }

    /** Public URL for a stored value (local upload path or absolute URL). */
    public static function mediaUrl(?string $value): ?string
    {
        if (! $value) {
            return null;
        }

        return str_starts_with($value, 'uploads/') ? asset($value) : $value;
    }

    /**
     * @return array<string, mixed>
     */
    public static function section(string $name): array
    {
        return Arr::get(self::get(), $name, []);
    }
}
