<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;
use Throwable;

/**
 * Key/value platform settings. Every value is encrypted at rest with APP_KEY.
 */
class Setting extends Model
{
    protected $fillable = ['key', 'value'];

    protected $hidden = ['value'];

    /** @var array<string, string|null> */
    private static array $cache = [];

    public static function get(string $key, ?string $default = null): ?string
    {
        if (! array_key_exists($key, self::$cache)) {
            $raw = static::query()->where('key', $key)->value('value');

            try {
                self::$cache[$key] = $raw === null ? null : Crypt::decryptString($raw);
            } catch (Throwable) {
                self::$cache[$key] = null; // unreadable (APP_KEY changed)
            }
        }

        $value = self::$cache[$key];

        return $value === null || $value === '' ? $default : $value;
    }

    public static function put(string $key, ?string $value): void
    {
        static::query()->updateOrCreate(
            ['key' => $key],
            ['value' => $value === null || $value === '' ? null : Crypt::encryptString($value)],
        );

        self::$cache[$key] = $value;
    }

    public static function flushCache(): void
    {
        self::$cache = [];
    }
}
