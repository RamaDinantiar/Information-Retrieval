<?php

namespace Tests\Unit;

use App\Enums\TaskType;
use App\Models\Course;
use App\Models\Grade;
use App\Models\Submission;
use App\Models\Task;
use App\Models\TaskTarget;
use App\Support\Grading\FinalScore;
use Tests\TestCase;

/**
 * Audit 03 P1-1. Skala huruf mutu = ASUMSI S9 – menunggu skala resmi IT PLN.
 * Test huruf mengambil batas dari FinalScore::LETTER_SCALE (satu sumber), bukan angka yang ditulis ulang di sini,
 * jadi saat skala resmi diketahui cukup ubah konstanta itu (dan GRADE_SCALE di review.tsx, dijaga GradeScaleTest).
 */
class FinalScoreTest extends TestCase
{
    private function course(int $pre = 20, int $post = 30, int $report = 50): Course
    {
        return new Course(['weight_pretest' => $pre, 'weight_posttest' => $post, 'weight_report' => $report]);
    }

    private function grade(TaskType $type, ?float $score, int $max = 100): Grade
    {
        $task = new Task(['type' => $type, 'max_score' => $max]);
        $target = (new TaskTarget)->setRelation('task', $task);
        $submission = (new Submission)->setRelation('target', $target);

        return (new Grade(['score' => $score]))->setRelation('submission', $submission);
    }

    public function test_every_boundary_of_the_letter_scale_belongs_to_the_higher_letter(): void
    {
        $scale = FinalScore::LETTER_SCALE;
        $minimums = array_keys($scale);
        $letters = array_values($scale);

        foreach ($minimums as $i => $minimum) {
            $below = $letters[$i + 1] ?? FinalScore::LOWEST_LETTER;

            $this->assertSame($letters[$i], FinalScore::letter((float) $minimum), "tepat di {$minimum}");
            $this->assertSame($letters[$i], FinalScore::letter($minimum + 0.1), 'sedikit di atas '.$minimum);
            $this->assertSame($below, FinalScore::letter($minimum - 0.1), 'sedikit di bawah '.$minimum);
        }
    }

    public function test_the_extremes_map_to_the_top_and_bottom_letters(): void
    {
        $this->assertSame(array_values(FinalScore::LETTER_SCALE)[0], FinalScore::letter(100.0));
        $this->assertSame(FinalScore::LOWEST_LETTER, FinalScore::letter(0.0));
        $this->assertSame(FinalScore::LOWEST_LETTER, FinalScore::letter(-5.0));
    }

    public function test_the_scale_is_well_formed(): void
    {
        $minimums = array_keys(FinalScore::LETTER_SCALE);
        $sorted = $minimums;
        rsort($sorted);

        $this->assertSame($sorted, $minimums, 'batas harus urut dari tertinggi agar pencocokan pertama benar');
        $this->assertSame($letters = array_values(FinalScore::LETTER_SCALE), array_values(array_unique($letters)), 'huruf tidak boleh ganda');
        $this->assertNotContains(FinalScore::LOWEST_LETTER, $letters);
        $this->assertLessThanOrEqual(100, $minimums[0]);
        $this->assertGreaterThan(0, end($minimums));
    }

    public function test_no_grades_gives_no_result(): void
    {
        $result = FinalScore::compute($this->course(), []);

        $this->assertSame(['pretest' => null, 'posttest' => null, 'report' => null, 'final' => null, 'letter' => null], $result);
    }

    public function test_ungraded_rows_and_invalid_max_scores_are_skipped(): void
    {
        $result = FinalScore::compute($this->course(), [
            $this->grade(TaskType::Pretest, null),
            $this->grade(TaskType::Posttest, 50, 0),
            $this->grade(TaskType::Report, 10, -5),
        ]);

        $this->assertNull($result['final']);
        $this->assertNull($result['letter']);
    }

    public function test_a_component_is_the_average_of_its_percentages_not_of_raw_points(): void
    {
        // 50/50 = 100% dan 0/100 = 0% → 50, bukan (50+0)/(50+100).
        $result = FinalScore::compute($this->course(), [$this->grade(TaskType::Pretest, 50, 50), $this->grade(TaskType::Pretest, 0, 100)]);

        $this->assertSame(50.0, $result['pretest']);
        $this->assertSame(50.0, $result['final']);
    }

    public function test_missing_components_renormalise_the_weights(): void
    {
        $only = FinalScore::compute($this->course(), [$this->grade(TaskType::Posttest, 70)]);
        $all = FinalScore::compute($this->course(), [
            $this->grade(TaskType::Pretest, 100), $this->grade(TaskType::Posttest, 0), $this->grade(TaskType::Report, 0),
        ]);

        $this->assertSame(70.0, $only['final'], 'satu komponen saja → nilai komponen itu');
        $this->assertSame(20.0, $all['final'], '100·20 / (20+30+50)');
    }

    public function test_all_weights_zero_gives_no_final_score(): void
    {
        $result = FinalScore::compute($this->course(0, 0, 0), [$this->grade(TaskType::Pretest, 90)]);

        $this->assertSame(90.0, $result['pretest']);
        $this->assertNull($result['final']);
        $this->assertNull($result['letter']);
    }

    public function test_a_zero_weight_component_is_ignored_in_the_final_score(): void
    {
        $result = FinalScore::compute($this->course(0, 50, 50), [$this->grade(TaskType::Pretest, 100), $this->grade(TaskType::Posttest, 60), $this->grade(TaskType::Report, 80)]);

        $this->assertSame(70.0, $result['final']);
    }

    public function test_results_are_rounded_to_one_decimal(): void
    {
        $result = FinalScore::compute($this->course(), [$this->grade(TaskType::Pretest, 1, 3)]);

        $this->assertSame(33.3, $result['pretest']);
        $this->assertSame(33.3, $result['final']);
    }

    public function test_the_letter_follows_the_final_score(): void
    {
        $result = FinalScore::compute($this->course(), [$this->grade(TaskType::Pretest, 100)]);

        $this->assertSame(FinalScore::letter(100.0), $result['letter']);
    }

    public function test_a_score_above_the_maximum_is_not_capped_here(): void
    {
        // Batas skor ≤ max_score dijaga saat input (SaveGradesBatch); FinalScore sendiri tidak memotong.
        $result = FinalScore::compute($this->course(), [$this->grade(TaskType::Pretest, 120)]);

        $this->assertSame(120.0, $result['final']);
    }
}
