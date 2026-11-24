<?php

namespace App\Services\WhatsApp;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Voice notes recorded in the browser → what WhatsApp accepts as a voice
 * message: an OGG file, Opus codec, MONO (Meta rejects stereo with
 * "Media file scrutiny … failed"). Kept small (24 kbit/s) so WhatsApp shows
 * the play button (files over 512 KB get a download icon instead).
 *
 * With ffmpeg on the server every recording is re-encoded (Chrome / Edge /
 * Android WebM, iPhone MP4 and Firefox OGG). Without ffmpeg only a mono
 * OGG/Opus recording can be sent as it is.
 */
class VoiceNoteConverter
{
    /** WhatsApp shows a play button only for voice files up to 512 KB — stay safely below. */
    public const VOICE_MAX_BYTES = 480 * 1024;

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
        $isOgg = in_array($mime, ['audio/ogg', 'application/ogg'], true);

        if (! $isOgg && ! in_array($mime, ['video/webm', 'audio/webm', 'video/mp4', 'audio/mp4', 'audio/x-m4a', 'audio/aac', 'audio/mpeg', 'audio/wav', 'audio/x-wav'], true)) {
            throw ValidationException::withMessages(['file' => 'This recording format is not supported. Please try again in Chrome, Firefox or Safari.']);
        }

        if ($this->ffmpegAvailable()) {
            return new UploadedFile($this->toOggOpus($path), 'voice-note.ogg', 'audio/ogg', null, true);
        }

        // No ffmpeg: only an OGG that is already mono Opus is safe to send.
        if ($isOgg && self::isMonoOpus($path)) {
            return new UploadedFile($path, 'voice-note.ogg', 'audio/ogg', null, true);
        }

        throw ValidationException::withMessages(['file' => 'Voice notes need “ffmpeg” on the server to be converted to WhatsApp’s format. Ask the administrator to run the update command (it installs ffmpeg).']);
    }

    /**
     * Re-encodes a stored file (used by Retry for older voice notes).
     *
     * @return string|null  path of the new .ogg file, or null when ffmpeg is missing
     */
    public function reencode(string $path): ?string
    {
        return $this->ffmpegAvailable() ? $this->toOggOpus($path) : null;
    }

    /** OGG whose Opus header says one channel (OpusHead: magic, version, channel count). */
    public static function isMonoOpus(string $path): bool
    {
        $head = (string) @file_get_contents($path, false, null, 0, 4096);
        $pos = strpos($head, 'OpusHead');

        return $pos !== false && isset($head[$pos + 9]) && ord($head[$pos + 9]) === 1;
    }

    /**
     * Mono Opus OGG. Long recordings get a lower bitrate so the file stays under
     * 512 KB (bigger voice files fail or show a download icon on WhatsApp).
     */
    private function toOggOpus(string $input): string
    {
        $output = $this->encodeOpus($input, 24);
        $size = (int) filesize($output);

        if ($size > self::VOICE_MAX_BYTES) {
            $kbps = max(8, (int) floor(24 * self::VOICE_MAX_BYTES / $size));
            @unlink($output);
            $output = $this->encodeOpus($input, $kbps);
        }

        return $output;
    }

    /**
     * Plain AAC audio (.m4a) — the fallback when Meta refuses a voice note.
     * ffmpeg's built-in AAC encoder is in every build.
     */
    public function toAac(string $input): ?string
    {
        if (! $this->ffmpegAvailable()) {
            return null;
        }

        $output = $this->tempPath('m4a');
        $result = Process::timeout(120)->run([$this->binary(), '-hide_banner', '-loglevel', 'error', '-y', '-i', $input, '-vn', '-map_metadata', '-1', '-ac', '1', '-ar', '44100', '-c:a', 'aac', '-b:a', '64k', '-movflags', '+faststart', '-f', 'mp4', $output]);

        return $result->successful() && is_file($output) && filesize($output) > 0 ? $output : null;
    }

    private function tempPath(string $extension): string
    {
        $base = tempnam(sys_get_temp_dir(), 'voice');
        @unlink($base);

        return $base.'.'.$extension;
    }

    private function encodeOpus(string $input, int $kbps): string
    {
        $output = $this->tempPath('ogg');

        // Always mono Opus, no metadata. libopus first, then ffmpeg's built-in Opus encoder.
        $common = ['-vn', '-map_metadata', '-1', '-ac', '1', '-ar', '48000', '-b:a', $kbps.'k'];
        $attempts = [
            ['-c:a', 'libopus', '-application', 'voip'],
            ['-c:a', 'opus', '-strict', '-2'],
        ];

        foreach ($attempts as $codec) {
            $result = Process::timeout(120)->run([$this->binary(), '-hide_banner', '-loglevel', 'error', '-y', '-i', $input, ...$common, ...$codec, '-f', 'ogg', $output]);

            if ($result->successful() && is_file($output) && filesize($output) > 0) {
                return $output;
            }

            Log::warning('Voice note conversion attempt failed', ['codec' => $codec[1], 'error' => mb_substr($result->errorOutput(), 0, 300)]);
        }

        throw ValidationException::withMessages(['file' => 'The voice note could not be converted. Please record again.']);
    }

    private function binary(): string
    {
        return (string) config('whatsapp.media.ffmpeg', 'ffmpeg');
    }
}
