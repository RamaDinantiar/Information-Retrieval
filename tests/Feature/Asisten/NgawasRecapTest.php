<?php

namespace Tests\Feature\Asisten;

use App\Enums\CalendarEventType;
use App\Enums\Position;
use App\Models\Course;
use App\Models\LabClass;
use App\Models\NgawasRecapOrder;
use App\Models\OutsideClass;
use App\Models\Semester;
use App\Models\SupervisionReport;
use App\Models\User;
use App\Support\Reports\NgawasRecap;
use App\Support\Reports\NgawasRecapChecks;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/** Mesin rekap, pemeriksaan, dan halaman pratinjau Rekap Jam Ngawas (FR-RJN-05/06/07/13). */
class NgawasRecapTest extends TestCase
{
    use RefreshDatabase;

    private Semester $semester;

    private User $rama;

    private User $dina;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-06-01 10:00'));

        $this->semester = Semester::factory()->active()->create([
            'name' => 'Genap 2025/2026', 'start_date' => '2026-02-16', 'end_date' => '2026-06-19',
            'sk_number' => '03/SK/Genap/IRLab/2026', 'term_label' => 'SEMESTER GENAP TAHUN 2025/2026',
        ]);
        $this->rama = User::factory()->asisten()->create(['name' => 'Rama Dinantiar', 'nim' => '202331044']);
        $this->dina = User::factory()->asisten()->create(['name' => 'Dina Aulia', 'nim' => '2023-32-010']);
    }

    private function lab(string $course, string $name, int $credits, int $day, array $team, string $start = '07:20'): LabClass
    {
        $courseModel = Course::factory()->for($this->semester)->create(['name' => $course, 'credits' => $credits]);
        $class = LabClass::factory()->create([
            'semester_id' => $this->semester->id, 'course_id' => $courseModel->id, 'name' => $name,
            'day_of_week' => $day, 'start_time' => $start, 'end_time' => '09:50',
        ]);
        $class->assistants()->attach(collect($team)->pluck('id'));

        return $class;
    }

    /**
     * @param  list<User>  $team
     */
    private function report(LabClass|OutsideClass $class, string $date, array $team, array $attributes = []): SupervisionReport
    {
        $report = SupervisionReport::factory()->create([
            'semester_id' => $this->semester->id, 'user_id' => $team[0]->id, 'date' => $date,
            'start_time' => '07:20', 'end_time' => '09:50', 'status' => 'verified',
            $class instanceof LabClass ? 'class_id' : 'outside_class_id' => $class->id,
            ...$attributes,
        ]);
        $report->assistants()->sync(collect($team)->mapWithKeys(fn (User $u, $i) => [$u->id => ['position' => $i + 1]])->all());

        return $report;
    }

    private function outside(array $team, array $attributes = []): OutsideClass
    {
        $outside = $this->semester->outsideClasses()->create([
            'course_name' => 'Data Warehouse', 'class_name' => 'KR', 'credits' => 2, 'origin_lab' => 'Lab SI', ...$attributes,
        ]);
        $outside->assistants()->attach(collect($team)->pluck('id'));

        return $outside;
    }

    /** @return array<string, mixed> */
    private function build(string $month = '2026-04', ?int $only = null): array
    {
        return NgawasRecap::build($this->semester, $month, $only);
    }

    private function row(array $recap, User $user, int $index = 0): array
    {
        return collect($recap['assistants'])->firstWhere('userId', $user->id)['rows'][$index];
    }

    public function test_cell_hours_follow_credits_not_clock_time()
    {
        $two = $this->lab('Data Warehouse', 'Kelas A', 2, 1, [$this->rama]);
        $three = $this->lab('Teknologi Blockchain', 'Kelas C', 3, 2, [$this->rama]);
        $this->report($two, '2026-04-06', [$this->rama], ['end_time' => '16:00']); // durasi jam mulai–selesai tidak dipakai
        $this->report($three, '2026-04-07', [$this->rama]);

        $recap = $this->build();

        $rows = collect($recap['assistants'])->firstWhere('userId', $this->rama->id)['rows'];
        $this->assertEqualsWithDelta(5 / 3, collect($rows)->firstWhere('label', 'DATA WAREHOUSE (A)')['cells'][6]['hours'], 1e-9);
        $this->assertEqualsWithDelta(2.5, collect($rows)->firstWhere('label', 'TEKNOLOGI BLOCKCHAIN (C)')['cells'][7]['hours'], 1e-9);
    }

    public function test_cancelled_cell_is_coloured_but_has_no_hours_and_is_not_counted()
    {
        $class = $this->lab('Data Warehouse', 'Kelas A', 2, 1, [$this->rama]);
        $this->report($class, '2026-04-06', [$this->rama], ['execution_status' => 'cancelled']);
        $this->report($class, '2026-04-13', [$this->rama]);

        $row = $this->row($this->build(), $this->rama);

        $this->assertSame('cancelled', $row['cells'][6]['kind']);
        $this->assertSame(0.0, $row['cells'][6]['hours']);
        $this->assertFalse($row['cells'][6]['counts']);
        $this->assertSame(1, $row['totalDays']);
        $this->assertEqualsWithDelta(5 / 3, $row['hours'], 1e-9);
    }

    public function test_only_verified_reports_count()
    {
        $class = $this->lab('Data Warehouse', 'Kelas A', 2, 1, [$this->rama]);
        foreach (['draft', 'submitted', 'returned'] as $i => $status) {
            $this->report($class, '2026-04-0'.($i + 1), [$this->rama], ['status' => $status]);
        }

        $this->assertSame([], $this->row($this->build(), $this->rama)['cells']);
    }

    public function test_every_team_member_gets_their_own_cell()
    {
        $class = $this->lab('Data Warehouse', 'Kelas A', 2, 1, [$this->rama, $this->dina]);
        $this->report($class, '2026-04-06', [$this->rama, $this->dina]);

        $recap = $this->build();

        $this->assertArrayHasKey(6, $this->row($recap, $this->rama)['cells']);
        $this->assertArrayHasKey(6, $this->row($recap, $this->dina)['cells']);
    }

    public function test_replacement_counts_in_the_month_it_was_held()
    {
        $class = $this->lab('Data Warehouse', 'Kelas B', 2, 1, [$this->rama]);
        $this->report($class, '2026-04-02', [$this->rama], [
            'execution_status' => 'replacement', 'original_date' => '2026-03-30', 'replacement_note' => 'Menjadi dimulai jam 15.30',
        ]);

        $april = $this->build('2026-04');
        $march = $this->build('2026-03');

        $this->assertSame('replacement', $this->row($april, $this->rama)['cells'][2]['kind']);
        $this->assertSame([], $this->row($march, $this->rama)['cells']);
        $this->assertSame('Pengganti dari Kelas Senin, 30 Maret 2026, Menjadi dimulai jam 15.30', $april['replacements'][0]['note']);
    }

    public function test_replacement_and_additional_tables_use_the_campus_formats()
    {
        $class = $this->lab('Teknologi Blockchain', 'Kelas C', 3, 1, [$this->rama]);
        $this->report($class, '2026-04-30', [$this->rama], ['execution_status' => 'replacement', 'original_date' => '2026-04-27']);
        $this->report($class, '2026-04-21', [$this->rama], ['execution_status' => 'additional', 'start_time' => '07:10', 'end_time' => '09:50']);

        $recap = $this->build();

        $this->assertSame(
            ['date' => '2026-04-30', 'class' => 'Teknologi Blockchain C', 'time' => '07.20 - 09.50', 'note' => 'Pengganti dari Kelas Senin, 27 April 2026'],
            $recap['replacements'][0],
        );
        $this->assertSame('07.10 - 09.50', $recap['additional'][0]['time']);
    }

    public function test_duplicates_are_reported_and_counted_once()
    {
        $class = $this->lab('Data Warehouse', 'Kelas A', 2, 1, [$this->rama]);
        $first = $this->report($class, '2026-04-06', [$this->rama]);
        $second = $this->report($class, '2026-04-06', [$this->rama]);

        $recap = $this->build();

        $this->assertCount(1, $recap['duplicates']);
        $this->assertSame([$first->id, $second->id], $recap['duplicates'][0]['reportIds']);
        $this->assertSame(1, $this->row($recap, $this->rama)['totalDays']);
    }

    public function test_assistants_without_reports_or_classes_still_appear_with_a_dash_row()
    {
        $lonely = User::factory()->asisten()->create(['name' => 'Zed Kosong']);
        $this->lab('Data Warehouse', 'Kelas A', 2, 1, [$this->rama]);

        $recap = $this->build();

        $this->assertCount(3, $recap['assistants']);
        $row = $this->row($recap, $lonely);
        $this->assertSame('-', $row['label']);
        $this->assertTrue($row['placeholder']);
        $this->assertSame([], $row['cells']);
        $this->assertSame(0, collect($recap['assistants'])->firstWhere('userId', $lonely->id)['hours']);
    }

    public function test_inactive_assistants_without_reports_are_left_out_but_with_reports_are_kept()
    {
        $inactive = User::factory()->asisten()->create(['is_active' => false, 'name' => 'Nonaktif']);
        $this->assertNull(collect($this->build()['assistants'])->firstWhere('userId', $inactive->id));

        $class = $this->lab('Data Warehouse', 'Kelas A', 2, 1, [$inactive]);
        $this->report($class, '2026-04-06', [$inactive]);

        $this->assertNotNull(collect($this->build()['assistants'])->firstWhere('userId', $inactive->id));
    }

    public function test_outside_class_and_outside_room_cells_are_orange_with_the_right_priority()
    {
        $outside = $this->outside([$this->rama], ['day_of_week' => 1]);
        $lab = $this->lab('Data Warehouse', 'Kelas A', 2, 1, [$this->rama]);

        $this->report($outside, '2026-04-06', [$this->rama]);
        $this->report($lab, '2026-04-06', [$this->rama], ['held_outside_lab' => true, 'outside_location' => 'Ruang 4.2']);
        $this->report($lab, '2026-04-13', [$this->rama], ['held_outside_lab' => true, 'execution_status' => 'replacement', 'original_date' => '2026-04-10']);
        $this->report($lab, '2026-04-20', [$this->rama], ['held_outside_lab' => true, 'execution_status' => 'cancelled']);
        $this->report($lab, '2026-04-27', [$this->rama], ['held_outside_lab' => true, 'execution_status' => 'exam', 'exam_type' => 'uts']);

        $rows = collect($this->build()['assistants'])->firstWhere('userId', $this->rama->id)['rows'];
        $out = collect($rows)->firstWhere('key', 'o'.$outside->id);
        $labRow = collect($rows)->firstWhere('key', 'c'.$lab->id);

        $this->assertSame('outside', $out['cells'][6]['kind']);
        $this->assertSame('outside', $labRow['cells'][6]['kind']);
        $this->assertSame('outside', $labRow['cells'][13]['kind']); // lebih tinggi dari Pengganti
        $this->assertSame('cancelled', $labRow['cells'][20]['kind']); // Ditiadakan tertinggi
        $this->assertSame('exam', $labRow['cells'][27]['kind']);
        $this->assertEqualsWithDelta(5 / 3, $out['cells'][6]['hours'], 1e-9); // jam tetap dihitung
    }

    public function test_totals_are_rounded_half_up_per_assistant_and_the_grand_total_sums_the_rounded_values()
    {
        $three = $this->lab('Teknologi Blockchain', 'Kelas A', 3, 1, [$this->rama, $this->dina]);
        $this->report($three, '2026-04-06', [$this->rama, $this->dina]); // 2,5 jam masing-masing

        $recap = $this->build();
        $rama = collect($recap['assistants'])->firstWhere('userId', $this->rama->id);

        $this->assertEqualsWithDelta(2.5, $rama['hoursRaw'], 1e-9);
        $this->assertSame(3, $rama['hours']); // ROUND(2,5;0) = 3
        $this->assertSame(6, $recap['totals']['hours']); // ROUND(SUM(3,3)); bukan ROUND(5,0)
        $this->assertEqualsWithDelta(5.0, $recap['totals']['hoursRaw'], 1e-9);
        $this->assertSame(0, NgawasRecap::roundHours(0.4999));
        $this->assertSame(1, NgawasRecap::roundHours(0.49999999999)); // selisih float di bawah 1e-9 dianggap 0,5
        $this->assertSame(1, NgawasRecap::roundHours(0.5));
        $this->assertSame(56, NgawasRecap::roundHours(55.5));
    }

    public function test_day_columns_mark_sundays_holidays_and_exam_periods()
    {
        $this->semester->calendarEvents()->createMany([
            ['date_from' => '2026-04-03', 'date_to' => '2026-04-03', 'type' => CalendarEventType::NationalHoliday, 'label' => 'Wafat Isa Almasih'],
            ['date_from' => '2026-04-12', 'date_to' => '2026-04-12', 'type' => CalendarEventType::NationalHoliday, 'label' => 'Libur di hari Minggu'],
            ['date_from' => '2026-04-20', 'date_to' => '2026-04-26', 'type' => CalendarEventType::Uts, 'label' => 'UTS'],
        ]);

        $columns = collect($this->build('2026-04')['columns'])->keyBy('day');

        $this->assertCount(30, $columns);
        $this->assertSame('holiday', $columns[3]['kind']);
        $this->assertSame('Wafat Isa Almasih', $columns[3]['label']);
        $this->assertSame('sunday', $columns[5]['kind']);
        $this->assertSame('holiday', $columns[12]['kind']); // libur nasional mengalahkan Minggu
        $this->assertSame('exam', $columns[21]['kind']);
        $this->assertSame('sunday', $columns[26]['kind']); // Minggu mengalahkan UTS/UAS
        $this->assertNull($columns[7]['kind']);
        $this->assertCount(28, $this->build('2026-02')['columns']);
        $this->assertCount(31, $this->build('2026-05')['columns']);
    }

    public function test_labels_names_and_nim_follow_the_campus_format()
    {
        $this->lab('Simulasi dan Pemodelan', 'Kelas A', 2, 1, [$this->rama]);

        $assistant = $this->build()['assistants'][1]; // urut nama: Dina, Rama

        $this->assertSame('RAMA DINANTIAR', $assistant['name']);
        $this->assertSame('2023 – 31 – 044', $assistant['nim']);
        $this->assertSame('SIMULASI DAN PEMODELAN (A)', $assistant['rows'][0]['label']);
        $this->assertSame('2023 – 32 – 010', $this->build()['assistants'][0]['nim']);
    }

    public function test_assistant_order_defaults_to_name_and_follows_the_recap_order_setting()
    {
        $this->assertSame([$this->dina->id, $this->rama->id], array_column($this->build()['assistants'], 'userId'));

        NgawasRecapOrder::create(['semester_id' => $this->semester->id, 'user_id' => $this->rama->id, 'position' => 1]);

        $this->assertSame([$this->rama->id, $this->dina->id], array_column($this->build()['assistants'], 'userId'));
    }

    public function test_class_rows_are_ordered_by_day_then_time_unless_a_custom_order_is_saved()
    {
        $thursday = $this->lab('Pemrograman Web', 'Kelas B', 3, 4, [$this->rama]);
        $mondayLate = $this->lab('Data Warehouse', 'Kelas A', 2, 1, [$this->rama], '13:00');
        $mondayEarly = $this->lab('Big Data', 'Kelas A', 2, 1, [$this->rama], '07:20');

        $keys = fn () => array_column(collect($this->build()['assistants'])->firstWhere('userId', $this->rama->id)['rows'], 'key');

        $this->assertSame(['c'.$mondayEarly->id, 'c'.$mondayLate->id, 'c'.$thursday->id], $keys());

        NgawasRecapOrder::create(['semester_id' => $this->semester->id, 'user_id' => $this->rama->id, 'position' => 1, 'class_order_json' => ['c'.$thursday->id]]);

        $this->assertSame('c'.$thursday->id, $keys()[0]);
    }

    public function test_only_user_filter_returns_a_single_assistant()
    {
        $class = $this->lab('Data Warehouse', 'Kelas A', 2, 1, [$this->rama, $this->dina]);
        $this->report($class, '2026-04-06', [$this->rama, $this->dina]);

        $recap = $this->build('2026-04', $this->dina->id);

        $this->assertSame([$this->dina->id], array_column($recap['assistants'], 'userId'));
        $this->assertSame(1, $recap['totals']['assistants']);
    }

    public function test_reports_without_a_class_are_listed_as_unplaced_not_silently_dropped()
    {
        $report = SupervisionReport::factory()->create([
            'semester_id' => $this->semester->id, 'user_id' => $this->rama->id, 'date' => '2026-04-08', 'status' => 'verified', 'activity' => 'Jaga Lab',
        ]);
        $report->assistants()->sync([$this->rama->id => ['position' => 1]]);

        $this->assertSame('Jaga Lab', $this->build()['unplaced'][0]['activity']);
    }

    // --- Pemeriksaan -------------------------------------------------------

    /** @return list<string> */
    private function codes(array $checks): array
    {
        return array_column($checks['findings'], 'code');
    }

    private function checks(string $month = '2026-04'): array
    {
        return NgawasRecapChecks::run($this->semester, $month, $this->build($month));
    }

    public function test_unverified_reports_and_missing_sk_block_finalisation()
    {
        $class = $this->lab('Data Warehouse', 'Kelas A', 2, 1, [$this->rama]);
        $this->report($class, '2026-04-06', [$this->rama], ['status' => 'submitted']);
        $this->semester->forceFill(['sk_number' => null])->save();

        $checks = $this->checks();

        $this->assertTrue($checks['blocking']);
        $this->assertContains('unverified', $this->codes($checks));
        $this->assertContains('no_sk', $this->codes($checks));
    }

    public function test_duplicates_block_and_a_report_without_class_blocks_for_unknown_credits()
    {
        $class = $this->lab('Data Warehouse', 'Kelas A', 2, 1, [$this->rama]);
        $this->report($class, '2026-04-06', [$this->rama]);
        $this->report($class, '2026-04-06', [$this->rama]);
        SupervisionReport::factory()->create(['semester_id' => $this->semester->id, 'user_id' => $this->rama->id, 'date' => '2026-04-08', 'status' => 'verified'])
            ->assistants()->sync([$this->rama->id => ['position' => 1]]);

        $codes = $this->codes($this->checks());

        $this->assertContains('duplicates', $codes);
        $this->assertContains('no_credits', $codes);
    }

    public function test_warnings_do_not_block_and_cover_signature_empty_rows_and_unconfirmed_outside_classes()
    {
        $this->lab('Data Warehouse', 'Kelas A', 2, 1, [$this->rama]); // tidak ada laporan sama sekali
        $this->outside([$this->rama]);

        $checks = $this->checks('2026-07'); // di luar semester: tidak ada kelas terjadwal yang diperiksa

        $this->assertFalse($checks['blocking']);
        $this->assertEqualsCanonicalizing(['no_signature', 'empty_rows', 'unconfirmed_outside'], $this->codes($checks));
    }

    public function test_scheduled_class_dates_without_any_report_are_flagged_except_holidays_future_and_replaced_dates()
    {
        // Rabu 1 Apr … 29 Apr (5 tanggal terjadwal); 8 = ada laporan, 15 = libur, 22 = tanggal asli pengganti.
        $class = $this->lab('Data Warehouse', 'Kelas A', 2, 3, [$this->rama]);
        $this->report($class, '2026-04-08', [$this->rama]);
        $this->report($class, '2026-04-23', [$this->rama], ['execution_status' => 'replacement', 'original_date' => '2026-04-22']);
        $this->semester->calendarEvents()->create(['date_from' => '2026-04-15', 'date_to' => '2026-04-15', 'type' => CalendarEventType::NationalHoliday, 'label' => 'Libur']);

        $missing = collect($this->checks()['findings'])->firstWhere('code', 'missing_reports');

        $this->assertSame(['1 Apr 2026 · Data Warehouse (Kelas A)', '29 Apr 2026 · Data Warehouse (Kelas A)'], $missing['items']);

        $this->travelTo(CarbonImmutable::parse('2026-04-10 10:00'));
        $this->assertSame(['1 Apr 2026 · Data Warehouse (Kelas A)'], collect($this->checks()['findings'])->firstWhere('code', 'missing_reports')['items']);
    }

    public function test_a_clean_month_has_no_blocking_findings()
    {
        $class = $this->lab('Data Warehouse', 'Kelas A', 2, 1, [$this->rama]);
        foreach (['06', '13', '20', '27'] as $day) {
            $this->report($class, "2026-04-{$day}", [$this->rama]);
        }
        $this->rama->forceFill(['signature_file_id' => null])->save();

        $checks = $this->checks();

        $this->assertFalse($checks['blocking']);
        $this->assertNotContains('missing_reports', $this->codes($checks));
    }

    // --- Halaman -----------------------------------------------------------

    public function test_a_regular_assistant_sees_only_their_own_row_and_no_checks()
    {
        $class = $this->lab('Data Warehouse', 'Kelas A', 2, 1, [$this->rama, $this->dina]);
        $this->report($class, '2026-04-06', [$this->rama, $this->dina]);

        $this->actingAs($this->rama)->get(route('asisten.ngawas-recap.index', ['month' => '2026-04']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('asisten/ngawas-recap')
                ->where('canManage', false)
                ->where('checks', null)
                ->has('recap.assistants', 1)
                ->where('recap.assistants.0.userId', $this->rama->id)
                ->where('status.value', 'draft'));
    }

    public function test_the_secretary_treasurer_and_admin_see_everyone_with_checks()
    {
        $this->lab('Data Warehouse', 'Kelas A', 2, 1, [$this->rama]);
        $manager = User::factory()->asisten(Position::SekretarisBendahara)->create();

        foreach ([$manager, User::factory()->admin()->create()] as $user) {
            $this->actingAs($user)->get(route('asisten.ngawas-recap.index', ['month' => '2026-04']))
                ->assertOk()
                ->assertInertia(fn (Assert $page) => $page
                    ->where('canManage', true)
                    ->has('recap.assistants', 3)
                    ->has('checks.findings')
                    ->where('signatures.total', 3));
        }
    }

    public function test_praktikan_cannot_open_the_page_and_a_bad_month_falls_back_to_the_current_one()
    {
        $this->actingAs(User::factory()->create())->get(route('asisten.ngawas-recap.index'))->assertForbidden();

        $this->actingAs($this->rama)->get(route('asisten.ngawas-recap.index', ['month' => 'bukan-bulan']))
            ->assertOk()->assertInertia(fn (Assert $page) => $page->where('filters.month', '2026-06'));
    }

    public function test_a_month_outside_any_semester_renders_an_empty_state()
    {
        Semester::query()->update(['status' => 'archived']);

        $this->actingAs($this->rama)->get(route('asisten.ngawas-recap.index', ['month' => '2030-01']))
            ->assertOk()->assertInertia(fn (Assert $page) => $page->where('recap', null));
    }
}
