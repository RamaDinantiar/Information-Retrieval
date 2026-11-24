<?php

namespace App\Actions\Grade;

use App\Enums\TaskType;
use App\Events\GradesUpdated;
use App\Models\AuditLog;
use App\Models\Grade;
use App\Models\Submission;
use App\Models\TaskTarget;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * US-A02-3 / FR-AG-03: Simpan Batch — semua perubahan dalam satu transaksi;
 * satu baris invalid → tidak ada yang tersimpan. Optimistic locking lewat
 * `version` mencegah dua asisten menimpa nilai yang sama.
 *
 * Audit 03 N12: mengubah nilai yang sudah Published langsung terlihat praktikan, memicu notifikasi
 * "Nilai diperbarui" dan dicatat di audit log (`grade.updated_after_publish`).
 */
class SaveGradesBatch
{
    /**
     * Laporan praktikum: baris boleh membawa `theory`, `practice`, `video`; `score` (TOTAL) lalu dihitung
     * sebagai rata-rata komponen dan baru terisi bila semua komponen yang dipakai mata kuliah sudah ada.
     *
     * @param  list<array{submission_id: int, score?: float|int|string|null, theory?: float|int|string|null, practice?: float|int|string|null, video?: float|int|string|null, feedback: string|null, version: int|null}>  $rows
     */
    public function __invoke(User $grader, TaskTarget $target, array $rows): int
    {
        $max = $target->task->max_score;
        $isReport = $target->task->type === TaskType::Report;
        $components = $target->task->course->report_has_video ? ['theory', 'practice', 'video'] : ['theory', 'practice'];
        $errors = [];

        $submissions = Submission::where('task_target_id', $target->id)
            ->whereIn('id', array_column($rows, 'submission_id'))
            ->where('is_current', true)
            ->pluck('id')->all();

        $outOfRange = fn (mixed $value): bool => $value !== null && $value !== '' && (! is_numeric($value) || $value < 0 || $value > $max);

        foreach ($rows as $index => $row) {
            $fields = $isReport && $this->hasComponents($row) ? [$row['theory'] ?? null, $row['practice'] ?? null, $row['video'] ?? null] : [$row['score'] ?? null];

            if (! in_array((int) $row['submission_id'], $submissions, true)) {
                $errors["grades.{$index}.score"] = 'Submission tidak ditemukan pada tugas ini.';
            } elseif (array_filter($fields, $outOfRange) !== []) {
                $errors["grades.{$index}.score"] = "Nilai harus 0–{$max}.";
            } elseif (mb_strlen((string) ($row['feedback'] ?? '')) > 5000) {
                $errors["grades.{$index}.feedback"] = 'Feedback maksimal 5.000 karakter.';
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        $notify = [];

        $count = DB::transaction(function () use ($grader, $rows, $isReport, $components, &$notify) {
            $existing = Grade::whereIn('submission_id', array_column($rows, 'submission_id'))
                ->lockForUpdate()->get()->keyBy('submission_id');

            $conflicts = [];
            foreach ($rows as $index => $row) {
                $grade = $existing[(int) $row['submission_id']] ?? null;
                if ($grade && (int) ($row['version'] ?? 0) !== $grade->version) {
                    $conflicts["grades.{$index}.score"] = "Nilai ini baru saja diubah oleh {$grade->grader?->name}. Muat ulang halaman sebelum menyimpan.";
                }
            }

            if ($conflicts !== []) {
                throw ValidationException::withMessages($conflicts);
            }

            foreach ($rows as $row) {
                $grade = $existing[(int) $row['submission_id']] ?? new Grade(['submission_id' => (int) $row['submission_id'], 'version' => 0]);
                // Audit mencatat skor dan ada/tidaknya feedback, bukan isinya (feedback bersifat pribadi per praktikan).
                $before = $grade->exists ? ['score' => $grade->score, 'has_feedback' => $grade->feedback !== null] : null;
                $wasPublished = $grade->exists && $grade->status === 'published';
                $oldScore = $grade->score;
                $oldFeedback = $grade->feedback;
                $oldComponents = [$grade->score_theory, $grade->score_practice, $grade->score_video];

                $scores = $isReport && $this->hasComponents($row)
                    ? $this->componentScores($row, $components)
                    : ['score' => ($row['score'] ?? '') === '' ? null : $row['score']];

                $grade->fill([
                    ...$scores,
                    'feedback' => $row['feedback'] !== null && trim($row['feedback']) !== '' ? $row['feedback'] : null,
                    'graded_by' => $grader->id,
                    'graded_at' => now(),
                ]);
                $grade->version++;
                $grade->save();

                AuditLog::record('grade.saved', $grade, $before, ['score' => $grade->score, 'status' => $grade->status, 'has_feedback' => $grade->feedback !== null]);

                $changed = (string) $oldScore !== (string) $grade->score || $oldFeedback !== $grade->feedback
                    || $oldComponents !== [$grade->score_theory, $grade->score_practice, $grade->score_video];
                if ($wasPublished && $changed) {
                    AuditLog::record('grade.updated_after_publish', $grade, $before, ['score' => $grade->score, 'has_feedback' => $grade->feedback !== null]);
                    $notify[] = (int) $grade->submission()->value('user_id');
                }
            }

            return count($rows);
        });

        if ($notify !== []) {
            GradesUpdated::dispatch($target, array_values(array_unique($notify)));
        }

        return $count;
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function hasComponents(array $row): bool
    {
        return array_key_exists('theory', $row) || array_key_exists('practice', $row) || array_key_exists('video', $row);
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  list<string>  $components
     * @return array{score: float|null, score_theory: mixed, score_practice: mixed, score_video: mixed}
     */
    private function componentScores(array $row, array $components): array
    {
        $value = fn (string $key) => in_array($key, $components, true) && ($row[$key] ?? '') !== '' ? (float) $row[$key] : null;
        $parts = array_map($value, $components);

        return [
            'score' => in_array(null, $parts, true) ? null : round(array_sum($parts) / count($parts), 2),
            'score_theory' => $value('theory'),
            'score_practice' => $value('practice'),
            'score_video' => $value('video'),
        ];
    }
}
