<?php

namespace Tests\Unit;

use App\Support\Grading\FinalScore;
use PHPUnit\Framework\TestCase;

/**
 * Audit 03 P1-1. Skala umum (S9 diputuskan 2026-11-25).
 *
 * Skala huruf mutu ditulis dua kali: FinalScore::LETTER_SCALE (PHP) dan GRADE_SCALE di halaman review (TypeScript).
 * Ini pengecualian yang disengaja dari aturan "jangan membaca isi berkas": satu-satunya cara menjaga dua salinan
 * tetap sama tanpa mengubah kode tampilan. Bila gagal, ubah kedua tempat itu bersamaan.
 */
class GradeScaleTest extends TestCase
{
    public function test_the_review_page_uses_the_same_scale_as_the_backend(): void
    {
        $source = file_get_contents(dirname(__DIR__, 2).'/resources/js/pages/asisten/grades/review.tsx');

        $this->assertSame(1, preg_match('/const GRADE_SCALE[^=]*=\s*\[(.*?)\];/s', $source, $block), 'GRADE_SCALE tidak ditemukan di review.tsx');
        preg_match_all("/\[\s*(\d+)\s*,\s*'([A-Z]+)'\s*\]/", $block[1], $pairs, PREG_SET_ORDER);

        $frontend = [];
        foreach ($pairs as $pair) {
            $frontend[(int) $pair[1]] = $pair[2];
        }

        $this->assertSame(FinalScore::LETTER_SCALE, $frontend);
        $this->assertSame(1, preg_match("/\?\.\[1\]\s*\?\?\s*'".FinalScore::LOWEST_LETTER."'/", $source), 'huruf terendah di review.tsx berbeda dari LOWEST_LETTER');
    }
}
