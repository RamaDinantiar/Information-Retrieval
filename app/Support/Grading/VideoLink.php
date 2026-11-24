<?php

namespace App\Support\Grading;

/**
 * Laporan praktikum dikumpulkan sebagai link video (biasanya YouTube) lewat kolom
 * jawaban teks. Mengambil URL pertama dari jawaban itu.
 */
class VideoLink
{
    public static function extract(?string $text): ?string
    {
        if ($text === null || ! preg_match('~https?://[^\s<>"\']+~i', $text, $match)) {
            return null;
        }

        return rtrim($match[0], '.,;:)]');
    }
}
