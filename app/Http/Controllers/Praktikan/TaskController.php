<?php

namespace App\Http\Controllers\Praktikan;

use App\Actions\Task\SubmitTask;
use App\Actions\Task\UnsubmitTask;
use App\Enums\TaskType;
use App\Http\Controllers\Controller;
use App\Models\Semester;
use App\Models\StoredFile;
use App\Models\Submission;
use App\Models\TaskTarget;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Praktikan › Task (FR-PT, US-P02).
 */
class TaskController extends Controller
{
    public function index(Request $request, ?string $type = null): Response
    {
        $taskType = TaskType::fromSlug($type);
        $user = $request->user();

        $targets = $this->visibleTargets($user->id)
            ->when($taskType, fn ($q) => $q->whereHas('task', fn ($t) => $t->where('type', $taskType)))
            ->with(['task.course', 'labClass', 'submissions' => fn ($q) => $q->where('user_id', $user->id)->where('is_current', true)])
            ->get()
            ->sortBy(fn (TaskTarget $target) => [$target->state() === 'open' ? 0 : 1, $target->due_at->timestamp ?? PHP_INT_MAX]);

        return Inertia::render('praktikan/tasks/index', [
            'type' => $taskType?->value,
            'tasks' => $targets->values()->map(fn (TaskTarget $target) => $this->summary($target)),
        ]);
    }

    public function show(Request $request, TaskTarget $target, SubmitTask $submit): Response
    {
        $this->authorizeView($request, $target);
        $user = $request->user();
        $target->load(['task.course', 'task.attachments', 'labClass.room', 'labClass.assistants', 'meeting']);
        $task = $target->task;
        // Soal baru tampil saat tugas dibuka.
        $revealed = $target->state() !== 'scheduled';

        $history = Submission::where('task_target_id', $target->id)->where('user_id', $user->id)
            ->with('files')->orderByDesc('version')->get();

        return Inertia::render('praktikan/tasks/show', [
            'task' => [
                ...$this->summary($target->setRelation('submissions', $history->where('is_current', true)->values())),
                'body' => $revealed ? $task->body_json : null,
                'attachments' => ! $revealed ? [] : $task->attachments->map(fn (StoredFile $file) => $file->toClient(inline: true)),
                'canUnsubmit' => $target->state() === 'open'
                    && $history->contains(fn (Submission $s) => $s->is_current && ! $s->grade()->exists()),
                'maxScore' => $task->max_score,
                'allowLate' => $task->allow_late,
                'latePenalty' => $task->late_penalty !== null ? (float) $task->late_penalty : null,
                'latePenaltyUnit' => $task->late_penalty_unit,
                'allowedFormats' => $task->allowed_formats,
                'textAnswer' => $task->text_answer,
                'meeting' => $target->meeting ? ['number' => $target->meeting->number, 'title' => $target->meeting->topic] : null,
                // Figma "Matakuliah Aktif" & "Informasi Asisten".
                'schedule' => [
                    'dayOfWeek' => $target->labClass->day_of_week,
                    'startTime' => $target->labClass->startClock(),
                    'endTime' => $target->labClass->endClock(),
                    'room' => $target->labClass->room->name,
                ],
                'assistants' => $target->labClass->assistants->pluck('name')->values(),
                'maxFiles' => (int) config('lab.files.submission_count'),
                'limits' => ['pdf' => (int) config('lab.files.limits.pdf') / 1024, 'zip' => (int) config('lab.files.limits.zip') / 1024],
            ],
            // Draf dari pengumpulan yang dibatalkan (Unsend): berkas & teks tetap ada.
            'draft' => ($draft = $submit->draftFor($user, $target)) ? [
                'textAnswer' => $draft->text_answer,
                'files' => $draft->files->map(fn (StoredFile $f) => $f->toClient(inline: true))->values(),
            ] : null,
            'history' => $history->map(fn (Submission $submission) => [
                'id' => $submission->id,
                'version' => $submission->version,
                'isCurrent' => $submission->is_current,
                'submittedAt' => $submission->submitted_at->toIso8601String(),
                'isLate' => $submission->is_late,
                'lateLabel' => $submission->lateLabel(),
                'receiptNumber' => $submission->receipt_number,
                'textAnswer' => $submission->text_answer,
                'files' => $submission->files->map(fn (StoredFile $f) => $f->toClient(inline: true))->values(),
                'file' => $submission->files->first()?->toClient(),
                'sha256' => $submission->files->first()?->sha256,
            ]),
        ]);
    }

    public function submit(Request $request, TaskTarget $target, SubmitTask $submit): RedirectResponse
    {
        $this->authorizeView($request, $target);
        $request->validate([
            'files' => ['nullable', 'array'],
            'files.*' => ['file'],
            'file' => ['nullable', 'file'],
            'keep_draft_files' => ['nullable', 'array'],
            'keep_draft_files.*' => ['integer'],
            'text_answer' => ['nullable', 'string', 'max:20000'],
        ], [], ['file' => 'file', 'text_answer' => 'jawaban teks']);

        $uploads = array_values(array_filter([...(array) $request->file('files', []), $request->file('file')]));
        $submission = $submit($request->user(), $target, $uploads, $request->input('text_answer'), array_values(array_map('intval', (array) $request->input('keep_draft_files', []))));

        Inertia::flash('toast', ['type' => 'success', 'message' => "Terkumpul. Nomor tanda terima {$submission->receipt_number}."]);

        return back();
    }

    public function unsubmit(Request $request, TaskTarget $target, UnsubmitTask $unsubmit): RedirectResponse
    {
        $this->authorizeView($request, $target);
        $unsubmit($request->user(), $target);

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Pengumpulan dibatalkan. Anda dapat mengumpulkan ulang.']);

        return back();
    }

    /**
     * Target tugas terbit untuk kelas praktikan. Yang belum waktu bukanya tetap
     * bisa dilihat (mis. dari notifikasi) tetapi isinya disembunyikan dan belum
     * bisa dikerjakan — lihat `show()` dan `SubmitTask`.
     *
     * @return Builder<TaskTarget>
     */
    private function visibleTargets(int $userId)
    {
        return TaskTarget::query()
            ->whereNotNull('open_at')
            ->whereHas('task', fn ($q) => $q->where('status', 'published')->where('semester_id', Semester::current()?->id))
            ->whereHas('labClass.enrollments', fn ($q) => $q->where('user_id', $userId));
    }

    private function authorizeView(Request $request, TaskTarget $target): void
    {
        abort_unless($this->visibleTargets($request->user()->id)->whereKey($target->id)->exists(), 404);
    }

    /**
     * @return array<string, mixed>
     */
    private function summary(TaskTarget $target): array
    {
        $current = $target->submissions->first();

        return [
            'id' => $target->id,
            'type' => $target->task->type->value,
            'title' => $target->task->title,
            'course' => $target->task->course->name,
            'className' => $target->labClass->name,
            'openAt' => $target->open_at?->toIso8601String(),
            'dueAt' => $target->due_at?->toIso8601String(),
            'state' => $target->state(),
            // US-P02 & FR-PD-3: Belum / Terkumpul / Terlambat.
            'status' => $current ? ($current->is_late ? 'late' : 'submitted') : 'not_submitted',
            'submittedAt' => $current?->submitted_at->toIso8601String(),
        ];
    }
}
