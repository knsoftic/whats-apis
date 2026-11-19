<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MessageAttachment extends Model
{
    use HasFactory;

    public const PENDING = 'pending';
    public const DOWNLOADED = 'downloaded';
    public const FAILED = 'failed';
    public const NOT_REQUIRED = 'not_required';

    protected $fillable = [
        'message_id',
        'wa_media_id',
        'type',
        'mime_type',
        'file_name',
        'size',
        'sha256',
        'disk',
        'path',
        'download_status',
        'download_error',
    ];

    protected $hidden = [
        'path',
        'disk',
    ];

    protected function casts(): array
    {
        return [
            'size' => 'integer',
        ];
    }

    public function message(): BelongsTo
    {
        return $this->belongsTo(Message::class);
    }

    public function isAvailable(): bool
    {
        return $this->download_status === self::DOWNLOADED && $this->path !== null;
    }

    public function humanSize(): string
    {
        $bytes = (int) $this->size;

        if ($bytes <= 0) {
            return '';
        }

        $units = ['B', 'KB', 'MB', 'GB'];
        $i = (int) floor(log($bytes, 1024));

        return round($bytes / (1024 ** $i), 1).' '.$units[$i];
    }
}
