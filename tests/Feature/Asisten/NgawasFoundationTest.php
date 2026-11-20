<?php

namespace Tests\Feature\Asisten;

use App\Enums\ExecutionStatus;
use App\Enums\Position;
use App\Models\Course;
use App\Models\LabClass;
use App\Models\OutsideClass;
use App\Models\Semester;
use App\Models\SupervisionReport;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\NgawasRecapSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NgawasFoundationTest extends TestCase
{
    use RefreshDatabase;

    private function labReport(int $credits, ExecutionStatus $status = ExecutionStatus::Held): SupervisionReport
    {
        $semester = Semester::factory()->active()->create();
        $course = Course::factory()->for($semester)->create(['credits' => $credits]);
        $class = LabClass::factory()->create(['course_id' => $course->id, 'semester_id' => $semester->id]);

        return SupervisionReport::factory()->create([
            'semester_id' => $semester->id, 'class_id' => $class->id, 'execution_status' => $status,
        ])->fresh();
    }

    public function test_recap_hours_follow_credits_not_clock_time()
    {
        // Jam mulai–selesai di factory 13:00–16:00 (3 jam) tidak dipakai.
        $this->assertEqualsWithDelta(5 / 3, $this->labReport(2)->recapHours(), 1e-9);
        $this->assertEqualsWithDelta(2.5, $this->labReport(3)->recapHours(), 1e-9);
        $this->assertSame(3.0, $this->labReport(2)->hours());
    }

    public function test_cancelled_report_yields_zero_hours()
    {
        $this->assertSame(0.0, $this->labReport(3, ExecutionStatus::Cancelled)->recapHours());
    }

    public function test_legacy_report_defaults_to_held_inside_lab()
    {
        $report = $this->labReport(3);

        $this->assertSame(ExecutionStatus::Held, $report->execution_status);
        $this->assertFalse($report->held_outside_lab);
    }

    public function test_outside_class_credits_are_used_when_report_points_to_it()
    {
        $semester = Semester::factory()->active()->create();
        $outside = $semester->outsideClasses()->create([
            'course_name' => 'Data Warehouse', 'class_name' => 'KR',
            'credits' => 2, 'origin_lab' => 'Lab SI',
        ]);
        $report = SupervisionReport::factory()->create(['semester_id' => $semester->id, 'outside_class_id' => $outside->id])->fresh();

        $this->assertSame(2, $report->credits());
        $this->assertEqualsWithDelta(5 / 3, $report->recapHours(), 1e-9);
    }

    public function test_report_without_any_class_has_no_credits()
    {
        $report = SupervisionReport::factory()->create()->fresh();

        $this->assertNull($report->credits());
        $this->assertSame(0.0, $report->recapHours());
    }

    public function test_execution_status_labels_and_hour_counting()
    {
        foreach (ExecutionStatus::cases() as $status) {
            $this->assertNotSame('', $status->label());
            $this->assertSame($status !== ExecutionStatus::Cancelled, $status->countsHours());
        }
    }

    public function test_only_secretary_treasurer_and_admin_manage_recap()
    {
        $this->assertTrue(User::factory()->asisten(Position::SekretarisBendahara)->make()->canManageNgawasRecap());
        $this->assertTrue(User::factory()->admin()->make()->canManageNgawasRecap());
        $this->assertFalse(User::factory()->asisten()->make()->canManageNgawasRecap());
    }

    public function test_semester_has_calendar_events_and_recap_header_fields()
    {
        $semester = Semester::factory()->create(['sk_number' => '03/SK/Genap/IRLab/2026', 'term_label' => 'SEMESTER GENAP TAHUN 2025/2026']);
        $semester->calendarEvents()->create([
            'date_from' => '2026-04-03', 'date_to' => '2026-04-03',
            'type' => 'national_holiday', 'label' => 'Wafat Isa Almasih',
        ]);

        $this->assertSame('03/SK/Genap/IRLab/2026', $semester->fresh()->sk_number);
        $this->assertCount(1, $semester->calendarEvents);
    }

    public function test_ngawas_seeder_is_idempotent_and_varies_credits()
    {
        $this->seed(DatabaseSeeder::class);
        $this->seed(NgawasRecapSeeder::class);

        $semester = Semester::current();
        $this->assertNotNull($semester->sk_number);
        $this->assertSame(3, $semester->calendarEvents()->count());
        $this->assertSame(1, OutsideClass::count());
        $this->assertSame(2, Course::where('semester_id', $semester->id)->where('code', 'IF-2104')->value('credits'));
        $this->assertSame(3, Course::where('semester_id', $semester->id)->where('code', 'IF-3101')->value('credits'));
        $this->assertSame(1, OutsideClass::first()->assistants()->count());
    }
}
