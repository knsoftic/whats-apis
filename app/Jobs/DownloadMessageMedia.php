<?php

namespace App\Jobs;

use App\Events\MessageUpdated;
use App\Models\MessageAttachment;
use App\Services\WhatsApp\WhatsAppApiException;
use App\Services\WhatsApp\WhatsAppCloudApiService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\Mime\MimeTypes;
use Throwable;

/**
 * Securely fetches an incoming media file through Meta's official media
 * endpoints (GET /{media-id} → short-lived URL → authenticated download)
 * and stores it on the PRIVATE disk. Files are served only through an
 * authorised controller, never from a public URL.
 */
class DownloadMessageMedia implements ShouldQueue
{
    use Queueable;

    public int $tries = 4;

    /** @var array<int, int> */
    public array $backoff = [10, 60, 300];

    public int $timeout = 300;

    public function __construct(public readonly int $attachmentId) {}

    public function handle(): void
    {
        $attachment = MessageAttachment::with('message.account')->find($this->attachmentId);

        if (! $attachment || $attachment->isAvailable() || ! $attachment->wa_media_id || ! $attachment->message?->account) {
            return;
        }

        $account = $attachment->message->account;
        $api = WhatsAppCloudApiService::for($account);
        $info = $api->retrieveMedia($attachment->wa_media_id);

        $size = (int) ($info['file_size'] ?? 0);
        $max = (int) config('whatsapp.media.max_size.document'); // largest allowed type (100 MB)

        if ($size > $max) {
            $attachment->forceFill([
                'download_status' => MessageAttachment::FAILED,
                'download_error' => 'File is larger than the allowed maximum.',
                'size' => $size,
            ])->save();

            return;
        }

        $mime = (string) ($info['mime_type'] ?? $attachment->mime_type ?? 'application/octet-stream');
        $mime = trim(explode(';', $mime)[0]);
        $extension = (new MimeTypes)->getExtensions($mime)[0] ?? 'bin';

        $disk = Storage::disk($attachment->disk ?: config('whatsapp.media.disk'));
        $relative = sprintf('%s/%d/%s/%s.%s', config('whatsapp.media.directory'), $account->id, now()->format('Y/m'), Str::uuid(), $extension);
        $disk->makeDirectory(dirname($relative));
        $absolute = $disk->path($relative);

        $api->downloadMediaTo((string) $info['url'], $absolute);

        $sha = $info['sha256'] ?? $attachment->sha256;

        if ($sha && strlen((string) $sha) === 64 && ! hash_equals(strtolower((string) $sha), hash_file('sha256', $absolute))) {
            @unlink($absolute);

            throw new \RuntimeException('Downloaded media failed the SHA-256 integrity check.');
        }

        $attachment->forceFill([
            'path' => $relative,
            'mime_type' => $mime,
            'size' => filesize($absolute) ?: $size,
            'download_status' => MessageAttachment::DOWNLOADED,
            'download_error' => null,
        ])->save();

        MessageUpdated::dispatch($attachment->message);
    }

    public function failed(?Throwable $e): void
    {
        $message = $e instanceof WhatsAppApiException ? $e->userMessage() : (string) $e?->getMessage();

        MessageAttachment::whereKey($this->attachmentId)->update([
            'download_status' => MessageAttachment::FAILED,
            'download_error' => Str::limit($message, 500),
        ]);
    }
}
