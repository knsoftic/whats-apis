<?php

namespace App\Services\WhatsApp;

use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use Symfony\Component\Mime\MimeTypes;

/**
 * Decides whether an uploaded file can be sent through WhatsApp, and as which type.
 * MIME type is detected from the file CONTENT (finfo), not trusted from the browser.
 */
class OutgoingMediaValidator
{
    /** Generic container types that finfo reports for Office files on some systems. */
    private const CONTAINER_MIMES = ['application/zip', 'application/octet-stream', 'application/x-ole-storage', 'application/cdfv2', 'application/vnd.ms-office'];

    /**
     * @return array{type:string, mime:string}
     */
    public function resolve(UploadedFile $file, ?string $requestedType = null): array
    {
        if (! $file->isValid()) {
            throw ValidationException::withMessages(['file' => 'The upload failed. Please try again.']);
        }

        $extension = strtolower($file->getClientOriginalExtension());
        // Always sniff the real bytes — never trust the browser or the file name.
        $detected = strtolower((string) (new \finfo(FILEINFO_MIME_TYPE))->file((string) $file->getRealPath()));
        $detected = $detected === 'application/ogg' ? 'audio/ogg' : $detected;

        $mimes = config('whatsapp.media.mimes');
        $extensions = config('whatsapp.media.extensions');

        $type = null;
        $mime = null;

        foreach ($mimes as $candidateType => $allowed) {
            if (in_array($detected, $allowed, true) && in_array($extension, $extensions[$candidateType] ?? [], true)) {
                $type = $candidateType;
                $mime = $detected;
                break;
            }
        }

        // Office documents detected as a generic container — trust the extension only for documents.
        if (! $type && in_array($detected, self::CONTAINER_MIMES, true) && in_array($extension, ['doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx'], true)) {
            $type = 'document';
            $mime = (new MimeTypes)->getMimeTypes($extension)[0] ?? 'application/octet-stream';
        }

        // An image/video/audio file can always be sent as a document if the user chose "document".
        if ($requestedType === 'document' && $type && $type !== 'document') {
            $type = 'document';
        }

        if (! $type || ! $mime) {
            throw ValidationException::withMessages([
                'file' => 'This file type cannot be sent on WhatsApp. Allowed: JPG/PNG images, MP4/3GP video, AAC/AMR/MP3/M4A/OGG audio, PDF/Office/TXT documents.',
            ]);
        }

        $max = (int) config("whatsapp.media.max_size.{$type}");

        if ($file->getSize() > $max) {
            throw ValidationException::withMessages([
                'file' => sprintf('%s files can be at most %s MB on WhatsApp.', ucfirst($type), round($max / 1048576, 1)),
            ]);
        }

        return ['type' => $type, 'mime' => $mime];
    }
}
