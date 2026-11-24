<?php

namespace Tests\Feature\Asisten;

use App\Enums\TaskType;
use App\Events\GradesPublished;
use App\Models\AuditLog;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\Grade;
use App\Models\LabClass;
use App\Models\Semester;
use App\Models\Submission;
use App\Models\Task;
use App\Models\TaskTarget;
use App\Models\User;
use App\Support\Grading\FinalScore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * FR-AG-01…06, US-A02, FR-PG — Penilaian.
 */
class GradingTest extends TestCase
{
    use RefreshDatabase;

    private User $asisten;

    private LabClass $class;

    private TaskTarget $target;

    /** @var list<Submission> */
    private array $submissions;

    protected function setUp(): void
    {
        parent::setUp();

        $this->asisten = User::factory()->asisten()->create();
        $course = Course::factory()->for(Semester::factory()->active())->create();
        $course->assistants()->attach($this->asisten);
        $this->class = LabClass::factory()->create(['course_id' => $course->id, 'semester_id' => $course->semester_id]);
        $this->class->assistants()->attach($this->asisten);
        $task = Task::factory()->create(['course_id' => $course->id, 'max_score' => 100]);
        $this->target = TaskTarget::factory()->for($task)->create(['class_id' => $this->class->id, 'open_at' => now()->subDay(), 'due_at' => now()->addDay()]);

        $this->submissions = collect(range(1, 3))->map(function () {
            $student = Enrollment::factory()->for($this->class, 'labClass')->create()->user;

            return Submission::factory()->for($this->target, 'target')->for($student)->create();
        })->all();

        $this->actingAs($this->asisten);
    }

    private function batch(array $grades)
    {
        return $this->patch(route('asisten.grades.batch'), ['target_id' => $this->target->id, 'grades' => $grades]);
    }

    private function row(Submission $submission, $score, ?int $version = null, ?string $feedback = null): array
    {
        return ['submission_id' => $submission->id, 'score' => $score, 'feedback' => $feedback, 'version' => $version];
    }

