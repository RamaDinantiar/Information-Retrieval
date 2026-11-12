<?php

namespace Tests\Feature\Praktikan;

use App\Models\Enrollment;
use App\Models\Grade;
use App\Models\LabClass;
use App\Models\StoredFile;
use App\Models\Submission;
use App\Models\Task;
use App\Models\TaskTarget;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\Pdf;
use Tests\TestCase;

/**
 * US-P02 — Mengumpulkan tugas; FR-PT-01…05.
 */
class SubmissionTest extends TestCase
{
    use RefreshDatabase;

    private User $student;

    private TaskTarget $target;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('lab');

        $class = LabClass::factory()->create();
        $this->student = Enrollment::factory()->for($class, 'labClass')->create()->user;
        $task = Task::factory()->create(['course_id' => $class->course_id, 'semester_id' => $class->semester_id, 'allowed_formats' => ['pdf']]);
        $class->semester->forceFill(['status' => 'active'])->save();
        $this->target = TaskTarget::factory()->for($task)->create(['class_id' => $class->id, 'open_at' => now()->subHour(), 'due_at' => now()->addDay()]);
        $this->actingAs($this->student);
    }

    private function submit(array $data)
    {
        return $this->post(route('praktikan.tasks.submit', $this->target), $data);
    }

    public function test_students_see_open_tasks_of_their_classes_only()
    {
        $other = TaskTarget::factory()->create(); // kelas lain
        $draft = TaskTarget::factory()->create(['class_id' => $this->target->class_id, 'task_id' => Task::factory()->draft()->create(['course_id' => $this->target->task->course_id, 'semester_id' => $this->target->task->semester_id])]);
        $scheduled = TaskTarget::factory()->create(['class_id' => $this->target->class_id, 'task_id' => Task::factory()->create(['course_id' => $this->target->task->course_id, 'semester_id' => $this->target->task->semester_id]), 'open_at' => now()->addDay()]);

        $this->get(route('praktikan.tasks.index'))
            ->assertInertia(fn (Assert $page) => $page->has('tasks', 2)->where('tasks.0.id', $this->target->id)->where('tasks.0.status', 'not_submitted')
                ->where('tasks.1.id', $scheduled->id)->where('tasks.1.state', 'scheduled'));

        foreach ([$other, $draft] as $hidden) {
            $this->get(route('praktikan.tasks.show', $hidden))->assertNotFound();
        }
    }

    public function test_a_scheduled_task_can_be_opened_from_the_notification_but_hides_content_and_rejects_submission()
    {
        $scheduled = TaskTarget::factory()->create([
            'class_id' => $this->target->class_id,
            'task_id' => Task::factory()->create([
                'course_id' => $this->target->task->course_id, 'semester_id' => $this->target->task->semester_id,
                'body_json' => ['type' => 'doc', 'content' => []], 'allowed_formats' => ['pdf'],
            ]),
            'open_at' => now()->addMinutes(10), 'due_at' => now()->addMinutes(30),
        ]);

        $this->get(route('praktikan.tasks.show', $scheduled))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('task.state', 'scheduled')->where('task.body', null)->where('task.attachments', []));

        $this->post(route('praktikan.tasks.submit', $scheduled), ['file' => Pdf::upload('jawaban.pdf')])
            ->assertSessionHasErrors();
        $this->assertSame(0, Submission::count());
    }

    public function test_a_pdf_submission_gets_a_receipt_with_server_time()
    {
        $this->submit(['file' => Pdf::upload('jawaban.pdf'), 'text_answer' => '```python\nprint(1)\n```'])
            ->assertSessionHasNoErrors();

        $submission = Submission::sole();
        $this->assertMatchesRegularExpression('/^IRL-\d{6}-\d{6}$/', $submission->receipt_number);
        $this->assertFalse($submission->is_late);
        $this->assertSame('jawaban.pdf', $submission->file->original_name);

        $this->get(route('praktikan.tasks.show', $this->target))
            ->assertInertia(fn (Assert $page) => $page
                ->where('task.status', 'submitted')
                ->where('history.0.receiptNumber', $submission->receipt_number)
                ->whereNot('history.0.sha256', null)
            );
    }

    public function test_the_detail_shows_the_class_schedule_and_assistants()
    {
        $class = $this->target->labClass;
        $class->assistants()->attach(User::factory()->asisten()->create(['name' => 'Rama Dinantiar']));

        $this->get(route('praktikan.tasks.show', $this->target))
            ->assertInertia(fn (Assert $page) => $page
                ->where('task.assistants', ['Rama Dinantiar'])
                ->where('task.schedule.dayOfWeek', $class->day_of_week)
                ->where('task.schedule.startTime', $class->startClock())
            );
    }

    public function test_only_the_allowed_formats_are_accepted()
    {
        $this->submit(['file' => Pdf::zip(['main.py' => 'x'])])->assertSessionHasErrors('file');
        $this->assertSame(0, Submission::count());
        $this->assertSame(0, StoredFile::count());
    }

    public function test_a_file_is_required_unless_text_is_mandatory()
    {
        $this->submit(['text_answer' => 'tanpa file'])->assertSessionHasErrors('file');

        $this->target->task->update(['text_answer' => 'required']);
        $this->submit(['text_answer' => ''])->assertSessionHasErrors('text_answer');
        $this->submit(['text_answer' => 'Jawaban'])->assertSessionHasNoErrors();
    }

    public function test_a_second_submission_is_rejected_until_the_first_is_unsent()
    {
        $this->submit(['file' => Pdf::upload('v1.pdf')])->assertSessionHasNoErrors();
        $this->submit(['file' => Pdf::upload('v2.pdf')])->assertSessionHasErrors('submission');
        $this->assertSame(1, Submission::count());
        $this->assertSame('v1.pdf', Submission::sole()->file->original_name);

        $this->get(route('praktikan.tasks.show', $this->target))
            ->assertInertia(fn (Assert $page) => $page->where('task.canUnsubmit', true));

        $this->delete(route('praktikan.tasks.unsubmit', $this->target))->assertSessionHasNoErrors();
        $this->assertSame(0, Submission::count());
        $this->assertSame(1, Submission::withTrashed()->where('is_current', false)->count());

        $this->submit(['file' => Pdf::upload('v2.pdf')])->assertSessionHasNoErrors();
        $current = Submission::sole();
        $this->assertSame(2, $current->version);
        $this->assertSame('v2.pdf', $current->file->original_name);
    }

    public function test_after_unsend_the_file_and_text_stay_as_a_draft_that_can_be_resubmitted()
    {
        $this->submit(['file' => Pdf::upload('v1.pdf'), 'text_answer' => 'jawaban awal'])->assertSessionHasNoErrors();
        $this->delete(route('praktikan.tasks.unsubmit', $this->target));

        $this->get(route('praktikan.tasks.show', $this->target))
            ->assertInertia(fn (Assert $page) => $page->where('draft.textAnswer', 'jawaban awal')->where('draft.files.0.name', 'v1.pdf'));

        // Kirim ulang tanpa berkas baru, berkas lama ikut (keep_draft_file).
        $draftFileId = Submission::onlyTrashed()->latest('id')->first()->file->id;
        $this->submit(['keep_draft_files' => [$draftFileId], 'text_answer' => 'jawaban revisi'])->assertSessionHasNoErrors();
        $current = Submission::sole();
        $this->assertSame('v1.pdf', $current->file->original_name);
        $this->assertSame('jawaban revisi', $current->text_answer);
        $this->get(route('praktikan.tasks.show', $this->target))->assertInertia(fn (Assert $page) => $page->where('draft', null));
    }

    public function test_a_removed_draft_file_is_not_reattached()
    {
        $this->target->task->update(['text_answer' => 'required']);
        $this->submit(['file' => Pdf::upload('v1.pdf'), 'text_answer' => 'awal']);
        $this->delete(route('praktikan.tasks.unsubmit', $this->target));

        $this->submit(['text_answer' => 'hanya teks'])->assertSessionHasNoErrors();
        $this->assertNull(Submission::sole()->file);
    }

    public function test_several_pdfs_can_be_submitted_together_up_to_the_limit()
    {
        $this->submit(['files' => [Pdf::upload('a.pdf'), Pdf::upload('b.pdf'), Pdf::upload('c.pdf')]])->assertSessionHasNoErrors();

        $submission = Submission::sole();
        $this->assertSame(['a.pdf', 'b.pdf', 'c.pdf'], $submission->files->pluck('original_name')->all());

        $this->get(route('praktikan.tasks.show', $this->target))
            ->assertInertia(fn (Assert $page) => $page->has('history.0.files', 3));

        $this->delete(route('praktikan.tasks.unsubmit', $this->target));
        $this->submit(['files' => array_map(fn ($i) => Pdf::upload("f{$i}.pdf"), range(1, 6))])->assertSessionHasErrors('file');
        $this->assertSame(0, Submission::count());
    }

    public function test_a_draft_keeps_every_file_and_each_can_be_dropped_on_resubmit()
    {
        $this->submit(['files' => [Pdf::upload('a.pdf'), Pdf::upload('b.pdf')]]);
        $ids = Submission::sole()->files->pluck('id', 'original_name');
        $this->delete(route('praktikan.tasks.unsubmit', $this->target));

        $this->get(route('praktikan.tasks.show', $this->target))
            ->assertInertia(fn (Assert $page) => $page->has('draft.files', 2));

        $this->submit(['keep_draft_files' => [$ids['b.pdf']], 'files' => [Pdf::upload('c.pdf')]])->assertSessionHasNoErrors();
        $this->assertEqualsCanonicalizing(['b.pdf', 'c.pdf'], Submission::sole()->files->pluck('original_name')->all());
    }

    public function test_a_graded_or_closed_submission_cannot_be_unsent()
    {
        $this->submit(['file' => Pdf::upload()]);
        $submission = Submission::sole();

        $this->delete(route('praktikan.tasks.unsubmit', $this->target))->assertSessionHasNoErrors();
        $this->submit(['file' => Pdf::upload()]);
        $submission = Submission::sole();

        Grade::factory()->create(['submission_id' => $submission->id]);
        $this->delete(route('praktikan.tasks.unsubmit', $this->target))->assertSessionHasErrors('submission');
        $this->assertSame(1, Submission::count());

        $submission->grade()->delete();
        $this->target->update(['due_at' => now()->subMinute()]);
        $this->delete(route('praktikan.tasks.unsubmit', $this->target))->assertSessionHasErrors('submission');
        $this->assertSame(1, Submission::count());
    }

    public function test_late_submissions_are_labelled_when_allowed()
    {
        $this->target->update(['due_at' => now()->subMinutes(135)]);
        $this->target->task->update(['allow_late' => true]);

        $this->submit(['file' => Pdf::upload()])->assertSessionHasNoErrors();

        $submission = Submission::sole();
        $this->assertTrue($submission->is_late);
        $this->assertSame('Terlambat 2 jam 15 menit', $submission->lateLabel());
    }

    public function test_late_submissions_are_rejected_when_not_allowed()
    {
        $this->target->update(['due_at' => now()->subMinute()]);

        $this->submit(['file' => Pdf::upload()])->assertSessionHasErrors('submission');
        $this->assertSame(0, Submission::count());
    }

    public function test_submitted_files_are_private_to_the_student_and_class_assistants()
    {
        $this->submit(['file' => Pdf::upload()]);
        $file = Submission::sole()->file;

        $this->get($file->url())->assertOk();

        $classmate = Enrollment::factory()->for($this->target->labClass, 'labClass')->create()->user;
        $this->actingAs($classmate)->get($file->url())->assertForbidden();

        $assistant = User::factory()->asisten()->create();
        $this->target->labClass->assistants()->attach($assistant);
        $this->actingAs($assistant)->get($file->url())->assertOk();
    }
}
