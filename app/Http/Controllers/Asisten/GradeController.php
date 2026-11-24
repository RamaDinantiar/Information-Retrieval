<?php

namespace App\Http\Controllers\Asisten;

use App\Actions\Grade\PublishGrades;
use App\Actions\Grade\SaveGradesBatch;
use App\Enums\ApprovalStatus;
use App\Enums\TaskType;
use App\Http\Controllers\Asisten\Concerns\AuthorizesClassAssistant;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Enrollment;
use App\Models\Grade;
use App\Models\LabClass;
use App\Models\Semester;
use App\Models\Submission;
use App\Models\TaskTarget;
use App\Support\Files\ZipBrowser;
use App\Support\Grading\FinalScore;
use App\Support\Grading\VideoLink;
use App\Support\Reports\GradeRecapWorkbook;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Asisten (& Admin) › Grade (FR-AG, US-A02).
 */
class GradeController extends Controller
{
    use AuthorizesClassAssistant;

    public function index(Request $request, ?string $type = null): Response
    {
        $taskType = TaskType::fromSlug($type);
        $user = $request->user();
        $semester = Semester::current();

        $classes = $semester
            ? LabClass::forSemester($semester)->where('approval_status', ApprovalStatus::Approved)->taughtBy($user)
                ->with('course')->orderBy('course_id')->orderBy('name')->get()
            : collect();

        $class = $classes->firstWhere('id', $request->integer('class')) ?? $classes->first();

        $targets = $class
            ? TaskTarget::where('class_id', $class->id)
                ->whereNotNull('open_at')
                ->whereHas('task', fn ($q) => $q->where('status', 'published')->when($taskType, fn ($t) => $t->where('type', $taskType)))
                ->with('task')
                ->withCount([
                    'submissions as submitted_count' => fn ($q) => $q->where('is_current', true),
                    'submissions as graded_count' => fn ($q) => $q->where('is_current', true)->whereHas('grade', fn ($g) => $g->whereNotNull('score')),
                ])
                ->orderByDesc('due_at')
                ->get()
            : collect();

        $target = $targets->firstWhere('id', $request->integer('target'))
            ?? $targets->sortByDesc(fn (TaskTarget $t) => $t->submitted_count - $t->graded_count)->first();

        return Inertia::render('asisten/grades/index', [
            'type' => $taskType?->value,
            'classes' => $classes->map(fn (LabClass $c) => [
                'id' => $c->id,
                'label' => "{$c->course->name} · {$c->name}",
                'name' => $c->name,
                'courseId' => $c->course_id,
                'course' => $c->course->name,
            ])->values(),
            'targets' => $targets->map(fn (TaskTarget $t) => [
                'id' => $t->id,
                'label' => $t->task->title,
                'type' => $t->task->type->value,
                'meetingNumber' => $t->task->meeting_number,
                'submitted' => $t->submitted_count,
                'graded' => $t->graded_count,
            ])->values(),
            'selected' => ['classId' => $class?->id, 'targetId' => $target?->id],
            'target' => $target ? $this->targetSummary($target->setRelation('labClass', $class)) : null,
            'rows' => $target ? $this->rows($target) : [],
        ]);
    }

    public function batch(Request $request, SaveGradesBatch $save): RedirectResponse
    {
        $validated = $request->validate([
            'target_id' => ['required', 'integer', 'exists:task_targets,id'],
            'grades' => ['required', 'array', 'min:1', 'max:500'],
            'grades.*.submission_id' => ['required', 'integer', 'distinct'],
            'grades.*.score' => ['nullable'],
            'grades.*.theory' => ['nullable'],
            'grades.*.practice' => ['nullable'],
            'grades.*.video' => ['nullable'],
            'grades.*.feedback' => ['nullable', 'string'],
            'grades.*.version' => ['nullable', 'integer'],
        ]);

        $target = TaskTarget::with(['task', 'labClass'])->whereKey($validated['target_id'])->firstOrFail();
        $this->authorizeAssistant($request, $target->labClass, write: true);

        $count = $save($request->user(), $target, $validated['grades']);

        Inertia::flash('toast', ['type' => 'success', 'message' => "{$count} nilai disimpan sebagai Draft. Publikasikan agar terlihat praktikan."]);

        return back();
    }

    public function publish(Request $request, TaskTarget $target, PublishGrades $publish): RedirectResponse
    {
        $this->authorizeAssistant($request, $target->labClass, write: true);
        $count = $publish($target);

        Inertia::flash('toast', [
            'type' => $count > 0 ? 'success' : 'info',
            'message' => $count > 0 ? "{$count} nilai dipublikasikan. Praktikan mendapat notifikasi." : 'Tidak ada nilai Draft yang perlu dipublikasikan.',
        ]);

        return back();
    }

