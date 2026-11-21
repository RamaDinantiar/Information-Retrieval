<?php

namespace Tests\Feature\Asisten;

use App\Models\Course;
use App\Models\Enrollment;
use App\Models\LabClass;
use App\Models\Semester;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

/**
 * Audit 03 P1-3: batas gabung kelas dan impor anggota.
 */
class EnrollmentEdgeTest extends TestCase
{
    use RefreshDatabase;

    private Semester $semester;

    private Course $course;

    private LabClass $class;

    private User $asisten;

    protected function setUp(): void
    {
        parent::setUp();

        $this->semester = Semester::factory()->active()->create();
        $this->course = Course::factory()->for($this->semester)->create();
        $this->class = LabClass::factory()->create(['course_id' => $this->course->id, 'semester_id' => $this->semester->id, 'join_token' => 'ABC234', 'quota' => 2]);
        $this->asisten = User::factory()->asisten()->create();
        $this->class->assistants()->attach($this->asisten);
    }

    private function join(User $student, string $token = 'ABC234')
    {
        return $this->actingAs($student)->post(route('praktikan.enrollments.store'), ['token' => $token]);
    }

    private function import(string $csv, ?LabClass $class = null)
    {
        return $this->actingAs($this->asisten)
            ->post(route('asisten.classes.members.import', $class ?? $this->class), ['file' => UploadedFile::fake()->createWithContent('anggota.csv', $csv)]);
    }

    public function test_the_last_seat_goes_to_the_first_student_and_the_next_is_refused_without_exceeding_the_quota(): void
    {
        $students = User::factory()->count(4)->create();

        foreach ($students as $student) {
            $this->join($student);
        }

        $this->assertSame(2, $this->class->enrollments()->count());
        $this->assertTrue($this->class->fresh()->is_locked);
        $this->assertTrue($students[0]->enrollments()->exists());
        $this->assertTrue($students[1]->enrollments()->exists());
        $this->assertFalse($students[2]->enrollments()->exists());
        $this->join($students[3])->assertSessionHasErrors(['token' => 'Kelas sudah ditutup, hubungi asisten.']);
    }

    public function test_a_token_of_a_class_that_is_still_a_proposal_cannot_be_used(): void
    {
        $pending = LabClass::factory()->pending()->create(['course_id' => $this->course->id, 'semester_id' => $this->semester->id, 'join_token' => 'XYZ234']);

        $this->join(User::factory()->create(), 'XYZ234')->assertSessionHasErrors(['token' => 'Kode kelas tidak ditemukan.']);
        $this->assertSame(0, $pending->enrollments()->count());
    }

    public function test_tokens_with_ambiguous_or_wrong_length_never_reach_the_database_lookup(): void
    {
        $student = User::factory()->create();

        foreach (['ABC23', 'ABC2345', 'ABC230', 'ABC23I', 'ABC 34', '', "ABC23\n4"] as $token) {
            $this->join($student, $token)->assertSessionHasErrors('token');
        }

        $this->assertSame(0, $this->class->enrollments()->count());
    }

    public function test_import_adds_only_the_valid_row_and_counts_the_rest_as_failed(): void
    {
        $assistantAccount = User::factory()->asisten()->create(['nim' => '202300009']);
        $dupe = User::factory()->create(['nim' => '202300010']);

        $this->import("nim\n\n202300009\n999999999\n202300010\n202300010\n")->assertSessionHasNoErrors()
            ->assertInertiaFlash('importResult.created', 1)
            ->assertInertiaFlash('importResult.total', 4);

        // Baris kosong dilewati; NIM asisten, NIM tak dikenal, dan duplikat dilaporkan; satu yang sah masuk.
        $this->assertSame([$dupe->id], $this->class->enrollments()->pluck('user_id')->all());
    }

    public function test_import_stops_adding_when_the_quota_is_full_and_says_so(): void
    {
        $students = collect(range(1, 4))->map(fn ($i) => User::factory()->create(['nim' => '2023001'.$i]));
        $csv = "nim\n".$students->pluck('nim')->implode("\n")."\n";

        $this->import($csv)->assertInertiaFlash('importResult.created', 2)->assertInertiaFlash('importResult.total', 4);

        $this->assertSame(2, $this->class->enrollments()->count());
        $this->assertFalse($students[2]->enrollments()->exists());
        $this->assertFalse($students[3]->enrollments()->exists());
    }

    public function test_import_restores_a_removed_member_instead_of_failing(): void
    {
        $student = User::factory()->create(['nim' => '202300020']);
        $enrollment = Enrollment::factory()->for($this->class, 'labClass')->for($student)->create();
        $enrollment->delete();

        $this->import("nim\n202300020\n");

        $this->assertSame(1, $this->class->enrollments()->count());
        $this->assertNull($enrollment->fresh()?->deleted_at);
    }

    public function test_import_is_refused_for_a_class_of_another_assistant_and_for_an_archived_semester(): void
    {
        User::factory()->create(['nim' => '202300030']);
        $archived = Semester::factory()->archived()->create();
        $oldClass = LabClass::factory()->create(['semester_id' => $archived->id]);
        $oldClass->assistants()->attach($this->asisten);
        $foreign = LabClass::factory()->create(['semester_id' => $this->semester->id]);

        $this->import("nim\n202300030\n", $foreign)->assertForbidden();
        $this->import("nim\n202300030\n", $oldClass)->assertForbidden();

        $this->assertSame(0, $foreign->enrollments()->count() + $oldClass->enrollments()->count());
    }

    public function test_import_rejects_a_file_without_the_nim_column_or_with_the_wrong_type(): void
    {
        $this->import("nama\nRina\n")->assertSessionHasErrors();

        $this->actingAs($this->asisten)->post(route('asisten.classes.members.import', $this->class), [
            'file' => UploadedFile::fake()->create('anggota.pdf', 10, 'application/pdf'),
        ])->assertSessionHasErrors('file');

        $this->assertSame(0, $this->class->enrollments()->count());
    }

    // --- Audit 03 N11: kelas terkunci ---

    public function test_the_assistant_import_still_works_when_the_class_is_locked_while_the_token_does_not(): void
    {
        $this->class->forceFill(['is_locked' => true])->save();
        $student = User::factory()->create(['nim' => '2023999001']);

        $this->join($student)->assertSessionHasErrors();
        $this->assertSame(0, $this->class->enrollments()->where('user_id', $student->id)->count());

        $this->import("nim\n2023999001\n")->assertSessionHasNoErrors();
        $this->assertSame(1, $this->class->enrollments()->where('user_id', $student->id)->count());
    }

    public function test_the_class_page_tells_the_import_dialog_when_the_class_is_locked(): void
    {
        foreach ([true, false] as $locked) {
            $this->class->forceFill(['is_locked' => $locked])->save();

            $this->actingAs($this->asisten)->get(route('asisten.class.show', $this->class))
                ->assertInertia(fn ($page) => $page->where('klass.isLocked', $locked));
        }
    }
}
