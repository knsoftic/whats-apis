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

    private static bool $preloaded = false;

    /**
     * Load every setting with one query (called once per request at boot).
     */
    public static function preload(): void
    {
        $rows = static::query()->get(['key', 'value']);

        foreach ($rows as $row) {
            try {
                self::$cache[$row->key] = $row->value === null ? null : Crypt::decryptString($row->value);
            } catch (Throwable) {
                self::$cache[$row->key] = null;
            }
        }

        self::$preloaded = true;
    }

    public static function get(string $key, ?string $default = null): ?string
    {
        if (! array_key_exists($key, self::$cache) && self::$preloaded) {
            self::$cache[$key] = null; // not stored → default
        }

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
        self::$preloaded = false;
    }
}