    /**
     * FR-AG-04: Split-Screen 70:30 — dokumen di kiri, nilai di kanan.
     */
    public function review(Request $request, TaskTarget $target): Response
    {
        $target->load(['task', 'labClass']);
        $this->authorizeAssistant($request, $target->labClass);

        $rows = collect($this->rows($target))->filter(fn ($row) => $row['submission'] !== null)->values();
        $current = $rows->firstWhere('userId', $request->integer('student')) ?? $rows->first();
        $index = $current ? $rows->search(fn ($row) => $row['userId'] === $current['userId']) : null;

        $zip = null;
        if ($current && ($current['submission']['file']['mime'] ?? null) === 'application/zip') {
            $submission = Submission::with('file')->whereKey($current['submission']['id'])->firstOrFail();
            $zip = ZipBrowser::entries($submission->file);
        }

        return Inertia::render('asisten/grades/review', [
            'target' => $this->targetSummary($target),
            'students' => $rows->map(fn ($row) => ['userId' => $row['userId'], 'name' => $row['name'], 'nim' => $row['nim'], 'graded' => $row['grade']['score'] ?? null])->values(),
            'current' => $current,
            'position' => $index === null ? null : ['index' => $index, 'total' => $rows->count(), 'prev' => $rows[$index - 1]['userId'] ?? null, 'next' => $rows[$index + 1]['userId'] ?? null],
            'zipEntries' => $zip,
        ]);
    }

    /**
     * Isi satu file dari ZIP submission (Code Viewer).
     */
    public function zipFile(Request $request, Submission $submission): JsonResponse
    {
        $submission->load(['target.labClass', 'file']);
        $this->authorizeAssistant($request, $submission->target->labClass);
        abort_unless($submission->file?->mime === 'application/zip', 404);

        return response()->json(ZipBrowser::read($submission->file, (string) $request->query('path')));
    }

