<?php

namespace App\Services\WhatsApp;

use Illuminate\Support\Str;

/**
 * Turns one entry of a webhook "messages" array into our storage format.
 *
 * Returns: type, body (text/caption), content (structured data),
 * media (id/mime/sha256/filename) and context (replied-to wamid).
 */
class IncomingMessageParser
{
    public const SUPPORTED = [
        'text', 'image', 'video', 'audio', 'document', 'sticker',
        'location', 'contacts', 'interactive', 'button', 'reaction', 'order', 'system',
    ];

    /**
     * @param  array<string, mixed>  $msg
     * @return array{type:string, body:?string, content:?array, media:?array, context:?string}
     */
    public static function parse(array $msg): array
    {
        $type = (string) ($msg['type'] ?? 'unsupported');
        $body = null;
        $content = null;
        $media = null;

        switch ($type) {
            case 'text':
                $body = $msg['text']['body'] ?? '';
                break;

            case 'image':
            case 'video':
            case 'sticker':
            case 'document':
            case 'audio':
                $m = $msg[$type] ?? [];
                $media = [
                    'id' => $m['id'] ?? null,
                    'mime_type' => $m['mime_type'] ?? null,
                    'sha256' => $m['sha256'] ?? null,
                    'filename' => isset($m['filename']) ? Str::limit(basename((string) $m['filename']), 200, '') : null,
                ];
                $body = $m['caption'] ?? null;
                $content = array_filter([
                    'filename' => $media['filename'],
                    'voice' => $type === 'audio' ? (bool) ($m['voice'] ?? false) : null,
                    'animated' => $type === 'sticker' ? (bool) ($m['animated'] ?? false) : null,
                ], fn ($v) => $v !== null);
                break;

            case 'location':
                $l = $msg['location'] ?? [];
                $content = [
                    'latitude' => $l['latitude'] ?? null,
                    'longitude' => $l['longitude'] ?? null,
                    'name' => $l['name'] ?? null,
                    'address' => $l['address'] ?? null,
                    'url' => $l['url'] ?? null,
                ];
                $body = trim(($l['name'] ?? '').' '.($l['address'] ?? '')) ?: null;
                break;

            case 'contacts':
                $cards = $msg['contacts'] ?? [];
                $content = ['contacts' => $cards];
                $body = collect($cards)->map(fn ($c) => $c['name']['formatted_name'] ?? null)->filter()->implode(', ') ?: 'Contact card';
                break;

            case 'interactive':
                $i = $msg['interactive'] ?? [];
                $kind = $i['type'] ?? 'unknown';
                $reply = $i[$kind] ?? [];
                $content = ['kind' => $kind, 'reply' => $reply];
                $body = match ($kind) {
                    'button_reply' => $reply['title'] ?? 'Button reply',
                    'list_reply' => trim(($reply['title'] ?? 'List reply').(isset($reply['description']) ? ' — '.$reply['description'] : '')),
                    'nfm_reply' => $reply['body'] ?? 'Form response',
                    default => 'Interactive reply',
                };
                break;

            case 'button':
                // Quick-reply button tapped on a template message.
                $content = ['payload' => $msg['button']['payload'] ?? null];
                $body = $msg['button']['text'] ?? 'Button';
                break;

            case 'reaction':
                $content = [
                    'message_id' => $msg['reaction']['message_id'] ?? null,
                    'emoji' => $msg['reaction']['emoji'] ?? '',
                ];
                $body = ($msg['reaction']['emoji'] ?? '') === '' ? 'Removed reaction' : $msg['reaction']['emoji'];
                break;

            case 'order':
                $content = $msg['order'] ?? [];
                $body = 'Order with '.count($msg['order']['product_items'] ?? []).' item(s)';
                break;

            case 'system':
                $content = $msg['system'] ?? [];
                $body = $msg['system']['body'] ?? 'System message';
                break;

            default:
                $type = 'unsupported';
                $content = ['errors' => $msg['errors'] ?? [], 'original_type' => $msg['type'] ?? null];
                $body = $msg['errors'][0]['title'] ?? 'Unsupported message type';
        }

        return [
            'type' => $type,
            'body' => $body !== null ? Str::limit((string) $body, 4096, '') : null,
            'content' => $content ?: null,
            'media' => ($media['id'] ?? null) ? $media : null,
            'context' => $msg['context']['id'] ?? null,
        ];
    }
}
