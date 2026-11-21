<?php

namespace App\Support\Reports;

use App\Models\SupervisionReport;
use Carbon\CarbonImmutable;

/**
 * Rekap jam mengawas bulanan per asisten, dirinci per mata kuliah (jam + SKS
 * mata kuliah). Hanya laporan Diverifikasi; setiap anggota tim jaga mendapat
 * jam laporan tersebut. Jam memakai rumus SKS × 50 ÷ 60 yang sama dengan Rekap
 * Jam Ngawas format kampus (FR-RJN-16), bukan jam mulai–selesai; total per asisten
 * dibulatkan seperti template. Kelas ditiadakan dilewati. Tidak ada perhitungan
 * uang: tarif per jam bukan urusan sistem ini.
 */
class HoursRecap
{
    /**
     * Setiap baris: userId, name, nim, shifts, hours (bulat), hoursRaw (2 desimal), courses[{name, code, credits, shifts, hours}].
     *
     * @return list<array<string, mixed>>
     */
    public static function forMonth(string $month, ?int $onlyUserId = null): array
    {
        $start = CarbonImmutable::createFromFormat('Y-m', $month)->startOfMonth();

        $reports = SupervisionReport::where('status', 'verified')
            ->whereDateBetween('date', [$start->toDateString(), $start->endOfMonth()->toDateString()])
            ->with(['assistants:id,name,nim', 'labClass.course', 'outsideClass'])
            ->get();

        /** @var array<int, array<string, mixed>> $perUser */
        $perUser = [];

        foreach ($reports as $report) {
            if (! $report->execution_status->countsHours()) {
                continue;
            }

            $labClass = $report->labClass;
            $course = $labClass === null ? null : $labClass->course;
            $outside = $report->outsideClass;
            $courseKey = $outside !== null ? 'o'.$outside->id : ($course === null ? 'other' : 'c'.$course->id);
            [$courseName, $courseCode] = match (true) {
                $outside !== null => [$outside->course_name, null],
                $course !== null => [$course->name, $course->code],
                default => ['Kegiatan lain', null],
            };
            $hours = $report->recapHours();

            foreach ($report->assistants as $assistant) {
                if ($onlyUserId !== null && $assistant->id !== $onlyUserId) {
                    continue;
                }

                $row = &$perUser[$assistant->id];
                $row['userId'] = $assistant->id;
                $row['name'] = $assistant->name;
                $row['nim'] = $assistant->nim;
                $row['shifts'] = ($row['shifts'] ?? 0) + 1;
                $row['hours'] = ($row['hours'] ?? 0) + $hours;

                $c = &$row['courses'][$courseKey];
                $c['name'] = $courseName;
                $c['code'] = $courseCode;
                $c['credits'] = $report->credits();
                $c['shifts'] = ($c['shifts'] ?? 0) + 1;
                $c['hours'] = ($c['hours'] ?? 0) + $hours;
                unset($row, $c);
            }
        }

        $rows = [];
        foreach ($perUser as $row) {
            $courses = array_map(fn (array $c) => [...$c, 'hours' => round($c['hours'], 2)], array_values($row['courses']));
            usort($courses, fn (array $a, array $b) => strcmp($a['name'], $b['name']));
            $rows[] = [
                'userId' => $row['userId'],
                'name' => $row['name'],
                'nim' => $row['nim'],
                'shifts' => $row['shifts'],
                'hours' => NgawasRecap::roundHours($row['hours']),
                'hoursRaw' => round($row['hours'], 2),
                'courses' => $courses,
            ];
        }

        usort($rows, fn (array $a, array $b) => strcmp((string) ($a['nim'] ?? $a['name']), (string) ($b['nim'] ?? $b['name'])));

        return $rows;
    }
}