    /**
     * FR-AG-06: rekap nilai satu kelas ke Excel dengan tampilan template "Rekap Nilai", dipisah per jenis
     * (?jenis=): `tes` = sheet Pre-Test + sheet Post-Test, `laporan` = Laporan Praktikum
     * (Teori/Praktikum/Video + total), `akhir` = nilai akhir. Hanya nilai yang sudah Published.
     */
    public function export(Request $request, LabClass $class): StreamedResponse
    {
        $this->authorizeAssistant($request, $class);
        $class->load('course');
        $course = $class->course;

        $jenis = in_array($request->query('jenis'), ['tes', 'laporan', 'akhir'], true) ? $request->query('jenis') : 'akhir';

        $targets = TaskTarget::where('class_id', $class->id)->whereHas('task', fn ($q) => $q->where('status', 'published'))
            ->with('task')->get()
            ->sortBy(fn (TaskTarget $t) => [$t->task->meeting_number ?? 99, $t->id])->values();

        /** @var Collection<int, Collection<int, Grade>> $grades per mahasiswa → nilai terpublikasi per tugas */
        $grades = Grade::where('status', 'published')
            ->whereHas('submission', fn ($q) => $q->whereIn('task_target_id', $targets->pluck('id'))->where('is_current', true))
            ->with('submission.target.task')
            ->get()
            ->groupBy('submission.user_id');

        $members = $class->enrollments()->with('user:id,nim,name')->get()->sortBy(fn (Enrollment $e) => $e->user->nim)->values();
        $students = $members->map(fn (Enrollment $e) => ['nim' => $e->user->nim, 'name' => $e->user->name])->all();

        $short = (string) preg_replace('/^kelas\s+/i', '', $class->name);
        $name = mb_strtoupper("{$course->name} {$short}");
        $scoreOf = fn (Enrollment $e, TaskTarget $t): ?Grade => $grades->get($e->user_id)?->first(fn (Grade $g) => $g->submission->task_target_id === $t->id);

        $sheets = match ($jenis) {
            'laporan' => [GradeRecapWorkbook::reportSheet(
                $short,
                "NILAI MAHASISWA {$name}",
                $students,
                $targets->filter(fn (TaskTarget $t) => $t->task->type === TaskType::Report)->map(
                    fn (TaskTarget $t) => $members->map(function (Enrollment $e) use ($scoreOf, $t) {
                        $grade = $scoreOf($e, $t);

                        return $grade === null ? null : [
                            'theory' => $grade->score_theory !== null ? (float) $grade->score_theory : null,
                            'practice' => $grade->score_practice !== null ? (float) $grade->score_practice : null,
                            'video' => $grade->score_video !== null ? (float) $grade->score_video : null,
                            'total' => $grade->score !== null ? (float) $grade->score : null,
                        ];
                    })->all()
                )->values()->all(),
                $course->report_has_video,
            )],
            'tes' => array_map(fn (TaskType $type) => GradeRecapWorkbook::testSheet(
                $type->label(),
                'NILAI '.mb_strtoupper($type->label())." MAHASISWA {$name}",
                'RATA-RATA '.mb_strtoupper($type->label()),
                $students,
                $targets->filter(fn (TaskTarget $t) => $t->task->type === $type)->map(fn (TaskTarget $t) => [
                    'title' => $this->testColumnTitle($type, $t->task->title),
                    'scores' => $members->map(fn (Enrollment $e) => ($g = $scoreOf($e, $t))?->score !== null ? (float) $g->score : null)->all(),
                ])->values()->all(),
            ), [TaskType::Pretest, TaskType::Posttest]),
            default => [GradeRecapWorkbook::finalSheet(
                $short,
                "NILAI AKHIR MAHASISWA {$name}",
                $students,
                $members->map(fn (Enrollment $e) => FinalScore::compute($course, $grades->get($e->user_id, collect())))->all(),
            )],
        };

        $suffix = ['tes' => 'Pre-Post Test', 'laporan' => 'Laporan Praktikum', 'akhir' => 'Akhir'][$jenis];
        $filename = trim((string) preg_replace('/[\\\\\/:*?"<>|]+/', ' ', "{$course->code} {$course->name} - {$class->name} - Rekap Nilai {$suffix}")).'.xlsx';

        return response()->streamDownload(function () use ($sheets) {
            $path = (string) tempnam(sys_get_temp_dir(), 'rekap');
            GradeRecapWorkbook::write($path, $sheets);
            readfile($path);
            unlink($path);
        }, $filename, ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']);
    }

    /** "Post-Test: <judul tugas>" — awalan "Post-Test"/"Pre-Test" di judul tidak diulang. */
    private function testColumnTitle(TaskType $type, string $title): string
    {
        $prefix = $type->label();
        $rest = trim((string) preg_replace('/(pre|post)[\s-]*test\s*:?\s*/i', '', $title, 1), " \t-:");

        return $rest === '' ? $prefix : "{$prefix}: {$rest}";
    }

    /**
     * @return array<string, mixed>
     */
    private function targetSummary(TaskTarget $target): array
    {
        $current = Submission::where('task_target_id', $target->id)->where('is_current', true);
        $target->loadMissing('task.course');

        return [
            'id' => $target->id,
            'classId' => $target->class_id,
            'className' => $target->labClass->name,
            // Header Mode Tinjauan (Figma "SPLIT SCREEN REVIEW").
            'course' => $target->task->course->name,
            'meetingNumber' => $target->task->meeting_number,
            'title' => $target->task->title,
            'type' => $target->task->type->value,
            'maxScore' => $target->task->max_score,
            'hasVideo' => $target->task->type === TaskType::Report && $target->task->course->report_has_video,
            'dueAt' => $target->due_at?->toIso8601String(),
            'submitted' => (clone $current)->count(),
            'graded' => (clone $current)->whereHas('grade', fn ($q) => $q->whereNotNull('score'))->count(),
            'drafts' => (clone $current)->whereHas('grade', fn ($q) => $q->where('status', 'draft')->whereNotNull('score'))->count(),
            'members' => $target->labClass->enrollments()->count(),
            'canEdit' => $target->labClass->isEditable(),
        ];
    }

    /**
     * Semua anggota kelas + submission terkini & nilainya (US-A02-1).
     *
     * @return array<int, array<string, mixed>>
     */
    private function rows(TaskTarget $target): array
    {
        $submissions = Submission::where('task_target_id', $target->id)->where('is_current', true)
            ->with(['file', 'files', 'grade.grader'])->get()->keyBy('user_id');

        // Praktikan yang sudah membuka tugas (audit log `task.viewed`) → status "Hanya melihat" bila belum mengumpulkan.
        $viewers = AuditLog::where('action', 'task.viewed')->where('auditable_type', $target->getMorphClass())
            ->where('auditable_id', $target->id)->pluck('user_id')->flip();

        /** @var Collection<int, Enrollment> $members */
        $members = $target->labClass->enrollments()->with('user:id,nim,name')->get()
            ->sortBy(fn (Enrollment $e) => $e->user->nim)->values();

        return $members->map(function (Enrollment $enrollment) use ($submissions, $viewers) {
            $submission = $submissions->get($enrollment->user_id);
            $grade = $submission?->grade;

            return [
                'userId' => $enrollment->user_id,
                'nim' => $enrollment->user->nim,
                'name' => $enrollment->user->name,
                'viewed' => $viewers->has($enrollment->user_id),
                'submission' => $submission ? [
                    'id' => $submission->id,
                    'submittedAt' => $submission->submitted_at->toIso8601String(),
                    'isLate' => $submission->is_late,
                    'lateLabel' => $submission->lateLabel(),
                    'version' => $submission->version,
                    'textAnswer' => $submission->text_answer,
                    'videoLink' => VideoLink::extract($submission->text_answer),
                    'file' => $submission->file?->toClient(inline: true),
                    'files' => $submission->files->map(fn ($f) => $f->toClient(inline: true))->values(),
                ] : null,
                'grade' => $grade ? [
                    'score' => $grade->score !== null ? (float) $grade->score : null,
                    'theory' => $grade->score_theory !== null ? (float) $grade->score_theory : null,
                    'practice' => $grade->score_practice !== null ? (float) $grade->score_practice : null,
                    'video' => $grade->score_video !== null ? (float) $grade->score_video : null,
                    'feedback' => $grade->feedback,
                    'status' => $grade->status,
                    'version' => $grade->version,
                    'grader' => $grade->grader?->name,
                ] : null,
            ];
        })->all();
    }
}
