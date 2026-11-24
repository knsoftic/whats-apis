<?php

namespace App\Services\WhatsApp;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Voice notes recorded in the browser → a file WhatsApp accepts as a voice
 * message (OGG with the Opus codec, mono).
 *
 *  - Firefox records OGG/Opus already → sent as it is.
 *  - Chrome / Edge / Android record WebM/Opus and Safari / iPhone record MP4 →
 *    converted with ffmpeg on the server.
 */
class VoiceNoteConverter
{
    private ?bool $available = null;

    public function ffmpegAvailable(): bool
    {
        if ($this->available !== null) {
            return $this->available;
        }

        try {
            return $this->available = Process::timeout(10)->run([$this->binary(), '-hide_banner', '-version'])->successful();
        } catch (Throwable) {
            return $this->available = false;
        }
    }

    /**
     * @throws ValidationException when the recording can't be turned into a voice note
     */
    public function prepare(UploadedFile $file): UploadedFile
    {
        $path = (string) $file->getRealPath();
        $mime = strtolower((string) (new \finfo(FILEINFO_MIME_TYPE))->file($path));

        // Already OGG (Firefox) → keep, just make sure the name ends in .ogg.
        if (in_array($mime, ['audio/ogg', 'application/ogg'], true)) {
            return new UploadedFile($path, 'voice-note.ogg', 'audio/ogg', null, true);
        }

        if (! in_array($mime, ['video/webm', 'audio/webm', 'video/mp4', 'audio/mp4', 'audio/x-m4a', 'audio/aac', 'audio/mpeg', 'audio/wav', 'audio/x-wav'], true)) {
            throw ValidationException::withMessages(['file' => 'This recording format is not supported. Please try again in Chrome, Firefox or Safari.']);
        }

        if (! $this->ffmpegAvailable()) {
            throw ValidationException::withMessages(['file' => 'Voice notes from this browser need “ffmpeg” on the server. Ask the administrator to run the update command (it installs ffmpeg), or record in Firefox.']);
        }

        return new UploadedFile($this->toOggOpus($path), 'voice-note.ogg', 'audio/ogg', null, true);
    }

    private function toOggOpus(string $input): string
    {
        $base = tempnam(sys_get_temp_dir(), 'voice');
        @unlink($base);
        $output = $base.'.ogg';

        // Re-encode to mono Opus (what WhatsApp shows as a voice note); fall back to a plain remux.
        $attempts = [
            ['-c:a', 'libopus', '-b:a', '32k', '-ac', '1', '-ar', '48000', '-application', 'voip'],
            ['-c:a', 'copy'],
        ];

        foreach ($attempts as $codec) {
            $result = Process::timeout(60)->run([$this->binary(), '-hide_banner', '-loglevel', 'error', '-y', '-i', $input, '-vn', ...$codec, '-f', 'ogg', $output]);

            if ($result->successful() && is_file($output) && filesize($output) > 0) {
                return $output;
            }

            Log::warning('Voice note conversion attempt failed', ['error' => mb_substr($result->errorOutput(), 0, 300)]);
        }

        throw ValidationException::withMessages(['file' => 'The voice note could not be converted. Please record again.']);
    }

    private function binary(): string
    {
        return (string) config('whatsapp.media.ffmpeg', 'ffmpeg');
    }
}
