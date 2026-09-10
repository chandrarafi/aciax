<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BpkbSetting extends Model
{
    protected $table = 'aciax.bpkb_settings';

    protected $fillable = [
        'key',
        'value',
        'description',
    ];

    /**
     * Cek apakah efek scan dokumen aktif atau tidak
     */
    public static function isScanEffectEnabled(): bool
    {
        try {
            $val = static::where('key', 'scan_effect_enabled')->value('value');
            if ($val === null) {
                return (bool) env('BPKB_SCAN_EFFECT', true);
            }
            return in_array(strtolower((string) $val), ['1', 'true', 'on', 'yes'], true);
        } catch (\Throwable $e) {
            return (bool) env('BPKB_SCAN_EFFECT', true);
        }
    }

    /**
     * Ambil level kontras efek scan
     */
    public static function getScanContrast(): int
    {
        try {
            $val = static::where('key', 'scan_contrast')->value('value');
            return $val !== null ? (int) $val : -12;
        } catch (\Throwable $e) {
            return -12;
        }
    }

    /**
     * Ambil level kecerahan efek scan
     */
    public static function getScanBrightness(): int
    {
        try {
            $val = static::where('key', 'scan_brightness')->value('value');
            return $val !== null ? (int) $val : 8;
        } catch (\Throwable $e) {
            return 8;
        }
    }

    /**
     * Ubah status aktif/nonaktif efek scan
     */
    public static function setScanEffect(bool $enabled): void
    {
        static::updateOrCreate(
            ['key' => 'scan_effect_enabled'],
            [
                'value'       => $enabled ? '1' : '0',
                'description' => 'Mengaktifkan atau menonaktifkan efek scan dokumen (1 = Aktif, 0 = Nonaktif)',
            ]
        );
    }
}
