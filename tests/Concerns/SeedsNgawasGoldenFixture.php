<?php

namespace Tests\Concerns;

use App\Enums\CalendarEventType;
use App\Models\Course;
use App\Models\LabClass;
use App\Models\Semester;
use App\Models\SupervisionReport;
use App\Models\User;

/**
 * Menyemai database dari fixture anonim `tests/Fixtures/ngawas-april-2026.json`
 * (meniru rekap April 2026; nama dan NIM sintetis). Dipakai golden test mesin
 * rekap dan ekspor Excel.
 */
trait SeedsNgawasGoldenFixture
{
    /** @var array<string, mixed> */
    private array $fx;

    private Semester $semester;

    /** @var array<int, User> */
    private array $users = [];

    private function seedFromFixture(): void
    {
        $this->fx = json_decode((string) file_get_contents(base_path('tests/Fixtures/ngawas-april-2026.json')), true, flags: JSON_THROW_ON_ERROR);

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
}
