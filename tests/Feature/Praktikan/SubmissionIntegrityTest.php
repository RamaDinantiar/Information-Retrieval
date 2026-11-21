<?php

namespace Tests\Feature\Praktikan;

use App\Models\Enrollment;
use App\Models\LabClass;
use App\Models\StoredFile;
use App\Models\Submission;
use App\Models\Task;
use App\Models\TaskTarget;
use App\Models\User;
use App\Support\Files\FileStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Support\Pdf;
use Tests\TestCase;

/**
 * Audit 03 P1-4: pengumpulan yang gagal tidak meninggalkan baris atau berkas setengah jadi.
 */
class SubmissionIntegrityTest extends TestCase
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
        $class->semester->forceFill(['status' => 'active'])->save();
        $task = Task::factory()->create(['course_id' => $class->course_id, 'semester_id' => $class->semester_id, 'allowed_formats' => ['pdf']]);
        $this->target = TaskTarget::factory()->for($task)->create(['class_id' => $class->id, 'open_at' => now()->subHour(), 'due_at' => now()->addDay()]);
        $this->actingAs($this->student);
    }

    private function submit(array $data)
    {
        return $this->post(route('praktikan.tasks.submit', $this->target), $data);
    }

    public function test_a_bad_second_file_rolls_back_the_submission_and_removes_the_first_file(): void
    {
        $broken = UploadedFile::fake()->createWithContent('rusak.pdf', 'ini bukan pdf');

        $this->submit(['files' => [Pdf::upload('bagus.pdf'), $broken]])->assertSessionHasErrors();

        $this->assertSame(0, Submission::withTrashed()->count());
        $this->assertSame(0, StoredFile::count());
        $this->assertSame([], Storage::disk('lab')->allFiles(), 'tidak boleh ada berkas yatim di disk');
    }

    public function test_a_failed_attempt_does_not_block_the_next_valid_submission(): void
    {
        $this->submit(['files' => [UploadedFile::fake()->createWithContent('rusak.pdf', 'bukan pdf')]])->assertSessionHasErrors();

        $this->submit(['files' => [Pdf::upload('benar.pdf')]])->assertSessionHasNoErrors();

        $submission = Submission::sole();
        $this->assertSame(1, $submission->version, 'percobaan gagal tidak boleh memakai nomor versi');
        $this->assertSame(1, StoredFile::count());
    }

    public function test_unsend_keeps_the_files_as_a_draft_and_resubmit_reuses_them_without_duplicating(): void
    {
        $this->submit(['files' => [Pdf::upload('a.pdf')]]);
        $first = Submission::sole();
        $fileId = $first->files->sole()->id;

        $this->delete(route('praktikan.tasks.unsubmit', $this->target));
        $this->assertSame(1, StoredFile::count(), 'berkas draf tetap ada setelah Unsend');

        $this->submit(['keep_draft_files' => [$fileId]])->assertSessionHasNoErrors();

        $current = Submission::where('is_current', true)->sole();
        $this->assertSame(2, $current->version);
        $this->assertSame([$fileId], $current->files->pluck('id')->all());
        $this->assertSame(1, StoredFile::count());
    }

    public function test_deleting_a_task_removes_its_attachment_files_from_disk(): void
    {
        $asisten = User::factory()->asisten()->create();
        $this->target->task->course->assistants()->attach($asisten);
        $draft = Task::factory()->draft()->create(['course_id' => $this->target->task->course_id, 'semester_id' => $this->target->task->semester_id]);
        app(FileStore::class)->store(Pdf::upload('soal.pdf'), ['pdf'], 'task_attachment', $draft, $asisten);
        $path = StoredFile::sole()->path;
        Storage::disk('lab')->assertExists($path);

        $this->actingAs($asisten)->delete(route('asisten.tasks.destroy', $draft))->assertRedirect();

        $this->assertSame(0, StoredFile::count());
        Storage::disk('lab')->assertMissing($path);
    }
}
