<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * App-wide key-value settings that need to be flippable from the UI at runtime
 * and durable across deploys and cache flushes, unlike env-backed config.
 */
class AppSetting extends Model
{
    protected $fillable = ['key', 'value'];

    protected $casts = [
        'value' => 'array',
    ];

    /**
     * The stored value for a key, or $default when the key was never written.
     */
    public static function getValue(string $key, mixed $default = null): mixed
    {
        $setting = static::query()->where('key', $key)->first();

        return $setting ? $setting->value : $default;
    }

    public static function putValue(string $key, mixed $value): void
    {
        static::query()->updateOrCreate(['key' => $key], ['value' => $value]);
    }
}
