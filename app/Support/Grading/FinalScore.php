<?php

namespace App\Support\Grading;

use App\Enums\TaskType;
use App\Models\Course;
use App\Models\Grade;

/**
 * PRD A6: nilai akhir mata kuliah = rata-rata berbobot komponen Pre-test,
 * Post-test, Laporan (bobot dari Mata Kuliah). Setiap komponen = rata-rata
 * persentase (nilai/maks × 100) dari nilai yang sudah dipublikasikan.
 * "Sementara": komponen tanpa nilai tidak ikut, bobot dinormalisasi.
 */
class FinalScore
{
    /**
     * @param  iterable<Grade>  $grades  nilai terpublikasi milik satu praktikan pada mata kuliah ini
     * @return array{pretest: float|null, posttest: float|null, report: float|null, final: float|null, letter: string|null}
     */
    public static function compute(Course $course, iterable $grades): array
    {
        $buckets = ['pretest' => [], 'posttest' => [], 'report' => []];

        foreach ($grades as $grade) {
            $task = $grade->submission->target->task;
            if ($grade->score === null || $task->max_score <= 0) {
                continue;
            }

            $buckets[$task->type->value][] = (float) $grade->score / $task->max_score * 100;
        }

        $weights = [
            TaskType::Pretest->value => $course->weight_pretest,
            TaskType::Posttest->value => $course->weight_posttest,
            TaskType::Report->value => $course->weight_report,
        ];

        $components = array_map(fn (array $values) => $values === [] ? null : round(array_sum($values) / count($values), 1), $buckets);

        $weighted = 0.0;
        $weightSum = 0;
        foreach ($components as $type => $value) {
            if ($value !== null) {
                $weighted += $value * $weights[$type];
                $weightSum += $weights[$type];
            }
        }

        $final = $weightSum > 0 ? round($weighted / $weightSum, 1) : null;

        return [...$components, 'final' => $final, 'letter' => $final === null ? null : self::letter($final)];
    }

    /**
     * ASUMSI S9 – menunggu skala resmi IT PLN. Batas bawah nilai (skor ≥ batas) → huruf mutu, urut dari
     * tertinggi. Ini SATU-SATUNYA sumber skala di sisi PHP; test membaca konstanta ini, bukan angka
     * yang ditulis ulang. Salinan di sisi tampilan: `GRADE_SCALE` di resources/js/pages/asisten/grades/review.tsx
     * (dijaga sama oleh GradeScaleTest). Saat skala resmi diketahui, ubah kedua tempat itu.
     */
    public const LETTER_SCALE = [
        85 => 'A',
        80 => 'AB',
        70 => 'B',
        65 => 'BC',
        55 => 'C',
        40 => 'D',
    ];

    /** Huruf bila skor di bawah semua batas pada LETTER_SCALE. */
    public const LOWEST_LETTER = 'E';

    public static function letter(float $score): string
    {
        foreach (self::LETTER_SCALE as $minimum => $letter) {
            if ($score >= $minimum) {
                return $letter;
            }
        }

        return self::LOWEST_LETTER;
    }
}
