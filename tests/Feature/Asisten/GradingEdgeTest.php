<?php

namespace Tests\Feature\Asisten;

use App\Events\GradesPublished;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\Grade;
use App\Models\LabClass;
use App\Models\Semester;
use App\Models\Submission;
use App\Models\Task;
use App\Models\TaskTarget;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

/**
 * Audit 03 P1-5: batas nilai dan publikasi.
 */
class GradingEdgeTest extends TestCase
{
    use RefreshDatabase;

    private User $asisten;

    private LabClass $class;

    private TaskTarget $target;

    private Submission $submission;

    protected function setUp(): void
    {
        parent::setUp();

        $this->asisten = User::factory()->asisten()->create();
        $course = Course::factory()->for(Semester::factory()->active())->create();
        $course->assistants()->attach($this->asisten);
        $this->class = LabClass::factory()->create(['course_id' => $course->id, 'semester_id' => $course->semester_id]);
        $this->class->assistants()->attach($this->asisten);
        $task = Task::factory()->create(['course_id' => $course->id, 'max_score' => 80]);
        $this->target = TaskTarget::factory()->for($task)->create(['class_id' => $this->class->id, 'open_at' => now()->subDay(), 'due_at' => now()->addDay()]);
        $this->submission = Submission::factory()->for($this->target, 'target')->for(Enrollment::factory()->for($this->class, 'labClass')->create()->user)->create();

        $this->actingAs($this->asisten);
    }

    private function save(mixed $score, ?string $feedback = null)
    {
        return $this->patch(route('asisten.grades.batch'), [
            'target_id' => $this->target->id,
            'grades' => [['submission_id' => $this->submission->id, 'score' => $score, 'feedback' => $feedback, 'version' => null]],
        ]);
    }

    public function test_scores_outside_zero_to_the_task_maximum_are_rejected(): void
    {
        foreach (['-1', '-0.1', '80.01', '81', '1000', 'abc', '1e400', '8e1x'] as $bad) {
            $this->save($bad)->assertSessionHasErrors('grades.0.score');
        }

        $this->assertSame(0, Grade::count());
    }

    public function test_the_boundaries_and_decimals_are_accepted(): void
    {
        foreach (['0', '80', '79.5', 42, 0.5] as $score) {
            $grade = Grade::where('submission_id', $this->submission->id)->first();

            $this->patch(route('asisten.grades.batch'), [
                'target_id' => $this->target->id,
                'grades' => [['submission_id' => $this->submission->id, 'score' => $score, 'feedback' => null, 'version' => $grade?->version]],
            ])->assertSessionHasNoErrors();

            $this->assertEquals((float) $score, (float) Grade::where('submission_id', $this->submission->id)->value('score'));
        }
    }

    public function test_an_empty_score_clears_the_grade_instead_of_saving_zero(): void
    {
        $this->save('50')->assertSessionHasNoErrors();
        $version = Grade::sole()->version;

        $this->patch(route('asisten.grades.batch'), [
            'target_id' => $this->target->id,
            'grades' => [['submission_id' => $this->submission->id, 'score' => '', 'feedback' => null, 'version' => $version]],
        ])->assertSessionHasNoErrors();

        $this->assertNull(Grade::sole()->score);
    }

    public function test_an_oversized_feedback_is_rejected_and_blank_feedback_is_stored_as_null(): void
    {
        $this->save('50', str_repeat('a', 5001))->assertSessionHasErrors('grades.0.feedback');
        $this->assertSame(0, Grade::count());

        $this->save('50', "   \n ")->assertSessionHasNoErrors();
        $this->assertNull(Grade::sole()->feedback);
    }

    public function test_publishing_twice_notifies_only_once(): void
    {
        Event::fake([GradesPublished::class]);
        Grade::factory()->for($this->submission)->create(['score' => 70]);

        $this->post(route('asisten.grading.publish', $this->target))->assertRedirect();
        $this->post(route('asisten.grading.publish', $this->target))->assertRedirect();

        Event::assertDispatchedTimes(GradesPublished::class, 1);
        $this->assertTrue($this->submission->grade()->first()->isPublished());
    }

    public function test_publishing_one_target_does_not_publish_grades_of_another_target(): void
    {
        $otherTarget = TaskTarget::factory()->for(Task::factory()->create(['course_id' => $this->target->task->course_id]))
            ->create(['class_id' => $this->class->id, 'open_at' => now()->subDay(), 'due_at' => now()->addDay()]);
        $otherSubmission = Submission::factory()->for($otherTarget, 'target')->for($this->submission->user)->create();
        Grade::factory()->for($this->submission)->create(['score' => 70]);
        Grade::factory()->for($otherSubmission)->create(['score' => 60]);

        $this->post(route('asisten.grading.publish', $this->target));

        $this->assertTrue($this->submission->grade()->first()->isPublished());
        $this->assertFalse($otherSubmission->grade()->first()->isPublished());
    }

    public function test_only_the_assistants_of_the_class_may_publish_and_archived_classes_are_read_only(): void
    {
        Grade::factory()->for($this->submission)->create(['score' => 70]);

        $this->actingAs(User::factory()->asisten()->create())->post(route('asisten.grading.publish', $this->target))->assertForbidden();
        $this->actingAs(User::factory()->create())->post(route('asisten.grading.publish', $this->target))->assertForbidden();
        $this->assertFalse($this->submission->grade()->first()->isPublished());

        $this->class->semester->forceFill(['status' => 'archived'])->save();
        $this->actingAs($this->asisten)->post(route('asisten.grading.publish', $this->target))->assertForbidden();
        $this->save('10')->assertForbidden();
    }

    public function test_a_student_never_sees_a_draft_grade_or_feedback(): void
    {
        Grade::factory()->for($this->submission)->create(['score' => 77, 'feedback' => 'RAHASIA-DRAFT']);

        $response = $this->actingAs($this->submission->user)->get(route('praktikan.grades.index'))->assertOk();

        $this->assertStringNotContainsString('RAHASIA-DRAFT', $response->getContent());
        $this->assertStringNotContainsString('"score":77', $response->getContent());

        $dash = $this->get(route('praktikan.dashboard'))->assertOk();
        $this->assertStringNotContainsString('RAHASIA-DRAFT', $dash->getContent());
    }
}
