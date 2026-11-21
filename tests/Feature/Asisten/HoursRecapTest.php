<?php

namespace Tests\Feature\Asisten;

use App\Enums\ExecutionStatus;
use App\Models\Course;
use App\Models\LabClass;
use App\Models\OutsideClass;
use App\Models\Semester;
use App\Models\SupervisionReport;
use App\Models\User;
use App\Support\Reports\HoursRecap;
use App\Support\Reports\NgawasRecap;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SeedsNgawasGoldenFixture;
use Tests\TestCase;

/** FR-RJN-16: rekap jam lama memakai rumus SKS yang sama dengan Rekap Jam Ngawas. */
class HoursRecapTest extends TestCase
{
    use RefreshDatabase;
    use SeedsNgawasGoldenFixture;

    protected function tearDown(): void
    {
        gc_collect_cycles();
        parent::tearDown();
    }

    private function report(User $user, array $attrs): SupervisionReport
    {
        $report = SupervisionReport::factory()->create([
            'user_id' => $user->id, 'status' => 'verified', 'start_time' => '07:00', 'end_time' => '12:00', ...$attrs,
        ]);
        $report->assistants()->sync([$user->id => ['position' => 1]]);

        return $report;
    }

    private function lab(Semester $semester, int $credits, string $name = 'Basis Data'): LabClass
    {
        $course = Course::factory()->for($semester)->create(['name' => $name, 'credits' => $credits]);

        return LabClass::factory()->for($course)->create(['semester_id' => $semester->id]);
    }

    public function test_hours_do_not_depend_on_clock_time_and_cancelled_classes_are_skipped()
    {
        $semester = Semester::factory()->active()->create();
        $user = User::factory()->asisten()->create();
        $class = $this->lab($semester, 3);

        $this->report($user, ['semester_id' => $semester->id, 'class_id' => $class->id, 'date' => '2026-10-02']);
        $this->report($user, ['semester_id' => $semester->id, 'class_id' => $class->id, 'date' => '2026-10-09', 'execution_status' => ExecutionStatus::Cancelled]);

        $row = HoursRecap::forMonth('2026-10')[0];

        $this->assertSame(1, $row['shifts']);
        $this->assertSame(3, $row['hours']); // 2,5 dibulatkan setengah ke atas
        $this->assertSame(2.5, $row['hoursRaw']);
        $this->assertSame(2.5, $row['courses'][0]['hours']);
    }

    public function test_the_total_is_rounded_per_assistant_not_per_session()
    {
        $semester = Semester::factory()->active()->create();
        $user = User::factory()->asisten()->create();
        $class = $this->lab($semester, 2);

        foreach (['2026-10-02', '2026-10-09', '2026-10-16'] as $date) {
            $this->report($user, ['semester_id' => $semester->id, 'class_id' => $class->id, 'date' => $date]);
        }

        $row = HoursRecap::forMonth('2026-10')[0];

        $this->assertSame(5, $row['hours']); // 3 × 1,6667 = 5,0
        $this->assertSame(5.0, $row['hoursRaw']);
        $this->assertSame(5.0, $row['courses'][0]['hours']);
    }

    public function test_outside_lab_classes_use_their_own_credits_and_reports_without_a_class_give_zero_hours()
    {
        $semester = Semester::factory()->active()->create();
        $user = User::factory()->asisten()->create();
        $outside = OutsideClass::forceCreate(['semester_id' => $semester->id, 'course_name' => 'Jaringan Komputer', 'class_name' => 'A', 'credits' => 3, 'origin_lab' => 'Lab Jaringan']);

        $this->report($user, ['semester_id' => $semester->id, 'class_id' => null, 'outside_class_id' => $outside->id, 'date' => '2026-10-02']);
        $this->report($user, ['semester_id' => $semester->id, 'class_id' => null, 'outside_class_id' => null, 'date' => '2026-10-03']);

        $row = HoursRecap::forMonth('2026-10')[0];
        $courses = collect($row['courses'])->keyBy('name');

        $this->assertSame(2, $row['shifts']);
        $this->assertSame(2.5, $courses['Jaringan Komputer']['hours']);
        $this->assertSame(3, $courses['Jaringan Komputer']['credits']);
        $this->assertSame(0.0, $courses['Kegiatan lain']['hours']);
        $this->assertNull($courses['Kegiatan lain']['credits']);
    }

    public function test_totals_match_the_campus_recap_for_april_2026()
    {
        $this->travelTo(CarbonImmutable::parse('2026-06-01 10:00'));
        $this->seedFromFixture();

        $campus = collect(NgawasRecap::build($this->semester, '2026-04')['assistants'])->where('hoursRaw', '>', 0)->pluck('hours', 'userId')->all();
        $legacy = collect(HoursRecap::forMonth('2026-04'))->where('hoursRaw', '>', 0)->pluck('hours', 'userId')->all();

        ksort($campus);
        ksort($legacy);

        $this->assertSame($campus, $legacy);
        $this->assertSame(495, array_sum($legacy));
    }
}
