<?php

namespace Tests\Feature\Asisten;

use App\Models\Course;
use App\Models\Enrollment;
use App\Models\LabClass;
use App\Models\Semester;
use App\Models\Submission;
use App\Models\Task;
use App\Models\TaskTarget;
use App\Models\User;
use App\Support\Files\FileStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Support\Pdf;
use Tests\TestCase;

/**
 * Audit 03 P0-4: Code Viewer isi ZIP submission (`asisten.submissions.zip` dan `ZipBrowser`).
 */
class SubmissionZipTest extends TestCase
{
    use RefreshDatabase;

    private User $asisten;

    private LabClass $class;

    private TaskTarget $target;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('lab');

        $this->asisten = User::factory()->asisten()->create();
        $course = Course::factory()->for(Semester::factory()->active())->create();
        $course->assistants()->attach($this->asisten);
        $this->class = LabClass::factory()->create(['course_id' => $course->id, 'semester_id' => $course->semester_id]);
        $this->class->assistants()->attach($this->asisten);
        $task = Task::factory()->create(['course_id' => $course->id]);
        $this->target = TaskTarget::factory()->for($task)->create(['class_id' => $this->class->id, 'open_at' => now()->subDay(), 'due_at' => now()->addDay()]);
    }

    /**
     * @param  array<string, string>  $entries
     */
    private function zipSubmission(array $entries): Submission
    {
        $student = Enrollment::factory()->for($this->class, 'labClass')->create()->user;
        $submission = Submission::factory()->for($this->target, 'target')->for($student)->create();
        app(FileStore::class)->store(Pdf::zip($entries), ['zip'], 'submission', $submission, $student);

        return $submission;
    }

    private function read(Submission $submission, string $path): array
    {
        return $this->getJson(route('asisten.submissions.zip', ['submission' => $submission, 'path' => $path]))->assertOk()->json();
    }

    public function test_the_assistant_of_the_class_reads_a_text_file_inside_the_zip(): void
    {
        $submission = $this->zipSubmission(['src/main.py' => 'print("halo")', 'README.md' => '# Tugas']);

        $this->actingAs($this->asisten);

        $this->assertSame(['path' => 'src/main.py', 'content' => 'print("halo")', 'reason' => null], $this->read($submission, 'src/main.py'));
    }

    public function test_admin_may_read_any_class_but_an_assistant_of_another_class_may_not(): void
    {
        $submission = $this->zipSubmission(['a.py' => '1']);
        $url = route('asisten.submissions.zip', ['submission' => $submission, 'path' => 'a.py']);

        $this->actingAs(User::factory()->admin()->create())->getJson($url)->assertOk();
        $this->actingAs(User::factory()->asisten()->create())->getJson($url)->assertForbidden();
        $this->actingAs(User::factory()->create())->getJson($url)->assertForbidden();
    }

    public function test_the_student_who_submitted_cannot_use_the_code_viewer(): void
    {
        $submission = $this->zipSubmission(['a.py' => '1']);

        $this->actingAs($submission->user)
            ->getJson(route('asisten.submissions.zip', ['submission' => $submission, 'path' => 'a.py']))
            ->assertForbidden();
    }

    public function test_a_submission_without_a_zip_is_not_found(): void
    {
        $student = Enrollment::factory()->for($this->class, 'labClass')->create()->user;
        $pdf = Submission::factory()->for($this->target, 'target')->for($student)->create();
        app(FileStore::class)->store(Pdf::upload(), ['pdf'], 'submission', $pdf, $student);
        $none = Submission::factory()->for($this->target, 'target')->for(Enrollment::factory()->for($this->class, 'labClass')->create()->user)->create();

        $this->actingAs($this->asisten);

        $this->getJson(route('asisten.submissions.zip', ['submission' => $pdf, 'path' => 'a.py']))->assertNotFound();
        $this->getJson(route('asisten.submissions.zip', ['submission' => $none, 'path' => 'a.py']))->assertNotFound();
    }

    public function test_a_missing_path_reports_a_reason_instead_of_failing(): void
    {
        $submission = $this->zipSubmission(['a.py' => '1']);
        $this->actingAs($this->asisten);

        $result = $this->read($submission, 'tidak/ada.py');

        $this->assertNull($result['content']);
        $this->assertSame('File tidak ditemukan di dalam ZIP.', $result['reason']);
        $this->assertSame(['path' => '', 'content' => null, 'reason' => 'File tidak ditemukan di dalam ZIP.'], $this->read($submission, ''));
    }

    public function test_paths_that_try_to_leave_the_archive_never_read_files_from_disk(): void
    {
        $secret = tempnam(sys_get_temp_dir(), 'secret');
        file_put_contents($secret, 'RAHASIA-DI-LUAR-ZIP');
        $submission = $this->zipSubmission(['a.py' => '1']);
        $this->actingAs($this->asisten);

        foreach (['../../../../'.ltrim($secret, '/'), $secret, 'file://'.$secret, '..\\..\\x', "a.py\0.txt"] as $path) {
            $result = $this->getJson(route('asisten.submissions.zip', ['submission' => $submission, 'path' => $path]));

            $this->assertStringNotContainsString('RAHASIA-DI-LUAR-ZIP', $result->getContent());
            $this->assertNull($result->json('content'));
        }

        @unlink($secret);
    }

    public function test_a_file_over_the_viewer_limit_is_not_shown(): void
    {
        config(['lab.files.code_viewer_max' => 10]);
        $submission = $this->zipSubmission(['kecil.txt' => 'abc', 'besar.txt' => str_repeat('x', 50)]);
        $this->actingAs($this->asisten);

        $this->assertSame('abc', $this->read($submission, 'kecil.txt')['content']);

        $big = $this->read($submission, 'besar.txt');
        $this->assertNull($big['content']);
        $this->assertStringContainsString('1 MB', $big['reason']);
    }

    public function test_binary_content_is_not_shown(): void
    {
        $submission = $this->zipSubmission(['data.bin' => "ab\0cd", 'latin.txt' => "caf\xE9"]);
        $this->actingAs($this->asisten);

        foreach (['data.bin', 'latin.txt'] as $path) {
            $result = $this->read($submission, $path);
            $this->assertNull($result['content'], $path);
            $this->assertSame('File biner tidak dapat ditampilkan.', $result['reason'], $path);
        }
    }

    public function test_a_corrupt_archive_on_disk_is_reported_as_unreadable(): void
    {
        $submission = $this->zipSubmission(['a.py' => '1']);
        Storage::disk('lab')->put($submission->file->path, 'ini bukan zip');
        $this->actingAs($this->asisten);

        $this->getJson(route('asisten.submissions.zip', ['submission' => $submission, 'path' => 'a.py']))->assertStatus(422);
    }
}
