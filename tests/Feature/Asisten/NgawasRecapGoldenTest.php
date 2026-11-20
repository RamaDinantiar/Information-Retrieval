<?php

namespace Tests\Feature\Asisten;

use App\Enums\CalendarEventType;
use App\Models\Course;
use App\Models\LabClass;
use App\Models\Semester;
use App\Models\SupervisionReport;
use App\Models\User;
use App\Support\Reports\NgawasRecap;
use App\Support\Reports\NgawasRecapChecks;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Golden-file test (PRD 11.2): data yang meniru rekap April 2026 harus menghasilkan
 * total yang benar. Fixture `ngawas-april-2026.json` dibuat dari file kampus tetapi
 * sudah dianonimkan (nama/NIM sintetis); angka harapannya dihitung dari SKS data
 * master, bukan dari rumus di file manual.
 *
 * Catatan: total benar = 495,00 jam (bukan 494,17 seperti di PRD, yang masih memuat
 * sel Hilda `F33` yang keliru memakai 2 SKS untuk kelas 3 SKS).
 */
class NgawasRecapGoldenTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<string, mixed> */
    private array $fx;

    private Semester $semester;

    /** @var array<int, User> */
    private array $users = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-06-01 10:00'));
        $this->fx = json_decode((string) file_get_contents(base_path('tests/Fixtures/ngawas-april-2026.json')), true, flags: JSON_THROW_ON_ERROR);
        $this->seedFromFixture();
    }

    private function seedFromFixture(): void
    {
        $s = $this->fx['semester'];
        $this->semester = Semester::factory()->active()->create([
            'name' => $s['name'], 'start_date' => $s['start'], 'end_date' => $s['end'], 'sk_number' => $s['sk'], 'term_label' => $s['term'],
        ]);

        foreach ($this->fx['holidays'] as $holiday) {
            $this->semester->calendarEvents()->create(['date_from' => $holiday['date'], 'date_to' => $holiday['date'], 'type' => CalendarEventType::NationalHoliday, 'label' => $holiday['label']]);
        }

        foreach ($this->fx['assistants'] as $i => $a) {
            $this->users[$i] = User::factory()->asisten()->create(['name' => $a['name'], 'nim' => $a['nim']]);
        }

        $classes = [];
        foreach ($this->fx['classes'] as $key => $c) {
            $course = Course::factory()->for($this->semester)->create(['name' => $c['course'], 'credits' => $c['credits']]);
            $classes[$key] = LabClass::factory()->create([
                'semester_id' => $this->semester->id, 'course_id' => $course->id, 'name' => $c['name'],
                'day_of_week' => $c['day'], 'start_time' => '07:20', 'end_time' => '09:50',
            ]);
            $classes[$key]->assistants()->attach(collect($c['assistants'])->map(fn (int $i) => $this->users[$i]->id));
        }

        // Satu laporan per kelas × tanggal × status; anggotanya semua asisten yang bersel di situ.
        $groups = [];
        foreach ($this->fx['cells'] as $cell) {
            $groups["{$cell['class']}|{$cell['day']}|{$cell['status']}"][] = $cell['assistant'];
        }

        foreach ($groups as $group => $team) {
            [$course, $suffix, $day, $status] = array_pad(explode('|', $group), 4, null);
            $classKey = "{$course}|{$suffix}";
            $replacement = $status === 'replacement' ? $this->fx['replacements'][$classKey] : null;

            $report = SupervisionReport::factory()->create([
                'semester_id' => $this->semester->id, 'user_id' => $this->users[$team[0]]->id, 'class_id' => $classes[$classKey]->id,
                'date' => sprintf('2026-04-%02d', $day), 'status' => 'verified', 'execution_status' => $status,
                'start_time' => $replacement['start'] ?? '07:20', 'end_time' => $replacement['end'] ?? '09:50',
                'original_date' => $replacement['original'] ?? null,
                'replacement_note' => ($replacement['note'] ?? '') ?: null,
            ]);
            $report->assistants()->sync(collect($team)->values()->mapWithKeys(fn (int $i, int $pos) => [$this->users[$i]->id => ['position' => $pos + 1]])->all());
        }
    }

    /** @return array<string, mixed> */
    private function recap(): array
    {
        return NgawasRecap::build($this->semester, $this->fx['month']);
    }

    /** @return array<int, array<string, mixed>> asisten fixture-index → hasil mesin */
    private function byIndex(array $recap): array
    {
        $byName = collect($recap['assistants'])->keyBy('name');

        return collect($this->fx['assistants'])->mapWithKeys(fn (array $a, int $i) => [$i => $byName[mb_strtoupper($a['name'])]])->all();
    }

    public function test_totals_per_assistant_and_overall_match_the_correct_hand_calculation()
    {
        $recap = $this->recap();
        $assistants = $this->byIndex($recap);
        $expected = $this->fx['expected'];

        $this->assertCount(9, $recap['assistants']);

        foreach ($expected['hours'] as $i => $hours) {
            $this->assertSame($hours, $assistants[$i]['hours'], "total jam asisten {$i}");
            $this->assertEqualsWithDelta($expected['hoursRaw'][$i], $assistants[$i]['hoursRaw'], 1e-3, "jam sebenarnya asisten {$i}");
            $this->assertSame($expected['days'][$i], $assistants[$i]['totalDays'], "total hari asisten {$i}");
        }

        $this->assertSame(495, $expected['totalHours']);
        $this->assertSame(495, $recap['totals']['hours']);
        $this->assertEqualsWithDelta(495.0, $recap['totals']['hoursRaw'], 1e-3);
    }

    public function test_the_total_range_covers_day_one_for_the_assistants_the_manual_file_undercounted()
    {
        // Di file manual, total asisten no. 4–9 dimulai dari kolom tanggal 2 sehingga jam tanggal 1 hilang.
        $assistants = $this->byIndex($this->recap());

        foreach ([4, 5, 6, 7] as $i) {
            $day1 = collect($assistants[$i]['rows'])->sum(fn (array $row) => ($row['cells'][1]['hours'] ?? 0.0));
            $this->assertGreaterThan(0.0, $day1, "asisten {$i} punya kehadiran tanggal 1");
            $this->assertEqualsWithDelta($this->fx['expected']['hoursRaw'][$i], $assistants[$i]['hoursRaw'], 1e-3);
        }
    }

    public function test_every_cell_lands_on_the_right_row_day_and_kind()
    {
        $assistants = $this->byIndex($this->recap());
        $labels = collect($this->fx['classes'])->mapWithKeys(fn (array $c, string $key) => [$key => mb_strtoupper($c['course']).' ('.substr($c['name'], 6).')']);

        $cancelled = $replacement = 0;

        foreach ($this->fx['expected']['rows'] as $i => $rows) {
            foreach ($rows as $classKey => $expected) {
                $row = collect($assistants[$i]['rows'])->firstWhere('label', $labels[$classKey]);
                $this->assertNotNull($row, "baris {$labels[$classKey]} asisten {$i}");
                $cells = collect($row['cells']);

                $this->assertEqualsCanonicalizing($expected['days'] ?? [], $cells->where('counts', true)->pluck('day')->all(), "hari {$labels[$classKey]} asisten {$i}");
                $this->assertEqualsCanonicalizing($expected['cancelled'] ?? [], $cells->where('kind', 'cancelled')->pluck('day')->all());
                $this->assertEqualsCanonicalizing($expected['replacement'] ?? [], $cells->where('kind', 'replacement')->pluck('day')->all());

                $cancelled += count($expected['cancelled'] ?? []);
                $replacement += count($expected['replacement'] ?? []);
            }
        }

        $this->assertSame(11, $cancelled);
        $this->assertSame(19, $replacement);
        $this->assertSame($this->fx['expected']['cancelledCells'], $cancelled);
    }

    public function test_a_class_row_without_any_attendance_still_appears_empty()
    {
        $row = collect($this->byIndex($this->recap())[5]['rows'])->firstWhere('label', 'DATA WAREHOUSE (KR)');

        $this->assertNotNull($row);
        $this->assertSame([], $row['cells']);
        $this->assertSame(0, $row['totalDays']);
    }

    public function test_calendar_columns_mark_the_four_sundays_and_the_holiday()
    {
        $columns = collect($this->recap()['columns']);

        $this->assertCount(30, $columns);
        $this->assertSame($this->fx['expected']['sundays'], $columns->where('kind', 'sunday')->pluck('day')->all());
        $this->assertSame($this->fx['expected']['holidays'], $columns->where('kind', 'holiday')->pluck('day')->all());
    }

    public function test_the_replacement_table_lists_the_five_replaced_classes_with_campus_wording()
    {
        $table = collect($this->recap()['replacements']);

        $this->assertCount(5, $table);
        $this->assertSame(0, count($this->recap()['additional']));
        $this->assertEqualsCanonicalizing(
            ['Data Warehouse A', 'Data Warehouse B', 'Data Warehouse C', 'Data Warehouse D', 'Data Warehouse E'],
            $table->pluck('class')->all(),
        );

        $b = $table->firstWhere('class', 'Data Warehouse B');
        $this->assertSame('2026-04-30', $b['date']);
        $this->assertSame('10.10 - 11.50', $b['time']);
        $this->assertSame('Pengganti dari Kelas Senin, 27 April 2026', $b['note']);
        $this->assertStringStartsWith('Pengganti dari Kelas Kamis, 30 April 2026', $table->firstWhere('class', 'Data Warehouse C')['note']);
    }

    public function test_no_duplicates_no_unplaced_reports_and_nothing_blocks_finalisation()
    {
        $recap = $this->recap();
        $checks = NgawasRecapChecks::run($this->semester, $this->fx['month'], $recap);

        $this->assertSame([], $recap['duplicates']);
        $this->assertSame([], $recap['unplaced']);
        $this->assertFalse($checks['blocking']);
        $this->assertContains('empty_rows', array_column($checks['findings'], 'code')); // DW (KR)
    }
}
