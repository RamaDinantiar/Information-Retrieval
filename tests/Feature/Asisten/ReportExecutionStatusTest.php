<?php

namespace Tests\Feature\Asisten;

use App\Enums\CalendarEventType;
use App\Enums\ExecutionStatus;
use App\Models\LabClass;
use App\Models\OutsideClass;
use App\Models\Room;
use App\Models\Semester;
use App\Models\SupervisionReport;
use App\Models\User;
use App\Support\Reports\DailyActivityReport;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** FR-RJN-01/02/03: status pelaksanaan dan validasinya pada laporan ngawas. */
class ReportExecutionStatusTest extends TestCase
{
    use RefreshDatabase;

    private User $asisten;

    private Semester $semester;

    private LabClass $class;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('lab');
        $this->travelTo(CarbonImmutable::parse('2026-10-14 10:00')); // Rabu

        $this->semester = Semester::factory()->active()->create(['start_date' => '2026-09-01', 'end_date' => '2027-01-31']);
        // Kelas terjadwal Senin (day_of_week = 1).
        $this->class = LabClass::factory()->create(['semester_id' => $this->semester->id, 'room_id' => Room::factory()->create()->id]);
        $this->asisten = User::factory()->asisten()->create();
        $this->actingAs($this->asisten);
    }

    private function payload(array $overrides = []): array
    {
        return [
            'date' => '2026-10-12', 'start_time' => '07:20', 'end_time' => '09:50', // Senin
            'class_id' => $this->class->id, 'activity' => 'Jaga Lab', 'material' => 'OLAP',
            'notes' => 'Praktikum berjalan lancar tanpa kendala.', 'assistant_ids' => [$this->asisten->id],
            'photo' => UploadedFile::fake()->image('dokumentasi.jpg'),
            ...$overrides,
        ];
    }

    private function submitReport(array $overrides = [])
    {
        return $this->from('/')->post(route('asisten.reports.store'), $this->payload($overrides));
    }

    private function outsideClass(array $attributes = []): OutsideClass
    {
        $outside = $this->semester->outsideClasses()->create([
            'course_name' => 'Data Warehouse', 'class_name' => 'KR', 'credits' => 2, 'origin_lab' => 'Lab SI', 'day_of_week' => 2,
            ...$attributes,
        ]);
        $outside->assistants()->attach($this->asisten);

        return $outside;
    }

    public function test_held_report_defaults_and_is_saved()
    {
        $this->submitReport()->assertSessionHasNoErrors();

        $report = SupervisionReport::sole();
        $this->assertSame(ExecutionStatus::Held, $report->execution_status);
        $this->assertFalse($report->held_outside_lab);
    }

    public function test_held_on_the_wrong_weekday_is_rejected_with_a_helpful_message()
    {
        $this->submitReport(['date' => '2026-10-13'])->assertSessionHasErrors(['execution_status' => 'Kelas ini terjadwal hari Senin. Jika ini kelas pengganti atau tambahan, ubah status pelaksanaan.']);
        $this->assertSame(0, SupervisionReport::count());
    }

    public function test_held_on_sunday_or_a_national_holiday_is_rejected()
    {
        $this->class->forceFill(['day_of_week' => 7])->save();
        $this->submitReport(['date' => '2026-10-11'])->assertSessionHasErrors('execution_status'); // Minggu

        $this->class->forceFill(['day_of_week' => 1])->save();
        $this->semester->calendarEvents()->create(['date_from' => '2026-10-12', 'date_to' => '2026-10-12', 'type' => CalendarEventType::NationalHoliday, 'label' => 'Libur']);
        $this->submitReport()->assertSessionHasErrors('execution_status');

        $this->submitReport(['execution_status' => 'additional'])->assertSessionHasNoErrors();
        $this->assertSame(ExecutionStatus::Additional, SupervisionReport::sole()->execution_status);
    }

    public function test_uts_uas_period_does_not_block_a_held_class()
    {
        $this->semester->calendarEvents()->create(['date_from' => '2026-10-12', 'date_to' => '2026-10-16', 'type' => CalendarEventType::Uts, 'label' => 'UTS']);

        $this->submitReport()->assertSessionHasNoErrors();
    }

    public function test_replacement_needs_an_original_date_that_differs_from_the_report_date()
    {
        $this->submitReport(['execution_status' => 'replacement', 'date' => '2026-10-13'])->assertSessionHasErrors('original_date');
        $this->submitReport(['execution_status' => 'replacement', 'date' => '2026-10-13', 'original_date' => '2026-10-13'])->assertSessionHasErrors('original_date');

        $this->submitReport(['execution_status' => 'replacement', 'date' => '2026-10-13', 'original_date' => '2026-10-12', 'replacement_note' => 'Mulai 15.30'])->assertSessionHasNoErrors();

        $report = SupervisionReport::sole();
        $this->assertSame('2026-10-12', $report->original_date->toDateString());
        $this->assertSame('Mulai 15.30', $report->replacement_note);
    }

    public function test_exam_needs_a_type()
    {
        $this->submitReport(['execution_status' => 'exam'])->assertSessionHasErrors('exam_type');
        $this->submitReport(['execution_status' => 'exam', 'exam_type' => 'uts'])->assertSessionHasNoErrors();

        $this->assertSame('uts', SupervisionReport::sole()->exam_type);
    }

    public function test_irrelevant_fields_are_cleared_for_other_statuses()
    {
        $this->submitReport(['original_date' => '2026-10-05', 'exam_type' => 'uas', 'replacement_note' => 'x'])->assertSessionHasNoErrors();

        $report = SupervisionReport::sole();
        $this->assertNull($report->original_date);
        $this->assertNull($report->exam_type);
        $this->assertNull($report->replacement_note);
    }

    public function test_cancelled_report_needs_no_photo_and_is_allowed_on_holidays_and_off_days()
    {
        $this->semester->calendarEvents()->create(['date_from' => '2026-10-13', 'date_to' => '2026-10-13', 'type' => CalendarEventType::NationalHoliday, 'label' => 'Libur']);

        $this->submitReport(['execution_status' => 'cancelled', 'date' => '2026-10-13', 'photo' => null, 'notes' => 'Dosen berhalangan hadir.'])->assertSessionHasNoErrors();

        $this->assertSame(ExecutionStatus::Cancelled, SupervisionReport::sole()->execution_status);
        $this->assertSame(0.0, SupervisionReport::sole()->recapHours());
    }

    public function test_photo_is_still_required_for_a_held_class()
    {
        $this->submitReport(['photo' => null])->assertSessionHasErrors('photo');
    }

    public function test_class_and_outside_class_are_mutually_exclusive_and_one_is_required()
    {
        $outside = $this->outsideClass();

        $this->submitReport(['outside_class_id' => $outside->id])->assertSessionHasErrors('class_id');
        $this->submitReport(['class_id' => null])->assertSessionHasErrors('class_id');
    }

    public function test_an_outside_class_report_uses_the_outside_class_credits()
    {
        $outside = $this->outsideClass(['day_of_week' => 1]);

        $this->submitReport(['class_id' => null, 'outside_class_id' => $outside->id])->assertSessionHasNoErrors();

        $report = SupervisionReport::sole();
        $this->assertNull($report->class_id);
        $this->assertSame($outside->id, $report->outside_class_id);
        $this->assertEqualsWithDelta(5 / 3, $report->recapHours(), 1e-9);
    }

    public function test_outside_class_weekday_is_only_checked_when_it_has_a_schedule()
    {
        $this->submitReport(['class_id' => null, 'outside_class_id' => $this->outsideClass(['day_of_week' => 2])->id])
            ->assertSessionHasErrors('execution_status');

        SupervisionReport::query()->delete();
        $this->submitReport(['class_id' => null, 'outside_class_id' => $this->outsideClass(['class_name' => 'B', 'day_of_week' => null])->id])
            ->assertSessionHasNoErrors();
    }

    public function test_an_outside_class_belonging_to_another_assistant_is_rejected()
    {
        $outside = $this->semester->outsideClasses()->create(['course_name' => 'Jaringan', 'class_name' => 'A', 'credits' => 3, 'origin_lab' => 'Lab Jarkom']);
        $outside->assistants()->attach(User::factory()->asisten()->create());

        $this->submitReport(['class_id' => null, 'outside_class_id' => $outside->id, 'execution_status' => 'additional'])->assertSessionHasErrors('outside_class_id');
    }

    public function test_held_outside_the_lab_room_requires_a_location()
    {
        $this->submitReport(['held_outside_lab' => true])->assertSessionHasErrors('outside_location');
        $this->submitReport(['held_outside_lab' => true, 'outside_location' => 'Ruang 4.2'])->assertSessionHasNoErrors();

        $report = SupervisionReport::sole();
        $this->assertTrue($report->held_outside_lab);
        $this->assertSame('Ruang 4.2', $report->outside_location);
    }

    public function test_duplicate_report_for_same_assistant_class_and_date_is_rejected_unless_cancelled()
    {
        $this->submitReport()->assertSessionHasNoErrors();
        $this->submitReport()->assertSessionHasErrors('class_id');
        $this->assertSame(1, SupervisionReport::count());

        // Laporan ditiadakan tidak dihitung duplikat, dan tidak menghalangi laporan lain.
        SupervisionReport::query()->delete();
        $this->submitReport(['execution_status' => 'cancelled', 'photo' => null])->assertSessionHasNoErrors();
        $this->submitReport()->assertSessionHasNoErrors();
        $this->assertSame(2, SupervisionReport::count());
    }

    public function test_editing_a_report_is_not_flagged_as_its_own_duplicate()
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-13 10:00')); // masih dalam batas H+1
        $this->submitReport()->assertSessionHasNoErrors();
        $report = SupervisionReport::sole();

        $this->from('/')->post(route('asisten.reports.update', $report), $this->payload(['photo' => null, 'notes' => 'Catatan yang diperbarui lagi.']))
            ->assertSessionHasNoErrors();
    }

    public function test_another_team_member_cannot_duplicate_the_same_class_session()
    {
        $partner = User::factory()->asisten()->create();
        $this->submitReport(['assistant_ids' => [$this->asisten->id, $partner->id]])->assertSessionHasNoErrors();

        $this->actingAs($partner)->post(route('asisten.reports.store'), $this->payload(['assistant_ids' => [$partner->id]]))
            ->assertSessionHasErrors('class_id');
    }

    public function test_word_description_gets_a_status_prefix()
    {
        $make = fn (array $a) => new SupervisionReport(['execution_status' => 'held', 'held_outside_lab' => false, ...$a]);

        $this->assertSame('', DailyActivityReport::statusPrefix($make([])));
        $this->assertSame('[Kelas ditiadakan] ', DailyActivityReport::statusPrefix($make(['execution_status' => 'cancelled'])));
        $this->assertSame('[Kelas pengganti] ', DailyActivityReport::statusPrefix($make(['execution_status' => 'replacement'])));
        $this->assertSame('[Ujian UTS] ', DailyActivityReport::statusPrefix($make(['execution_status' => 'exam', 'exam_type' => 'uts'])));
        $this->assertSame('[Di luar ruang Lab IR: Ruang 4.2] ', DailyActivityReport::statusPrefix($make(['held_outside_lab' => true, 'outside_location' => 'Ruang 4.2'])));
    }
}
