<?php

namespace App\Http\Controllers;

use App\Models\MessageAttachment;
use App\Services\ActivityLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Serves stored WhatsApp media from the PRIVATE disk after an access check.
 * Only images / video / audio are shown inline; everything else is a forced
 * download with a sandbox CSP, so a customer-sent HTML/SVG file can never run.
 */
class MediaController extends Controller
{
    private const INLINE_MIMES = [
        'image/jpeg', 'image/png', 'image/webp', 'image/gif',
        'video/mp4', 'video/3gpp',
        'audio/aac', 'audio/amr', 'audio/mpeg', 'audio/mp4', 'audio/ogg',
    ];

    public function show(Request $request, MessageAttachment $attachment, ActivityLogger $logger): StreamedResponse
    {
        $conversation = $attachment->message?->conversation;

        abort_unless($conversation && $request->user()->can('view', $conversation), 404);
        abort_unless($attachment->isAvailable() && Storage::disk($attachment->disk)->exists($attachment->path), 404);

        $mime = (string) ($attachment->mime_type ?: 'application/octet-stream');
        $inline = ! $request->boolean('download') && in_array($mime, self::INLINE_MIMES, true);
        $name = $this->filename($attachment);

        if (! $inline) {
            $logger->log('media.downloaded', "Downloaded {$attachment->type} {$name}", $attachment->message, accountId: $attachment->message->whatsapp_account_id);
        }

        return Storage::disk($attachment->disk)->response($attachment->path, $name, [
            'Content-Type' => $inline ? $mime : 'application/octet-stream',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; sandbox",
            'Cache-Control' => 'private, max-age=3600',
        ], $inline ? 'inline' : 'attachment');
    }

    private function filename(MessageAttachment $attachment): string
    {
        $extension = pathinfo((string) $attachment->path, PATHINFO_EXTENSION) ?: 'bin';
        $base = $attachment->file_name ? pathinfo($attachment->file_name, PATHINFO_FILENAME) : $attachment->type.'-'.$attachment->id;

        return Str::limit(Str::slug($base, '_') ?: 'file', 100, '').'.'.$extension;
    }
}
