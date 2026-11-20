<?php

namespace App\Support;

/**
 * Format NIM untuk rekap ngawas: `2023 – 31 – 044` (tahun – kode prodi –
 * nomor, dipisah en dash dengan spasi; keputusan P6). NIM tersimpan apa
 * adanya di `users.nim`; formatter hanya dipakai saat tampil/ekspor.
 */
class NimFormatter
{
    public const SEPARATOR = ' – ';

    /**
     * Menerima `202331044`, `2023-31-044`, `2023 - 31 - 044`, `2023 – 31 – 044`.
     * NIM yang bukan 9 digit dikembalikan apa adanya (sudah dirapikan spasinya).
     */
    public static function format(?string $nim): string
    {
        $raw = trim((string) $nim);

        if ($raw === '') {
            return '';
        }

        $digits = preg_replace('/\D/', '', $raw) ?? '';
        $onlySeparators = preg_match('/^[\d\s\-–—.]+$/u', $raw) === 1;

        if (! $onlySeparators || strlen($digits) !== 9) {
            return $raw;
        }

        return substr($digits, 0, 4).self::SEPARATOR.substr($digits, 4, 2).self::SEPARATOR.substr($digits, 6, 3);
    }
}