    public function test_the_grade_table_lists_class_members_with_their_submissions()
    {
        $this->get(route('asisten.grades.index', ['type' => 'post-test']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('asisten/grades/index')
                ->where('selected.targetId', $this->target->id)
                ->has('rows', 3));
    }

    public function test_the_audit_log_records_scores_but_not_the_feedback_text()
    {
        $this->batch([$this->row($this->submissions[0], 80, null, 'Catatan pribadi untuk praktikan ini')])->assertSessionHasNoErrors();

        $log = AuditLog::where('action', 'grade.saved')->sole();
        $this->assertEquals(80, $log->after_json['score']);
        $this->assertTrue($log->after_json['has_feedback']);
        $this->assertArrayNotHasKey('feedback', $log->after_json);
        $this->assertStringNotContainsString('Catatan pribadi', json_encode($log->after_json).json_encode($log->before_json));
    }

    public function test_a_batch_with_a_duplicated_submission_is_rejected_cleanly()
    {
        $this->batch([$this->row($this->submissions[0], 80), $this->row($this->submissions[0], 90)])
            ->assertSessionHasErrors('grades.1.submission_id');

        $this->assertDatabaseCount('grades', 0);
    }

    public function test_a_batch_is_saved_as_draft_with_versions()
    {
        $this->batch([
            $this->row($this->submissions[0], 85, feedback: "Bagus.\n```python\nprint(1)\n```"),
            $this->row($this->submissions[1], '72.5'),
        ])->assertSessionHasNoErrors();

        $this->assertSame(2, Grade::where('status', 'draft')->count());
        $grade = $this->submissions[1]->grade()->first();
        $this->assertSame(72.5, (float) $grade->score);
        $this->assertSame(1, $grade->version);
        $this->assertSame($this->asisten->id, $grade->graded_by);
    }

    public function test_one_invalid_row_saves_nothing()
    {
        $this->batch([
            $this->row($this->submissions[0], 90),
            $this->row($this->submissions[1], 150),
        ])->assertSessionHasErrors('grades.1.score');

        $this->assertSame(0, Grade::count());
    }

    public function test_submissions_from_other_targets_are_rejected()
    {
        $foreign = Submission::factory()->create();

        $this->batch([$this->row($foreign, 80)])->assertSessionHasErrors('grades.0.score');
        $this->assertSame(0, Grade::count());
    }

    public function test_stale_versions_are_rejected_to_prevent_overwrites()
    {
        $grade = Grade::factory()->for($this->submissions[0])->create(['score' => 70, 'version' => 2, 'graded_by' => User::factory()->asisten()]);

        $this->batch([$this->row($this->submissions[0], 95, version: 1)])->assertSessionHasErrors('grades.0.score');
        $this->assertSame(70.0, (float) $grade->fresh()->score);

        $this->batch([$this->row($this->submissions[0], 95, version: 2)])->assertSessionHasNoErrors();
        $this->assertSame(3, $grade->fresh()->version);
    }

    public function test_assistants_of_other_courses_cannot_grade()
    {
        $this->actingAs(User::factory()->asisten()->create());

        $this->batch([$this->row($this->submissions[0], 80)])->assertForbidden();
        $this->post(route('asisten.grading.publish', $this->target))->assertForbidden();
    }

    public function test_publishing_releases_only_filled_drafts_and_notifies()
    {
        Event::fake([GradesPublished::class]);
        Grade::factory()->for($this->submissions[0])->create(['score' => 80]);
        Grade::factory()->for($this->submissions[1])->create(['score' => null]);

        $this->post(route('asisten.grading.publish', $this->target))->assertRedirect();

        $this->assertTrue($this->submissions[0]->grade()->first()->isPublished());
        $this->assertFalse($this->submissions[1]->grade()->first()->isPublished());
        Event::assertDispatched(GradesPublished::class, fn ($event) => $event->userIds === [$this->submissions[0]->user_id]);
    }

    public function test_the_review_page_walks_through_submissions()
    {
        $this->get(route('asisten.grading.review', [$this->target, 'student' => $this->submissions[1]->user_id]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('asisten/grades/review')
                ->where('current.userId', $this->submissions[1]->user_id)
                ->where('position.total', 3)
                ->has('students', 3));
    }

    public function test_grades_export_to_excel()
    {
        Grade::factory()->published()->for($this->submissions[0])->create(['score' => 90]);

        $response = $this->get(route('asisten.classes.grades.export', $this->class));
        $response->assertOk();
        $this->assertStringContainsString('.xlsx', $response->headers->get('content-disposition'));
    }

    /**
     * @return array<string, string> isi XML tiap sheet ekspor (nama sheet → xml), dibaca dari berkas xlsx.
     */
    private function exportSheets(string $jenis): array
    {
        $response = $this->get(route('asisten.classes.grades.export', [$this->class, 'jenis' => $jenis]));
        $response->assertOk();
        $disposition = $response->headers->get('content-disposition');
        $this->assertStringContainsString($this->class->course->name, $disposition);
        $this->assertStringContainsString('Rekap Nilai', $disposition);

        $path = tempnam(sys_get_temp_dir(), 'x');
        file_put_contents($path, $response->streamedContent());
        $zip = new \ZipArchive;
        $this->assertTrue($zip->open($path));
        $workbook = $zip->getFromName('xl/workbook.xml');
        preg_match_all('/<sheet name="([^"]+)" sheetId="(\d+)"/', $workbook, $matches, PREG_SET_ORDER);
        $sheets = [];
        foreach ($matches as $match) {
            $sheets[html_entity_decode($match[1])] = $zip->getFromName("xl/worksheets/sheet{$match[2]}.xml");
        }
        $zip->close();
        unlink($path);

        return $sheets;
    }

    private function makeGraded(TaskType $type, string $title, array $grade, ?string $text = null): TaskTarget
    {
        $task = Task::factory()->create(['course_id' => $this->class->course_id, 'type' => $type, 'title' => $title, 'status' => 'published', 'meeting_number' => null, 'max_score' => 100]);
        $target = TaskTarget::factory()->for($task)->create(['class_id' => $this->class->id]);
        $submission = Submission::factory()->for($target, 'target')->create(['user_id' => $this->submissions[0]->user_id, 'text_answer' => $text, 'is_current' => true]);
        Grade::factory()->published()->for($submission)->create($grade);

        return $target;
    }

    public function test_report_export_follows_the_recap_template_layout()
    {
        $this->class->course->update(['report_has_video' => true]);
        $this->makeGraded(TaskType::Report, 'Laporan A', ['score' => 80, 'score_theory' => 80, 'score_practice' => 70, 'score_video' => 90]);
        $this->makeGraded(TaskType::Report, 'Laporan B', ['score' => 60]); // nilai lama tanpa komponen

        $sheets = $this->exportSheets('laporan');
        $this->assertSame([preg_replace('/^kelas\s+/i', '', $this->class->name)], array_keys($sheets));
        $xml = array_values($sheets)[0];

        foreach (['NILAI MAHASISWA', 'LAPORAN 1', 'LAPORAN 2', 'TEORI', 'PRAKTIKUM', 'VIDEO', 'TOTAL NILAI', 'NILAI HASIL LAPORAN'] as $label) {
            $this->assertStringContainsString($label, $xml);
        }
        $this->assertStringContainsString('<mergeCell ref="B1:', $xml);
        $this->assertStringContainsString('<mergeCell ref="E3:H3"/>', $xml); // grup LAPORAN 1 = Teori, Praktikum, Video, Total
        $this->assertMatchesRegularExpression('~<f>\(E(\d)\+F\1\+G\1\)/3</f><v>80</v>~', $xml);
        $this->assertMatchesRegularExpression('~<f>SUM\(H\d,L\d\)/2</f><v>70</v>~', $xml);
        // Nilai lama (hanya satu angka): TOTAL tampil apa adanya, tanpa rumus.
        $this->assertMatchesRegularExpression('~<c r="L\d" s="\d+"><v>60</v></c>~', $xml);
        $this->assertStringContainsString('<pane xSplit="4" ySplit="4" topLeftCell="E5"', $xml);
    }

    public function test_report_export_without_video_has_two_components_per_report()
    {
        $this->makeGraded(TaskType::Report, 'Laporan A', ['score' => 75, 'score_theory' => 80, 'score_practice' => 70]);

        $xml = array_values($this->exportSheets('laporan'))[0];
        $this->assertStringNotContainsString('VIDEO', $xml);
        $this->assertStringContainsString('<mergeCell ref="E3:G3"/>', $xml);
        $this->assertMatchesRegularExpression('~<f>\(E\d\+F\d\)/2</f><v>75</v>~', $xml);
        $this->assertMatchesRegularExpression('~<f>SUM\(G\d\)/1</f><v>75</v>~', $xml);
    }

    public function test_test_export_has_pre_and_post_sheets_with_prefixed_column_titles()
    {
        $this->makeGraded(TaskType::Pretest, 'Pre-Test Pertemuan 1 · Basis Data', ['score' => 80]);
        $this->makeGraded(TaskType::Posttest, 'Post Test: Normalisasi', ['score' => 90]);

        $sheets = $this->exportSheets('tes');
        $this->assertSame(['Pre-Test', 'Post-Test'], array_keys($sheets));
        $this->assertStringContainsString('Pre-Test: Pertemuan 1 · Basis Data', $sheets['Pre-Test']);
        $this->assertStringContainsString('RATA-RATA PRE-TEST', $sheets['Pre-Test']);
        $this->assertStringContainsString('Post-Test: Normalisasi', $sheets['Post-Test']);
        // setUp sudah punya satu Post-Test lain (kolom pertama) → rata-rata dibagi 2 tes.
        $this->assertMatchesRegularExpression('~<f>SUM\(E\d:F\d\)/2</f><v>45</v>~', $sheets['Post-Test']);
        $this->assertStringContainsString('NILAI POST-TEST MAHASISWA', $sheets['Post-Test']);
        $this->assertStringNotContainsString('Normalisasi', $sheets['Pre-Test']);
    }

    public function test_final_export_has_weighted_final_score_without_letter()
    {
        $this->makeGraded(TaskType::Report, 'Laporan A', ['score' => 90]);

        $xml = array_values($this->exportSheets('akhir'))[0];
        $this->assertStringContainsString('NILAI AKHIR', $xml);
        $this->assertStringNotContainsString('HURUF', $xml);
        $this->assertMatchesRegularExpression('~<c r="H\d" s="\d+"><v>90</v></c>~', $xml);
    }

    public function test_report_grades_are_saved_as_components_and_averaged()
    {
        $report = $this->makeGraded(TaskType::Report, 'Laporan A', ['score' => null]);
        $submission = $report->submissions()->first();
        $grade = $submission->grade;

        $save = fn (array $row) => $this->patch(route('asisten.grades.batch'), ['target_id' => $report->id, 'grades' => [[
            'submission_id' => $submission->id, 'version' => $grade->fresh()->version, 'feedback' => null, ...$row,
        ]]]);

        $save(['theory' => '80', 'practice' => '70', 'video' => ''])->assertSessionHasNoErrors();
        $this->assertEquals(75, $grade->fresh()->score);
        $this->assertEquals(80, $grade->fresh()->score_theory);
        $this->assertNull($grade->fresh()->score_video);

        // Video dipakai mata kuliah: TOTAL baru terisi bila semua komponen ada.
        $this->class->course->update(['report_has_video' => true]);
        $save(['theory' => '80', 'practice' => '70', 'video' => ''])->assertSessionHasNoErrors();
        $this->assertNull($grade->fresh()->score);
        $save(['theory' => '80', 'practice' => '70', 'video' => '90'])->assertSessionHasNoErrors();
        $this->assertEquals(80, $grade->fresh()->score);

        $save(['theory' => '101', 'practice' => '70', 'video' => '90'])->assertSessionHasErrors('grades.0.score');
    }

    public function test_report_table_marks_students_who_only_viewed_the_task()
    {
        $report = $this->makeGraded(TaskType::Report, 'Laporan V', ['score' => 80]);
        $viewer = $this->submissions[1]->user;

        // Praktikan membuka tugas dua kali → hanya satu catatan "dilihat".
        $this->actingAs($viewer);
        $this->get(route('praktikan.tasks.show', $report))->assertOk();
        $this->get(route('praktikan.tasks.show', $report))->assertOk();
        $this->assertSame(1, AuditLog::where('action', 'task.viewed')->where('user_id', $viewer->id)->where('auditable_id', $report->id)->count());

        $this->actingAs($this->asisten);
        $this->get(route('asisten.grades.index', ['type' => 'laporan', 'class' => $this->class->id, 'target' => $report->id]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('asisten/grades/index')
                ->where('rows', fn ($rows) => collect($rows)->firstWhere('userId', $viewer->id)['viewed'] === true
                    && collect($rows)->firstWhere('userId', $viewer->id)['submission'] === null
                    && collect($rows)->firstWhere('userId', $this->submissions[2]->user_id)['viewed'] === false));
    }

    public function test_students_only_see_published_grades()
    {
        $draft = Grade::factory()->for($this->submissions[0])->create(['score' => 55, 'feedback' => 'rahasia']);
        $this->actingAs($this->submissions[0]->user);

        $cell = fn (Assert $page) => $page->component('praktikan/grades')->where('courses.0.final', null);
        $this->get(route('praktikan.grades.index'))->assertOk()->assertInertia($cell);

        $draft->forceFill(['status' => 'published', 'published_at' => now()])->save();
        $this->get(route('praktikan.grades.index'))
            ->assertInertia(fn (Assert $page) => $page->where('courses.0.final', 55)->where('courses.0.letter', 'C'));
    }

    public function test_final_score_is_a_normalised_weighted_average()
    {
        $course = $this->class->course;
        $make = function (TaskType $type, float $score, int $max = 100) use ($course) {
            $task = Task::factory()->create(['course_id' => $course->id, 'type' => $type, 'max_score' => $max]);
            $target = TaskTarget::factory()->for($task)->create(['class_id' => $this->class->id]);

            return Grade::factory()->published()->for(Submission::factory()->for($target, 'target'))->create(['score' => $score])
                ->load('submission.target.task');
        };

        // 20/30/50: pretest 80, laporan 45/50 = 90 → (80·20 + 90·50) / 70 = 87.1
        $result = FinalScore::compute($course, [$make(TaskType::Pretest, 80), $make(TaskType::Report, 45, 50)]);

        $this->assertSame(80.0, $result['pretest']);
        $this->assertNull($result['posttest']);
        $this->assertSame(87.1, $result['final']);
        $this->assertSame('A', $result['letter']);
    }
}
